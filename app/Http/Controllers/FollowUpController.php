<?php

namespace App\Http\Controllers;

use App\Models\FollowUp;
use App\Services\AIService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Services\CalendarService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FollowUpController extends Controller
{
    public function __construct(
        private AIService $aiService,
        private CalendarService $calendarService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = FollowUp::query();

        // ✅ Filter by contact (IMPORTANT)
        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->contact_id);
        }

        // ✅ Restrict sales rep to only their assigned contacts
        if (!$user->isAdmin()) {
            $query->whereHas('contact', function ($q) use ($user) {
                $q->where('assigned_to', $user->id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('scheduled_at', $request->date);
        }

        $followups = $query->with([
            'contact:id,name,company,priority,ai_score,ai_analysis,last_contacted_at,next_followup_at',
            'callLog',
            'user:id,name,role'
        ])
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json($followups);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id' => 'required|exists:tenant.contacts,id',
            'type' => 'required|in:call,email,whatsapp,linkedin,meeting',
            'subject' => 'required|string|max:255',
            'message' => 'nullable|string',
            'scheduled_at' => 'required|date',
        ]);

        try {

            // ✅ Parse once (IST)
            $start = Carbon::parse($request->scheduled_at, 'Asia/Kolkata');
            $end = $start->copy()->addMinutes(30);

            // ❌ STRICT BLOCKING (NO AUTO SHIFT)
            if (!$this->calendarService->isSlotAvailable(
                $request->user()->id,
                $start,
                $end
            )) {
                return response()->json([
                    'success' => false,
                    'message' => 'Time slot already booked'
                ], 422);
            }

            // ✅ Create FollowUp
            $followup = FollowUp::create([
                'contact_id' => $request->contact_id,
                'type' => $request->type,
                'subject' => $request->subject,
                'message' => $request->message,
                'scheduled_at' => $start,
                'user_id' => $request->user()->id,
                'status' => 'pending',
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            // ✅ Create Calendar Event (will not shift)
            $this->calendarService->createEvent(
                $request->user()->id,
                'followup',
                $followup->id,
                $followup->subject,
                $start
            );

            $followup->load('contact');

            return response()->json([
                'success' => true,
                'followup' => $followup,
                'scheduled_at' => $start
            ], 201);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function show($id): JsonResponse
    {
        $followup = FollowUp::findOrFail($id);
        $followup->load(['contact', 'callLog', 'user']);
        return response()->json($followup);
    }

    public function update(Request $request, $id)
    {
        $followup = FollowUp::findOrFail($id);

        try {
            // ✅ If time is being updated
            if ($request->has('scheduled_at')) {

                $start = Carbon::parse($request->scheduled_at, 'Asia/Kolkata');
                $end = $start->copy()->addMinutes(30);

                // 🔥 FIX: ignore current followup
                if (!$this->calendarService->isSlotAvailable(
                    $request->user()->id,
                    $start,
                    $end,
                    $id // 👈 VERY IMPORTANT
                )) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Time slot already booked'
                    ], 422);
                }

                $request->merge([
                    'scheduled_at' => $start
                ]);
            }

            $data = $request->all();
            $data['updated_by'] = $request->user()->id;

            $followup->update($data);

            // 🔥 IMPORTANT: update calendar event also
            if (isset($start)) {
                DB::connection('tenant')
                    ->table('calendar_events')
                    ->where('reference_id', $followup->id)
                    ->update([
                        'start_time' => $start,
                        'end_time' => $end
                    ]);
            }

            return response()->json([
                'success' => true,
                'followup' => $followup
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function destroy($id): JsonResponse
    {
        $followup = FollowUp::findOrFail($id);
        $followup->delete();
        return response()->json(['success' => true]);
    }

    public function dueToday(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $user->isAdmin()
            ? FollowUp::query()
            : FollowUp::where('user_id', $user->id);

        $followups = $query
            ->dueToday()
            ->with([
                'contact:id,name,company,priority,ai_score,ai_analysis,last_contacted_at,next_followup_at',
                'user:id,name,role'
            ])
            ->orderBy('scheduled_at')
            ->get();

        return response()->json($followups);
    }

    public function markComplete(Request $request, $id): JsonResponse
    {
        $followup = FollowUp::findOrFail($id);
        $followup->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        return response()->json(['success' => true, 'followup' => $followup]);
    }

    public function sendEmail(Request $request, $id): JsonResponse
    {
        $followup = FollowUp::findOrFail($id);
        try {
            $contact = $followup->contact;
            if (!$contact->email) {
                return response()->json(['success' => false, 'message' => 'Contact has no email'], 422);
            }

            // Generate AI email if no message
            if (!$followup->message) {
                $emailContent = $this->aiService->generateEmail($contact, $followup->subject, 'friendly');
                $followup->update(['message' => $emailContent['body'] ?? $followup->subject]);
            }

            // Send mail (configure SMTP in .env for production)
            Mail::raw($followup->message, function ($mail) use ($contact, $followup, $request) {
                $mail->to($contact->email)
                    ->subject($followup->subject)
                    ->from(config('mail.from.address'), $request->user()->name);
            });

            $followup->update(['email_sent' => true]);
            return response()->json(['success' => true, 'message' => 'Email sent successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function sendWhatsApp(Request $request, $id): JsonResponse
    {
        $followup = FollowUp::findOrFail($id);
        $contact = $followup->contact;
        $phone = $contact->phone;
        $message = urlencode($followup->message ?? $followup->subject);
        $whatsappUrl = "https://api.whatsapp.com/send?phone={$phone}&text={$message}";

        $followup->update(['whatsapp_sent' => true]);

        return response()->json(['success' => true, 'whatsapp_url' => $whatsappUrl]);
    }

    public function upcomingFollowups(Request $request)
    {
        $user = $request->user();

        return $user
            ->followUps()
            ->where('status', 'pending')
            ->whereBetween('scheduled_at', [now(), now()->addMinutes(10)])
            ->orderBy('scheduled_at')
            ->with('contact:id,name')
            ->get()
            ->map(function ($f) {
                return [
                    'id' => $f->id,
                    'contact_id' => $f->contact_id, // IMPORTANT
                    'contact_name' => $f->contact?->name,
                    'subject' => $f->subject,
                    'scheduled_at' => $f->scheduled_at
                ];
            });
    }
}
