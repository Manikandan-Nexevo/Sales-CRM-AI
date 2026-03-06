<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\AIService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AIController extends Controller
{
    public function __construct(private AIService $aiService) {}

    public function suggestResponse(Request $request): JsonResponse
    {
        $request->validate(['context' => 'required|string', 'contact_id' => 'nullable|exists:contacts,id']);

        $contact = $request->contact_id ? Contact::find($request->contact_id) : null;
        $suggestion = $this->aiService->suggestResponse($request->context, $contact);

        return response()->json(['suggestion' => $suggestion]);
    }

    public function analyzeLead(Request $request): JsonResponse
    {
        $request->validate(['contact_id' => 'required|exists:contacts,id']);

        $contact = Contact::with(['callLogs', 'followUps'])->find($request->contact_id);
        $analysis = $this->aiService->analyzeLead($contact);

        $contact->update([
            'ai_score' => $analysis['score'] ?? 0,
        ]);

        return response()->json(['analysis' => $analysis]);
    }

    public function generateEmail(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id' => 'required|exists:contacts,id',
            'purpose' => 'required|string',
            'tone' => 'nullable|in:formal,friendly,urgent',
        ]);

        $contact = Contact::find($request->contact_id);
        $email = $this->aiService->generateEmail($contact, $request->purpose, $request->tone ?? 'friendly');

        return response()->json(['email' => $email]);
    }

    public function callSummary(Request $request): JsonResponse
    {
        $request->validate(['notes' => 'required|string', 'contact_name' => 'nullable|string']);

        $summary = $this->aiService->generateCallSummary(
            $request->notes,
            $request->transcript ?? '',
            $request->contact_name ?? 'Unknown',
            $request->company ?? 'Unknown'
        );

        return response()->json(['summary' => $summary]);
    }

    public function suggestNextAction(Request $request): JsonResponse
    {
        $request->validate(['contact_id' => 'required|exists:contacts,id']);

        $contact = Contact::with(['callLogs' => function ($q) {
            $q->latest()->take(5);
        }])->find($request->contact_id);

        $action = $this->aiService->suggestNextAction($contact);
        return response()->json(['action' => $action]);
    }

    public function processVoiceCommand(Request $request): JsonResponse
    {
        $request->validate(['command' => 'required|string']);

        $user = $request->user();

        $result = $this->aiService->processVoiceCommand($request->command, $user);

        return response()->json(['result' => $result]);
    }

    public function generateLinkedInMessage(Request $request): JsonResponse
    {
        $request->validate(['contact_id' => 'required|exists:contacts,id', 'purpose' => 'required|string']);

        $contact = Contact::find($request->contact_id);
        $message = $this->aiService->generateLinkedInMessage($contact, $request->purpose);

        return response()->json(['message' => $message]);
    }

    public function dailyBriefing(Request $request): JsonResponse
    {
        $user = $request->user();
        $briefing = $this->aiService->generateDailyBriefing($user);
        return response()->json(['briefing' => $briefing]);
    }
}
