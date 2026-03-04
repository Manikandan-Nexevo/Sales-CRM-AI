<?php

namespace App\Http\Controllers;

use App\Models\FollowUp;
use App\Services\AIService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class FollowUpController extends Controller
{
    public function __construct(private AIService $aiService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $user->isAdmin()
            ? FollowUp::query()
            : FollowUp::where('user_id', $user->id);

        // 🔥 ADD THIS
        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->contact_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('scheduled_at', $request->date);
        }

        $followups = $query->with(['contact', 'callLog', 'user'])
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json($followups);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'type' => 'required|in:call,email,whatsapp,linkedin,meeting',
            'subject' => 'required|string|max:255',
            'message' => 'nullable|string',
            'scheduled_at' => 'required|date',
        ]);

        // 🔥 Parse as IST (Asia/Kolkata) to prevent UTC shifting
        $scheduledAt = \Carbon\Carbon::parse(
            $request->scheduled_at,
            'Asia/Kolkata'
        );

        $followup = FollowUp::create([
            ...$request->all(),
            'user_id' => $request->user()->id,
            'status' => 'pending',
        ]);;

        $followup->load('contact');

        return response()->json([
            'success' => true,
            'followup' => $followup
        ], 201);
    }

    public function show(FollowUp $followup): JsonResponse
    {
        $followup->load(['contact', 'callLog', 'user']);
        return response()->json($followup);
    }

    public function update(Request $request, FollowUp $followup): JsonResponse
    {
        if ($request->has('scheduled_at')) {
            $request->merge([
                'scheduled_at' => \Carbon\Carbon::parse(
                    $request->scheduled_at,
                    'Asia/Kolkata'
                )
            ]);
        }

        $followup->update($request->all());

        return response()->json([
            'success' => true,
            'followup' => $followup
        ]);
    }

    public function destroy(FollowUp $followup): JsonResponse
    {
        $followup->delete();
        return response()->json(['success' => true]);
    }

    public function dueToday(Request $request): JsonResponse
    {
        $user = $request->user();
        $followups = FollowUp::where('user_id', $user->id)
            ->dueToday()
            ->with('contact')
            ->orderBy('scheduled_at')
            ->get();

        return response()->json($followups);
    }

    public function markComplete(Request $request, FollowUp $followup): JsonResponse
    {
        $followup->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        return response()->json(['success' => true, 'followup' => $followup]);
    }

    public function sendEmail(Request $request, FollowUp $followup): JsonResponse
    {
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

    public function sendWhatsApp(Request $request, FollowUp $followup): JsonResponse
    {
        $contact = $followup->contact;
        $phone = $contact->phone;
        $message = urlencode($followup->message ?? $followup->subject);
        $whatsappUrl = "https://api.whatsapp.com/send?phone={$phone}&text={$message}";

        $followup->update(['whatsapp_sent' => true]);

        return response()->json(['success' => true, 'whatsapp_url' => $whatsappUrl]);
    }
}
