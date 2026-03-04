<?php

namespace App\Services;

use App\Helpers\MailHelper;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    private string $apiKey;
    private string $model = 'llama-3.1-8b-instant';
    private string $apiUrl = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('groqai.api_key', env('GROQ_API_KEY', ''));
    }

    private function chat(string $systemPrompt, string $userMessage, int $maxTokens = 500): string
    {
        if (empty($this->apiKey)) {
            return $this->getMockResponse($userMessage);
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post($this->apiUrl, [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.7,
                ]);

            if ($response->successful()) {
                return $response->json('choices.0.message.content') ?? 'AI response unavailable';
            }

            Log::error('GROQAI API error: ' . $response->body());
            return 'AI service temporarily unavailable.';
        } catch (\Exception $e) {
            Log::error('AIService error: ' . $e->getMessage());
            return 'AI service temporarily unavailable.';
        }
    }

    public function generateCallSummary(string $notes, string $transcript, string $contactName, string $company): string
    {
        $system = "You are an expert sales call analyst for Nexevo, an IT company. Summarize sales calls concisely and highlight key information for follow-up.";
        $user = "Summarize this sales call with {$contactName} from {$company}.\n\nNotes: {$notes}\nTranscript: {$transcript}\n\nProvide: 1) Key points discussed 2) Client interest level 3) Pain points mentioned 4) Recommended next steps. Keep it under 150 words.";

        return $this->chat($system, $user, 300);
    }

    public function analyzeLead(Contact $contact): array
    {
        $callCount = $contact->callLogs->count();
        $lastCallNote = $contact->callLogs->first()?->notes ?? 'No calls yet';

        $system = "You are a B2B sales analyst. Analyze leads for an IT company called Nexevo that provides software development and IT services.";
        $user = "Analyze this lead and provide a JSON response with these fields: score (0-100), stage (cold/warm/hot), buy_intent (low/medium/high), recommended_approach (string), key_insights (array of 3 strings), next_best_action (string).\n\nContact: {$contact->name}, {$contact->designation} at {$contact->company}\nIndustry: {$contact->industry}\nStatus: {$contact->status}\nCalls made: {$callCount}\nLast call note: {$lastCallNote}\n\nReturn ONLY valid JSON.";

        $response = $this->chat($system, $user, 400);

        try {
            $clean = preg_replace('/```json|```/', '', $response);
            return json_decode(trim($clean), true) ?? $this->defaultAnalysis();
        } catch (\Exception $e) {
            return $this->defaultAnalysis();
        }
    }

    public function generateEmail(Contact $contact, string $purpose, string $tone = 'friendly'): array
    {
        $system = "You are a senior sales executive at Nexevo, an IT company specializing in web development and digital transformation.";

        $user = "Write a {$tone} follow-up email.

        To: {$contact->name}, {$contact->designation} at {$contact->company}
        Purpose: {$purpose}
        Industry: {$contact->industry}

        Rules:
        - Write only the email body.
        - Do NOT return JSON.
        - Keep it under 180 words.
        - Use short paragraphs.
        - Leave a blank line between paragraphs.
        - End with:
        Best regards,
        Nexevo Sales Team";

        $response = $this->chat($system, $user, 400);

        try {
            $bodyText = trim(preg_replace('/```.*?```/s', '', $response));
            $paragraphs = explode("\n", $bodyText);
            $htmlBody = '';

            foreach ($paragraphs as $line) {
                if (trim($line) !== '') {
                    $htmlBody .= '<p style="margin:0 0 15px 0;">' . e($line) . '</p>';
                }
            }

            $subject = "Following up - Nexevo";

            MailHelper::sendMail($contact->email, $subject, $htmlBody, true);

            return [
                'subject' => $subject,
                'body' => $bodyText
            ];
        } catch (\Exception $e) {
            Log::error('Email generation error: ' . $e->getMessage());

            return [
                'subject' => 'Following up - Nexevo',
                'body' => $response
            ];
        }
    }

    public function suggestResponse(string $context, ?Contact $contact): string
    {
        $system = "You are a sales coach for Nexevo IT company. Provide quick, effective response suggestions for sales reps during live calls or messaging.";
        $contactInfo = $contact ? "{$contact->name} from {$contact->company} ({$contact->industry})" : "Unknown contact";
        $user = "Sales rep is talking to: {$contactInfo}\nContext: {$context}\n\nSuggest a brief, effective response (2-3 sentences max).";

        return $this->chat($system, $user, 200);
    }

    public function suggestNextAction(Contact $contact): array
    {
        $recentCalls = $contact->callLogs->take(3);
        $callSummary = $recentCalls->map(fn($c) => "{$c->status}: {$c->notes}")->join('; ');

        $system = "You are a sales strategist for Nexevo IT company. Suggest optimal next sales actions based on contact history.";
        $user = "Contact: {$contact->name} at {$contact->company}, Status: {$contact->status}\nRecent calls: {$callSummary}\n\nReturn JSON: {action: string, type: 'call'|'email'|'whatsapp'|'linkedin', timing: string, reason: string}";

        $response = $this->chat($system, $user, 200);

        try {
            $clean = preg_replace('/```json|```/', '', $response);
            return json_decode(trim($clean), true) ?? ['action' => 'Follow up via phone', 'type' => 'call', 'timing' => 'Tomorrow morning', 'reason' => 'Continue engagement'];
        } catch (\Exception $e) {
            return ['action' => 'Follow up via phone', 'type' => 'call', 'timing' => 'Tomorrow morning', 'reason' => 'Continue engagement'];
        }
    }

    public function processVoiceCommand(string $command, User $user): array
    {
        $system = "You are an AI assistant for Nexevo Sales CRM. Interpret voice commands from sales reps and return structured actions. Available actions: log_call, create_contact, schedule_followup, search_contact, get_briefing, navigate.";
        $userMsg = "Voice command from sales rep: \"{$command}\"\n\nReturn JSON: {action: string, params: object, response: string (what to say back)}";

        $response = $this->chat($system, $userMsg, 300);

        // Clean & extract JSON safely
        $clean = trim($response);

        if (preg_match('/\{.*\}/s', $clean, $matches)) {
            $clean = $matches[0];
        }

        $data = json_decode($clean, true);

        if (is_array($data) && isset($data['action'])) {
            return $data;
        }

        // Fallback if parsing fails
        return [
            'action' => 'unknown',
            'params' => [],
            'response' => 'Sorry, I did not understand that command.'
        ];
    }

    public function generateLinkedInMessage(Contact $contact, string $purpose): string
    {
        $system = "You are a LinkedIn outreach specialist for Nexevo IT company. Write personalized connection requests and messages that get responses.";
        $user = "Write a LinkedIn {$purpose} message to {$contact->name}, {$contact->designation} at {$contact->company} (Industry: {$contact->industry}).\nKeep it under 300 characters for connection request, 1000 for message. Be genuine, not salesy.";

        return $this->chat($system, $user, 300);
    }

    public function generateDailyBriefing(User $user): array
    {
        $pendingFollowups = $user->followUps()->dueToday()->count();
        $todayCalls = $user->todayCallCount();
        $target = $user->target_calls_daily;

        $system = "You are an AI sales coach for Nexevo IT company. Give motivating, actionable daily briefings to sales reps.";
        $userMsg = "Daily briefing for {$user->name}.\nTarget calls today: {$target}\nCalls done: {$todayCalls}\nPending follow-ups: {$pendingFollowups}\n\nReturn JSON: {greeting: string, priority: string, tip: string, motivation: string}";

        $response = $this->chat($system, $userMsg, 300);

        try {
            $clean = preg_replace('/```json|```/', '', $response);
            return json_decode(trim($clean), true) ?? $this->defaultBriefing($user->name);
        } catch (\Exception $e) {
            return $this->defaultBriefing($user->name);
        }
    }

    public function parseVoiceTranscript(string $transcript, Contact $contact): array
    {
        $system = "You are a sales call analyzer. Extract structured data from call transcripts.";
        $user = "Parse this call transcript with {$contact->name} from {$contact->company}:\n\"{$transcript}\"\n\nReturn JSON: {status: string, outcome: string, notes: string, sentiment: 'positive'|'neutral'|'negative', interest_level: 1-5, next_action: string, next_action_date: string}";

        $response = $this->chat($system, $user, 400);

        try {
            $clean = preg_replace('/```json|```/', '', $response);
            return json_decode(trim($clean), true) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getMockResponse(string $context): string
    {
        return "AI response for: " . substr($context, 0, 50) . "... (Configure GROQ_API_KEY in .env for real AI responses)";
    }

    private function defaultAnalysis(): array
    {
        return [
            'score' => 50,
            'stage' => 'warm',
            'buy_intent' => 'medium',
            'recommended_approach' => 'Continue nurturing with value-based content',
            'key_insights' => ['Regular follow-up needed', 'Understand their IT budget cycle', 'Identify decision maker'],
            'next_best_action' => 'Schedule a demo call',
        ];
    }

    private function defaultBriefing(string $name): array
    {
        return [
            'greeting' => "Good morning, {$name}! Ready to crush your targets today?",
            'priority' => 'Focus on hot leads first, then follow up on yesterday\'s calls.',
            'tip' => 'Lead with value: Ask about their current challenges before pitching.',
            'motivation' => 'Every call is an opportunity. Make them count!',
        ];
    }
}
