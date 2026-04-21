<?php

namespace App\Http\Controllers;

use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingLink;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class BookingController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC  (no auth — slug-based)
    // ─────────────────────────────────────────────────────────────────────────

    /** POST /api/booking-link  (auth) */
    public function createLink()
    {
        $user = auth()->user();
        $link = BookingLink::firstOrCreate(
            ['user_id' => $user->id],
            [
                'slug'      => Str::slug($user->name) . '-' . rand(100, 999),
                'duration'  => 30,
                'is_active' => 1,
            ]
        );
        $link->booking_url = url("/book/{$link->slug}");
        return response()->json($link);
    }

    /** GET /api/book/{slug}?date=YYYY-MM-DD */
    public function getAvailability(Request $request, $slug)
    {
        $date = $request->query('date') ?? now()->toDateString();
        $link = BookingLink::where('slug', $slug)->where('is_active', 1)->firstOrFail();
        $day  = Carbon::parse($date)->format('l');

        $availability = Availability::where('user_id', $link->user_id)
            ->where('day_of_week', $day)->where('is_active', 1)->get();

        if ($availability->isEmpty()) {
            return response()->json([
                'date'     => $date,
                'slots'    => [],
                'duration' => $link->duration,
                'message'  => 'No availability configured for ' . $day,
            ]);
        }

        $slots = $this->generateSlots($availability, $link->duration);
        $slots = $this->removeBookedSlots($slots, $link->user_id, $date);

        return response()->json(['date' => $date, 'slots' => $slots, 'duration' => $link->duration]);
    }

    public function book(Request $request, $slug)
    {
        $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email',
            'start_time'   => 'required|date',
            'meeting_type' => 'nullable|in:jitsi,gmeet',
        ]);

        $link  = BookingLink::where('slug', $slug)->where('is_active', 1)->firstOrFail();
        $start = Carbon::parse($request->start_time);
        $end   = $start->copy()->addMinutes($link->duration);

        if ($this->slotTaken($link->user_id, $start, $end)) {
            return response()->json(['error' => 'This time slot is already booked'], 400);
        }

        $meetingType = $request->meeting_type ?? 'jitsi';

        $contactId = $this->tenantDb()->table('contacts')
            ->where('email', $request->email)
            ->value('id');

        [$roomName, $meetingUrl] = $this->buildMeeting(
            $meetingType,
            $link->user_id,
            $start,
            $end,
            $request->name,
            $request->email
        );

        $bookingId = $this->tenantDb()->table('bookings')->insertGetId([
            'user_id'      => $link->user_id,
            'contact_id'   => $contactId,
            'name'         => $request->name,
            'email'        => $request->email,
            'start_time'   => $start,
            'end_time'     => $end,
            'timezone'     => $request->timezone ?? 'Asia/Kolkata',
            'meeting_link' => $roomName,   // for gmeet: this IS the Google event ID
            'meeting_url'  => $meetingUrl,
            'meeting_type' => $meetingType,
            'status'       => 'scheduled',
            'created_at'   => now(),
        ]);

        $bookingObj = (object) [
            'id'           => $bookingId,
            'user_id'      => $link->user_id,
            'name'         => $request->name,
            'email'        => $request->email,
            'start_time'   => $start,
            'end_time'     => $end,
            'meeting_link' => $roomName,
            'meeting_url'  => $meetingUrl,
            'meeting_type' => $meetingType,
            'status'       => 'scheduled',
        ];

        $this->insertCalendarEvent($link->user_id, $bookingObj, $request->name);

        $hostUser = DB::connection('mysql')->table('users')->where('id', $link->user_id)->first();

        $this->sendConfirmationEmail(
            toEmail: $request->email,
            toName: $request->name,
            hostName: $hostUser->name ?? 'Your Host',
            startTime: $start,
            endTime: $end,
            timezone: $request->timezone ?? 'Asia/Kolkata',
            meetingUrl: $meetingUrl,
            meetingType: $meetingType,
        );

        return response()->json(['message' => 'Booking confirmed', 'data' => $bookingObj]);
    }

    public function reschedule(Request $request, $slug)
    {
        $request->validate(['booking_id' => 'required|integer', 'new_time' => 'required|date']);

        $booking = $this->tenantDb()->table('bookings')->where('id', $request->booking_id)->first();

        $start = Carbon::parse($request->new_time);
        $end   = $start->copy()->addMinutes(30);

        $this->tenantDb()->table('bookings')
            ->where('id', $request->booking_id)
            ->update(['start_time' => $start, 'end_time' => $end]);

        $this->updateCalendarEventTimes($request->booking_id, $start, $end);

        if ($booking && $booking->meeting_type === 'gmeet' && !empty($booking->meeting_link)) {
            $this->patchGoogleCalendarEvent($booking->user_id, $booking->meeting_link, $start, $end);
        }

        if ($booking) {
            $hostUser = DB::connection('mysql')->table('users')->where('id', $booking->user_id)->first();
            $this->sendRescheduleEmail(
                toEmail: $booking->email,
                toName: $booking->name,
                hostName: $hostUser->name ?? 'Your Host',
                newStart: $start,
                newEnd: $end,
                timezone: $booking->timezone ?? 'Asia/Kolkata',
                meetingUrl: $booking->meeting_url,
                meetingType: $booking->meeting_type ?? 'jitsi',
            );
        }

        return response()->json(['message' => 'Booking rescheduled successfully']);
    }

    public function cancel(Request $request, $slug)
    {
        $request->validate(['booking_id' => 'required|integer']);

        $booking = $this->tenantDb()->table('bookings')->where('id', $request->booking_id)->first();

        $this->tenantDb()->table('bookings')
            ->where('id', $request->booking_id)
            ->update(['status' => 'cancelled']);

        $this->cancelCalendarEvent($request->booking_id);

        if ($booking && $booking->meeting_type === 'gmeet' && !empty($booking->meeting_link)) {
            $this->deleteGoogleCalendarEvent($booking->user_id, $booking->meeting_link);
        }

        if ($booking) {
            $hostUser = DB::connection('mysql')->table('users')->where('id', $booking->user_id)->first();
            $this->sendCancellationEmail(
                toEmail: $booking->email,
                toName: $booking->name,
                hostName: $hostUser->name ?? 'Your Host',
                start: Carbon::parse($booking->start_time),
                timezone: $booking->timezone ?? 'Asia/Kolkata',
            );
        }

        return response()->json(['message' => 'Booking cancelled successfully']);
    }

    public function getSlotsInternal(Request $request)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $date = $request->query('date');
        if (!$date) return response()->json(['error' => 'date query parameter is required'], 422);

        $day = Carbon::parse($date)->format('l');

        $availability = Availability::where('user_id', $userId)
            ->where('day_of_week', $day)
            ->where('is_active', 1)
            ->get();

        if ($availability->isEmpty()) {
            return response()->json([
                'slots'   => [],
                'message' => 'No availability set for ' . $day . '. Go to Settings → Availability to set your working hours.',
            ]);
        }

        $slots = $this->generateSlots($availability, 30);
        $slots = $this->removeBookedSlots($slots, $userId, $date);

        return response()->json(['slots' => $slots]);
    }

    public function bookInternal(Request $request)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $request->validate([
            'contact_id'   => 'nullable|integer',
            'name'         => 'required|string|max:255',
            'email'        => 'required|email',
            'start_time'   => 'required|date',
            'meeting_type' => 'nullable|in:jitsi,gmeet',
        ]);

        $start = Carbon::parse($request->start_time);
        $end   = $start->copy()->addMinutes(30);

        if ($this->slotTaken($userId, $start, $end)) {
            return response()->json(['error' => 'This slot is already booked. Please pick another time.'], 400);
        }

        $meetingType = $request->meeting_type ?? 'jitsi';
        $hostUser    = auth()->user();

        [$roomName, $meetingUrl] = $this->buildMeeting(
            $meetingType,
            $userId,
            $start,
            $end,
            $request->name,
            $request->email
        );

        $bookingId = $this->tenantDb()->table('bookings')->insertGetId([
            'user_id'      => $userId,
            'contact_id'   => $request->contact_id,
            'name'         => $request->name,
            'email'        => $request->email,
            'start_time'   => $start,
            'end_time'     => $end,
            'timezone'     => $request->timezone ?? 'Asia/Kolkata',
            'meeting_link' => $roomName,   // for gmeet: this IS the Google event ID
            'meeting_url'  => $meetingUrl,
            'meeting_type' => $meetingType,
            'status'       => 'scheduled',
            'created_at'   => now(),
        ]);

        $bookingObj = (object) [
            'id'           => $bookingId,
            'user_id'      => $userId,
            'contact_id'   => $request->contact_id,
            'name'         => $request->name,
            'email'        => $request->email,
            'start_time'   => $start,
            'end_time'     => $end,
            'meeting_link' => $roomName,
            'meeting_url'  => $meetingUrl,
            'meeting_type' => $meetingType,
            'status'       => 'scheduled',
        ];

        $this->insertCalendarEvent($userId, $bookingObj, $request->name);

        $this->sendConfirmationEmail(
            toEmail: $request->email,
            toName: $request->name,
            hostName: $hostUser->name ?? 'Your Host',
            startTime: $start,
            endTime: $end,
            timezone: $request->timezone ?? 'Asia/Kolkata',
            meetingUrl: $meetingUrl,
            meetingType: $meetingType,
        );

        return response()->json(['message' => 'Meeting scheduled successfully', 'data' => $bookingObj]);
    }

    public function rescheduleInternal(Request $request)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $request->validate([
            'booking_id' => 'required|integer',
            'new_time'   => 'required|date',
        ]);

        $booking = $this->tenantDb()->table('bookings')
            ->where('id', $request->booking_id)
            ->where('user_id', $userId)
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        $start = Carbon::parse($request->new_time);
        $end   = $start->copy()->addMinutes(30);

        $this->tenantDb()->table('bookings')
            ->where('id', $request->booking_id)
            ->where('user_id', $userId)
            ->update(['start_time' => $start, 'end_time' => $end]);

        $this->updateCalendarEventTimes($request->booking_id, $start, $end);

        if ($booking->meeting_type === 'gmeet' && !empty($booking->meeting_link)) {
            $this->patchGoogleCalendarEvent($userId, $booking->meeting_link, $start, $end);
        }

        $hostUser = auth()->user();
        $this->sendRescheduleEmail(
            toEmail: $booking->email,
            toName: $booking->name,
            hostName: $hostUser->name ?? 'Your Host',
            newStart: $start,
            newEnd: $end,
            timezone: $booking->timezone ?? 'Asia/Kolkata',
            meetingUrl: $booking->meeting_url,
            meetingType: $booking->meeting_type ?? 'jitsi',
        );

        return response()->json(['message' => 'Rescheduled successfully']);
    }

    public function cancelInternal(Request $request)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $request->validate(['booking_id' => 'required|integer']);

        $booking = $this->tenantDb()->table('bookings')
            ->where('id', $request->booking_id)
            ->where('user_id', $userId)
            ->first();

        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        $this->tenantDb()->table('bookings')
            ->where('id', $request->booking_id)
            ->where('user_id', $userId)
            ->update(['status' => 'cancelled']);

        $this->cancelCalendarEvent($request->booking_id);

        if ($booking->meeting_type === 'gmeet' && !empty($booking->meeting_link)) {
            $this->deleteGoogleCalendarEvent($userId, $booking->meeting_link);
        }

        $hostUser = auth()->user();
        $this->sendCancellationEmail(
            toEmail: $booking->email,
            toName: $booking->name,
            hostName: $hostUser->name ?? 'Your Host',
            start: Carbon::parse($booking->start_time),
            timezone: $booking->timezone ?? 'Asia/Kolkata',
        );

        return response()->json(['message' => 'Cancelled successfully']);
    }

    public function setAvailability(Request $request)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $request->validate([
            'availability'               => 'required|array|min:1',
            'availability.*.day_of_week' => 'required|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'availability.*.start_time'  => 'required|date_format:H:i',
            'availability.*.end_time'    => 'required|date_format:H:i',
        ]);

        Availability::where('user_id', $userId)->delete();

        $rows = collect($request->availability)->map(fn($a) => [
            'user_id'     => $userId,
            'day_of_week' => $a['day_of_week'],
            'start_time'  => $a['start_time'] . ':00',
            'end_time'    => $a['end_time']   . ':00',
            'timezone'    => $request->timezone ?? 'Asia/Kolkata',
            'is_active'   => 1,
        ])->toArray();

        Availability::insert($rows);

        return response()->json(['message' => 'Availability saved successfully.', 'days' => count($rows)]);
    }

    public function getAvailabilitySettings()
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $availability = Availability::where('user_id', $userId)
            ->where('is_active', 1)
            ->get(['day_of_week', 'start_time', 'end_time']);

        $link = BookingLink::where('user_id', $userId)->where('is_active', 1)->first();
        $bookingUrl = $link ? url("/book/{$link->slug}") : null;

        return response()->json(['availability' => $availability, 'booking_link' => $bookingUrl]);
    }

    public function myBookings()
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $bookings = $this->tenantDb()->table('bookings')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        $bookings = $bookings->map(function ($b) {
            $b = (array) $b;
            if (empty($b['meeting_url']) && !empty($b['meeting_link'])) {
                $type = $b['meeting_type'] ?? 'jitsi';
                if ($type === 'jitsi') {
                    $b['meeting_url'] = "https://meet.jit.si/{$b['meeting_link']}";
                }
            }
            return $b;
        });

        return response()->json($bookings);
    }

    public function getMeetingToken(Request $request, string $roomName)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $response = Http::withToken(env('DAILY_API_KEY'))
            ->post('https://api.daily.co/v1/meeting-tokens', [
                'properties' => [
                    'room_name'        => $roomName,
                    'user_name'        => auth()->user()->name ?? 'Host',
                    'is_owner'         => true,
                    'exp'              => now()->addHours(2)->timestamp,
                    'enable_recording' => 'local',
                ],
            ]);

        if (!$response->successful()) {
            return response()->json(['error' => 'Could not generate meeting token'], 500);
        }

        return response()->json([
            'token'     => $response->json()['token'],
            'room_url'  => "https://nexevo-sales.daily.co/{$roomName}",
            'room_name' => $roomName,
        ]);
    }

    private function buildMeeting(
        string $meetingType,
        int    $userId,
        Carbon $start,
        Carbon $end,
        string $guestName,
        string $guestEmail
    ): array {
        if ($meetingType === 'gmeet') {
            return $this->createGoogleMeetEvent($userId, $start, $end, $guestName, $guestEmail);
        }
        $roomName = 'crm-' . Str::random(10);
        return [$roomName, "https://meet.jit.si/{$roomName}"];
    }

    private function createGoogleMeetEvent(
        int    $userId,
        Carbon $start,
        Carbon $end,
        string $guestName,
        string $guestEmail
    ): array {
        $user = DB::connection('mysql')->table('users')->where('id', $userId)->first();

        if (!$user || empty($user->google_access_token)) {
            Log::warning('Google Meet: no access token for user ' . $userId . '. Falling back to Jitsi.');
            $roomName = 'crm-' . Str::random(10);
            return [$roomName, "https://meet.jit.si/{$roomName}"];
        }

        $accessToken = $this->refreshGoogleTokenIfNeeded($user);
        $timezone    = $user->timezone ?? 'Asia/Kolkata';
        $userName    = $user->name ?? 'Host';

        $body = [
            'summary'     => "Meeting: {$userName} & {$guestName}",
            'description' => "Scheduled via Nexevo CRM\nGuest: {$guestName} ({$guestEmail})\nHost: {$userName}",
            'start'       => [
                'dateTime' => $start->copy()->setTimezone($timezone)->toAtomString(),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $end->copy()->setTimezone($timezone)->toAtomString(),
                'timeZone' => $timezone,
            ],
            'attendees' => [
                ['email' => $user->email,  'displayName' => $userName,  'responseStatus' => 'accepted'],
                ['email' => $guestEmail,   'displayName' => $guestName, 'responseStatus' => 'needsAction'],
            ],
            'conferenceData' => [
                'createRequest' => [
                    'requestId'             => Str::uuid(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides'  => [
                    ['method' => 'email', 'minutes' => 60],
                    ['method' => 'popup', 'minutes' => 10],
                ],
            ],
        ];

        $response = Http::withToken($accessToken)
            ->post(
                'https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1&sendUpdates=all',
                $body
            );

        if (!$response->successful()) {
            Log::error('Google Calendar event creation failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $roomName = 'crm-' . Str::random(10);
            return [$roomName, "https://meet.jit.si/{$roomName}"];
        }

        $event      = $response->json();
        $eventId    = $event['id'] ?? Str::random(12);
        $meetingUrl = $event['hangoutLink']
            ?? $event['conferenceData']['entryPoints'][0]['uri']
            ?? null;

        if (!$meetingUrl) {
            Log::warning('Google Calendar event created but no Meet link.', ['event' => $event]);
            $roomName = 'crm-' . Str::random(10);
            return [$roomName, "https://meet.jit.si/{$roomName}"];
        }

        return [$eventId, $meetingUrl];
    }

    private function deleteGoogleCalendarEvent(int $userId, string $googleEventId): void
    {
        try {
            $user = DB::connection('mysql')->table('users')->where('id', $userId)->first();
            if (!$user || empty($user->google_access_token)) return;

            $accessToken = $this->refreshGoogleTokenIfNeeded($user);

            $response = Http::withToken($accessToken)
                ->delete(
                    "https://www.googleapis.com/calendar/v3/calendars/primary/events/{$googleEventId}?sendUpdates=all"
                );

            if ($response->successful() || $response->status() === 204 || $response->status() === 410) {
                Log::info("Google Calendar event deleted: {$googleEventId} for user {$userId}");
            } else {
                Log::warning("Failed to delete Google Calendar event {$googleEventId}", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('deleteGoogleCalendarEvent exception: ' . $e->getMessage());
        }
    }

    private function patchGoogleCalendarEvent(int $userId, string $googleEventId, Carbon $start, Carbon $end): void
    {
        try {
            $user = DB::connection('mysql')->table('users')->where('id', $userId)->first();
            if (!$user || empty($user->google_access_token)) return;

            $accessToken = $this->refreshGoogleTokenIfNeeded($user);
            $timezone    = $user->timezone ?? 'Asia/Kolkata';

            $response = Http::withToken($accessToken)
                ->patch(
                    "https://www.googleapis.com/calendar/v3/calendars/primary/events/{$googleEventId}?sendUpdates=all",
                    [
                        'start' => [
                            'dateTime' => $start->copy()->setTimezone($timezone)->toAtomString(),
                            'timeZone' => $timezone,
                        ],
                        'end' => [
                            'dateTime' => $end->copy()->setTimezone($timezone)->toAtomString(),
                            'timeZone' => $timezone,
                        ],
                    ]
                );

            if ($response->successful()) {
                Log::info("Google Calendar event rescheduled: {$googleEventId} for user {$userId}");
            } else {
                Log::warning("Failed to patch Google Calendar event {$googleEventId}", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('patchGoogleCalendarEvent exception: ' . $e->getMessage());
        }
    }

    private function refreshGoogleTokenIfNeeded(object $user): string
    {
        if (
            $user->google_token_expires_at &&
            Carbon::parse($user->google_token_expires_at)->isFuture()
        ) {
            return $user->google_access_token;
        }

        if (empty($user->google_refresh_token)) {
            return $user->google_access_token ?? '';
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'refresh_token' => $user->google_refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        if ($response->successful()) {
            $data = $response->json();
            DB::connection('mysql')->table('users')->where('id', $user->id)->update([
                'google_access_token'     => $data['access_token'],
                'google_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
                'updated_at'              => now(),
            ]);
            return $data['access_token'];
        }

        return $user->google_access_token ?? '';
    }

    private function sendConfirmationEmail(
        string  $toEmail,
        string  $toName,
        string  $hostName,
        Carbon  $startTime,
        Carbon  $endTime,
        string  $timezone,
        ?string $meetingUrl,
        string  $meetingType = 'jitsi'
    ): void {
        try {
            $formattedDate  = $startTime->format('l, F j, Y');
            $formattedStart = $startTime->format('g:i A');
            $formattedEnd   = $endTime->format('g:i A');
            $meetingLabel   = $meetingType === 'gmeet' ? 'Google Meet' : 'Jitsi Video';
            $appName        = config('app.name', 'Nexevo Sales CRM');
            $subject        = "Meeting Confirmed: {$formattedDate} at {$formattedStart} with {$hostName}";

            $joinBtn = $meetingUrl
                ? $this->joinButton($meetingUrl, $meetingLabel, 'linear-gradient(135deg,#06b6d4,#8b5cf6)')
                : '<p style="color:#6b7280;text-align:center;font-size:14px;margin:0 0 20px;">Your host will share the meeting link shortly.</p>';

            $body = <<<BODY
<p style="color:#374151;font-size:14px;margin:0 0 16px;">
  Hi <strong>{$toName}</strong>, your meeting with <strong>{$hostName}</strong> is confirmed:
</p>
{$this->detailsTable($formattedDate, "{$formattedStart} – {$formattedEnd}",$timezone,$hostName,$meetingLabel)}
{$joinBtn}
<p style="color:#374151;font-size:13px;margin:0;">
  To reschedule or cancel, contact <strong>{$hostName}</strong> directly.
</p>
BODY;

            Mail::html(
                $this->emailShell('✅', 'Meeting Confirmed!', $appName, $body),
                fn($m) => $m->to($toEmail, $toName)->subject($subject)
            );

            Log::info("Confirmation email sent to {$toEmail}");
        } catch (\Throwable $e) {
            Log::error('Confirmation email failed: ' . $e->getMessage(), ['to' => $toEmail]);
        }
    }

    private function sendCancellationEmail(
        string $toEmail,
        string $toName,
        string $hostName,
        Carbon $start,
        string $timezone
    ): void {
        try {
            $formattedDate  = $start->format('l, F j, Y');
            $formattedStart = $start->format('g:i A');
            $appName        = config('app.name', 'Nexevo Sales CRM');
            $subject        = "Meeting Cancelled: {$formattedDate} at {$formattedStart} with {$hostName}";

            $body = <<<BODY
<p style="color:#374151;font-size:14px;margin:0 0 16px;">
  Hi <strong>{$toName}</strong>, your meeting with <strong>{$hostName}</strong> scheduled for
  <strong>{$formattedDate} at {$formattedStart} ({$timezone})</strong> has been <strong style="color:#ef4444;">cancelled</strong>.
</p>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;margin-bottom:20px;">
  <tr><td style="padding:13px 18px;border-bottom:1px solid #fecaca;">
    <div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;margin-bottom:2px;">📅 Was Scheduled For</div>
    <div style="font-size:14px;color:#111827;font-weight:600;">{$formattedDate} at {$formattedStart} ({$timezone})</div>
  </td></tr>
  <tr><td style="padding:13px 18px;">
    <div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;margin-bottom:2px;">👤 Host</div>
    <div style="font-size:14px;color:#111827;font-weight:600;">{$hostName}</div>
  </td></tr>
</table>
<p style="color:#374151;font-size:13px;margin:0;">
  Please contact <strong>{$hostName}</strong> directly if you'd like to reschedule.
</p>
BODY;

            Mail::html(
                $this->emailShell('❌', 'Meeting Cancelled', $appName, $body),
                fn($m) => $m->to($toEmail, $toName)->subject($subject)
            );

            Log::info("Cancellation email sent to {$toEmail}");
        } catch (\Throwable $e) {
            Log::error('Cancellation email failed: ' . $e->getMessage(), ['to' => $toEmail]);
        }
    }

    private function sendRescheduleEmail(
        string  $toEmail,
        string  $toName,
        string  $hostName,
        Carbon  $newStart,
        Carbon  $newEnd,
        string  $timezone,
        ?string $meetingUrl,
        string  $meetingType = 'jitsi'
    ): void {
        try {
            $formattedDate  = $newStart->format('l, F j, Y');
            $formattedStart = $newStart->format('g:i A');
            $formattedEnd   = $newEnd->format('g:i A');
            $meetingLabel   = $meetingType === 'gmeet' ? 'Google Meet' : 'Jitsi Video';
            $appName        = config('app.name', 'Nexevo Sales CRM');
            $subject        = "Meeting Rescheduled: Now on {$formattedDate} at {$formattedStart} with {$hostName}";

            $joinBtn = $meetingUrl
                ? $this->joinButton($meetingUrl, $meetingLabel, 'linear-gradient(135deg,#f59e0b,#ef4444)')
                : '';

            $body = <<<BODY
<p style="color:#374151;font-size:14px;margin:0 0 16px;">
  Hi <strong>{$toName}</strong>, your meeting with <strong>{$hostName}</strong> has been
  <strong style="color:#f59e0b;">rescheduled</strong> to a new time:
</p>
{$this->detailsTable($formattedDate, "{$formattedStart} – {$formattedEnd}",$timezone,$hostName,$meetingLabel)}
{$joinBtn}
<p style="color:#374151;font-size:13px;margin:0;">
  To cancel or make further changes, contact <strong>{$hostName}</strong> directly.
</p>
BODY;

            Mail::html(
                $this->emailShell('🔄', 'Meeting Rescheduled', $appName, $body),
                fn($m) => $m->to($toEmail, $toName)->subject($subject)
            );

            Log::info("Reschedule email sent to {$toEmail}");
        } catch (\Throwable $e) {
            Log::error('Reschedule email failed: ' . $e->getMessage(), ['to' => $toEmail]);
        }
    }

    private function detailsTable(string $date, string $time, string $tz, string $host, string $platform): string
    {
        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:20px;">
  <tr><td style="padding:13px 18px;border-bottom:1px solid #e2e8f0;">
    <div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;margin-bottom:2px;">📅 Date</div>
    <div style="font-size:14px;color:#111827;font-weight:600;">{$date}</div>
  </td></tr>
  <tr><td style="padding:13px 18px;border-bottom:1px solid #e2e8f0;">
    <div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;margin-bottom:2px;">🕐 Time</div>
    <div style="font-size:14px;color:#111827;font-weight:600;">{$time} ({$tz})</div>
  </td></tr>
  <tr><td style="padding:13px 18px;border-bottom:1px solid #e2e8f0;">
    <div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;margin-bottom:2px;">👤 Host</div>
    <div style="font-size:14px;color:#111827;font-weight:600;">{$host}</div>
  </td></tr>
  <tr><td style="padding:13px 18px;">
    <div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;margin-bottom:2px;">🎥 Platform</div>
    <div style="font-size:14px;color:#111827;font-weight:600;">{$platform}</div>
  </td></tr>
</table>
HTML;
    }

    private function joinButton(string $url, string $label, string $gradient): string
    {
        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
  <tr><td align="center">
    <a href="{$url}" style="display:inline-block;background:{$gradient};color:#fff;text-decoration:none;font-size:15px;font-weight:700;padding:13px 36px;border-radius:12px;">
      🎥 Join {$label}
    </a>
  </td></tr>
</table>
<p style="font-size:12px;color:#9ca3af;text-align:center;margin:0 0 20px;word-break:break-all;">
  Link: <a href="{$url}" style="color:#06b6d4;">{$url}</a>
</p>
HTML;
    }

    private function emailShell(string $emoji, string $title, string $appName, string $body): string
    {
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:40px 20px;">
<tr><td align="center">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
  <tr><td style="background:linear-gradient(135deg,#06b6d4,#8b5cf6);padding:28px 36px;text-align:center;">
    <div style="font-size:36px;margin-bottom:10px;">{$emoji}</div>
    <h1 style="color:#fff;font-size:20px;font-weight:700;margin:0 0 4px;">{$title}</h1>
    <p style="color:rgba(255,255,255,.8);font-size:13px;margin:0;">{$appName}</p>
  </td></tr>
  <tr><td style="padding:28px 36px;">{$body}</td></tr>
  <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:16px 36px;text-align:center;">
    <p style="color:#9ca3af;font-size:11px;margin:0;">Automated notification from {$appName}. Please do not reply.</p>
  </td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
    }

    private function tenantConnectionName(): string
    {
        return (new Booking())->getConnectionName() ?? config('database.default');
    }

    private function tenantDb(): \Illuminate\Database\Connection
    {
        return DB::connection($this->tenantConnectionName());
    }

    private function slotTaken(int $userId, Carbon $start, Carbon $end): bool
    {
        return $this->tenantDb()->table('bookings')
            ->where('user_id', $userId)
            ->where('status', 'scheduled')
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();
    }

    private function generateSlots($availability, int $duration): array
    {
        $slots = [];
        foreach ($availability as $a) {
            $start = Carbon::parse($a->start_time);
            $end   = Carbon::parse($a->end_time);
            while ($start->copy()->addMinutes($duration) <= $end) {
                $slots[] = $start->format('H:i');
                $start->addMinutes($duration);
            }
        }
        return array_unique($slots);
    }

    private function removeBookedSlots(array $slots, int $userId, string $date): array
    {
        $booked = $this->tenantDb()->table('bookings')
            ->where('user_id', $userId)
            ->whereDate('start_time', $date)
            ->where('status', 'scheduled')
            ->pluck('start_time')
            ->map(fn($t) => Carbon::parse($t)->format('H:i'))
            ->toArray();

        return array_values(array_diff($slots, $booked));
    }

    private function insertCalendarEvent(int $userId, object $booking, string $name): void
    {
        try {
            $this->tenantDb()->table('calendar_events')->insert([
                'user_id'      => $userId,
                'type'         => 'booking',
                'reference_id' => $booking->id,
                'title'        => 'Meeting with ' . $name,
                'start_time'   => $booking->start_time,
                'end_time'     => $booking->end_time,
                'status'       => 'scheduled',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CalendarEvent insert skipped: ' . $e->getMessage());
        }
    }

    private function updateCalendarEventTimes(int $bookingId, Carbon $start, Carbon $end): void
    {
        try {
            $this->tenantDb()->table('calendar_events')
                ->where('reference_id', $bookingId)
                ->where('type', 'booking')
                ->update(['start_time' => $start, 'end_time' => $end, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('CalendarEvent update skipped: ' . $e->getMessage());
        }
    }

    private function cancelCalendarEvent(int $bookingId): void
    {
        try {
            $this->tenantDb()->table('calendar_events')
                ->where('reference_id', $bookingId)
                ->where('type', 'booking')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('CalendarEvent cancel skipped: ' . $e->getMessage());
        }
    }
}
