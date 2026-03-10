<?php

namespace App\Http\Controllers;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\FollowUp;
use App\Models\Whatsapp_Message;
use App\Services\AIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;

class CallLogController extends Controller
{
    public function __construct(private AIService $aiService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $user->isAdmin() ? CallLog::query() : CallLog::where('user_id', $user->id);

        if ($request->contact_id) $query->where('contact_id', $request->contact_id);
        if ($request->date) $query->whereDate('created_at', $request->date);
        if ($request->status) $query->where('status', $request->status);

        $calls = $query->with(['contact', 'user'])
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json($calls);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'status' => 'required|in:connected,no_answer,busy,voicemail,call_back',
            'outcome' => 'nullable|string|max:100',
            'duration' => 'nullable|integer',
            'notes' => 'nullable|string',
            'next_action' => 'nullable|string|max:255',
            'next_action_date' => 'nullable|date',
            'interest_level' => 'nullable|integer|min:1|max:5',
            'sentiment' => 'nullable|in:positive,neutral,negative',
        ]);

        $contact = Contact::findOrFail($request->contact_id);

        // 🔥 Generate AI Summary
        $summary = $this->aiService->generateCallSummary(
            $request->notes ?? '',
            '',
            $contact->name,
            $contact->company
        );

        $contact = Contact::findOrFail($request->contact_id);

        /* Generate AI summary */
        $summary = $this->aiService->generateCallSummary(
            $request->notes ?? '',
            '',
            $contact->name,
            $contact->company
        );

        $call = CallLog::create([
            ...$request->all(),
            'user_id' => $request->user()->id,
            'direction' => 'outbound',
            'ai_summary' => $summary
        ]);

        $contact->update([
            'last_contacted_at' => now(),
        ]);

        if ($request->next_action && $request->next_action_date) {
            FollowUp::create([
                'user_id' => $request->user()->id,
                'contact_id' => $request->contact_id,
                'call_log_id' => $call->id,
                'type' => 'call',
                'subject' => $request->next_action,
                'scheduled_at' => $request->next_action_date,
                'status' => 'pending',
            ]);
        }

        $call->load('contact');

        return response()->json([
            'success' => true,
            'call' => $call
        ], 201);
    }

    public function show(CallLog $call): JsonResponse
    {
        $call->load(['contact', 'user']);
        return response()->json($call);
    }

    public function update(Request $request, CallLog $call): JsonResponse
    {
        $call->update($request->all());
        return response()->json(['success' => true, 'call' => $call]);
    }

    public function destroy(CallLog $call): JsonResponse
    {
        $call->delete();
        return response()->json(['success' => true]);
    }

    public function quickLog(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'status' => 'required|in:connected,no_answer,busy,voicemail,call_back',
            'duration' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        $contact = Contact::findOrFail($request->contact_id);

        // 🔥 Generate AI summary
        $summary = $this->aiService->generateCallSummary(
            $request->notes ?? '',
            '',
            $contact->name,
            $contact->company
        );

        $contact = Contact::findOrFail($request->contact_id);

        $summary = $this->aiService->generateCallSummary(
            $request->notes ?? '',
            '',
            $contact->name,
            $contact->company
        );

        $call = CallLog::create([
            'contact_id' => $request->contact_id,
            'user_id' => $request->user()->id,
            'status' => $request->status,
            'duration' => $request->duration ?? 0,
            'notes' => $request->notes ?? '',
            'direction' => 'outbound',
            'ai_summary' => $summary
        ]);

        $contact->update([
            'last_contacted_at' => now()
        ]);

        $call->load('contact');

        return response()->json([
            'success' => true,
            'call' => $call
        ], 201);
    }

    public function generateAISummary(Request $request, CallLog $call): JsonResponse
    {
        $contact = $call->contact;
        $summary = $this->aiService->generateCallSummary(
            $call->notes ?? '',
            $call->voice_transcript ?? '',
            $contact?->name ?? 'Unknown',
            $contact?->company ?? 'Unknown'
        );

        $call->update(['ai_summary' => $summary]);
        return response()->json(['success' => true, 'summary' => $summary]);
    }

    public function todaysCalls(Request $request): JsonResponse
    {
        $user = $request->user();
        $calls = CallLog::where('user_id', $user->id)
            ->whereDate('created_at', today())
            ->with('contact')
            ->latest()
            ->get();

        return response()->json([
            'calls' => $calls,
            'stats' => [
                'total' => $calls->count(),
                'connected' => $calls->where('status', 'connected')->count(),
                'no_answer' => $calls->where('status', 'no_answer')->count(),
                'total_duration' => $calls->sum('duration'),
            ]
        ]);
    }

    public function processVoiceTranscript(Request $request): JsonResponse
    {
        $request->validate([
            'transcript' => 'required|string',
            'contact_id' => 'required|exists:contacts,id',
        ]);

        $parsed = $this->aiService->parseVoiceTranscript(
            $request->transcript,
            Contact::find($request->contact_id)
        );

        return response()->json(['success' => true, 'parsed' => $parsed]);
    }

}
