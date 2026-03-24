<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\AIService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Helpers\MailHelper;
use Illuminate\Support\Facades\DB;

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
        $request->validate([
            'contact_id' => 'required|integer'
        ]);

        $contact = Contact::with([
            'callLogs' => fn($q) => $q->latest()->limit(10),
            'followUps' => fn($q) => $q->latest()->limit(10)
        ])->find($request->contact_id);

        if (!$contact) {
            return response()->json([
                'message' => 'Invalid contact id'
            ], 422);
        }

        $analysis = $this->aiService->analyzeLead($contact);

        $contact->update([
            'ai_score' => $analysis['score'] ?? 0,
            'ai_analysis' => $analysis
        ]);

        return response()->json([
            'analysis' => $analysis
        ]);
    }

    public function generateProposalPDF(Request $request)
    {
        $contactId = $request->contact_id;

        // generate or fetch PDF
        $filePath = storage_path('app/public/proposal.pdf');

        return response()->json([
            'file_url' => asset('storage/proposal.pdf')
        ]);
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

        $followup = $this->aiService->generateFollowUpFromCall(
            new Contact(['name' => $request->contact_name, 'company' => $request->company]),
            $summary
        );

        return response()->json([
            'summary' => $summary,
            'followup_email' => $followup
        ]);
    }

    public function suggestNextAction(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id' => 'required|integer'
        ]);

        $contact = Contact::with([
            'callLogs' => fn($q) => $q->latest()->limit(10)
        ])->find($request->contact_id);

        if (!$contact) {
            return response()->json([
                'message' => 'Invalid contact id'
            ], 422);
        }

        // ✅ THIS is the correct call
        $action = $this->aiService->suggestNextAction($contact);

        return response()->json([
            'action' => $action
        ]);
    }

    public function generateEmail(Request $request)
    {
        $request->validate([
            'contact_id' => 'required|integer',
            'purpose' => 'required|string',
            'tone' => 'nullable|string'
        ]);

        $contact = Contact::find($request->contact_id);

        if (!$contact) {
            return response()->json([
                'message' => 'Invalid contact id'
            ], 422);
        }

        $tone = $request->tone ?? 'friendly';

        $email = $this->aiService->generateEmail(
            $contact,
            $request->purpose,
            $tone
        );

        return response()->json([
            'email' => $email
        ]);
    }

    public function sendGeneratedEmail(Request $request): JsonResponse
    {
        // ✅ Ensure tenant DB is active
        DB::connection()->getPdo();

        $request->validate([
            'contact_id' => 'required|integer',
            'subject' => 'required|string',
            'body' => 'required|string',
            'attachments.*' => 'file|max:20480'
        ]);

        $contact = Contact::find($request->contact_id);

        if (!$contact) {
            return response()->json([
                'message' => 'Invalid contact id'
            ], 422);
        }

        $html = nl2br(e($request->body));

        $attachments = [];

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {

                $originalName = $file->getClientOriginalName();

                $path = $file->storeAs(
                    'email_attachments',
                    $originalName,
                    'public' // ✅ IMPORTANT
                );

                $attachments[] = [
                    'path' => storage_path('app/public/' . $path),
                    'name' => $originalName
                ];
            }
        }

        MailHelper::sendMail(
            $contact->email,
            $request->subject,
            $html,
            true,
            $attachments
        );

        return response()->json([
            'message' => 'Email sent successfully'
        ]);
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
        $request->validate([
            'contact_id' => 'required|integer',
            'purpose' => 'required|string'
        ]);

        $contact = Contact::find($request->contact_id);

        if (!$contact) {
            return response()->json([
                'message' => 'Invalid contact id'
            ], 422);
        }

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
