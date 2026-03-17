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

    private string $provider;

    public function __construct()
    {
        $company = auth()->user()?->company;

        // ✅ Resolve provider based on PLAN (not directly from DB)
        $this->provider = $this->resolveProviderFromPlan($company);

        // ✅ Set API key dynamically
        $this->apiKey = $company && $company->ai_api_key
            ? decrypt($company->ai_api_key)
            : $this->getDefaultApiKey();

        // ✅ Set API URL + model
        $this->setProviderConfig();
    }

    private function resolveProviderFromPlan($company): string
    {
        if (!$company) {
            return 'groq';
        }

        return match ($company->plan) {
            'basic' => 'groq',          // free
            'growth' => 'gemini',       // better quality
            'pro' => 'openrouter',      // multiple models
            'enterprise' => $company->ai_provider ?? 'groq', // custom
            default => 'groq',
        };
    }

    private function getDefaultApiKey(): string
    {
        return match ($this->provider) {
            'gemini' => env('GEMINI_API_KEY'),
            'openrouter' => env('OPENROUTER_API_KEY'),
            default => env('GROQ_API_KEY'),
        };
    }

    private function setProviderConfig()
    {
        switch ($this->provider) {

            case 'gemini':
                $this->apiUrl = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent';
                $this->model = 'gemini-1.5-flash';
                break;

            case 'openrouter':
                $this->apiUrl = 'https://openrouter.ai/api/v1/chat/completions';
                $this->model = 'mistral:free';
                break;

            default: // groq
                $this->apiUrl = 'https://api.groq.com/openai/v1/chat/completions';
                $this->model = 'llama-3.1-8b-instant';
        }
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

    public function generateCallSummary(
        string $notes,
        string $transcript,
        string $contactName,
        string $company,
        string $outcome = '',
        int $interest = 0,
        string $sentiment = ''
    ): string {

        if (str_contains($notes, 'Key Points')) {
            $notes = '';
        }

        if (empty($company)) {
            $company = 'Company not provided';
        }

        Log::info("AI CALL SUMMARY INPUT", [
            'contact' => $contactName,
            'company' => $company,
            'notes' => $notes
        ]);

        $system = "
You are an AI CRM assistant for Nexevo.

Summarize sales calls for CRM usage.

Rules:
- Start directly with 'Key Points'
- No titles
- Use '-' for bullet points
";

        $user = "
Contact: {$contactName}
Company: {$company}

Outcome: {$outcome}
Interest Level: {$interest}/5
Sentiment: {$sentiment}

Notes:
{$notes}

Transcript:
{$transcript}

Return format:

Key Points
- point
- point

Client Interest
- High / Medium / Low

Pain Points
- point
- point

Recommended Next Steps
- point
- point
";

        try {

            $response = $this->chat($system, $user, 300);

            /* ------------------------------------
           FIX UTF-8
        ------------------------------------ */

            $response = mb_convert_encoding($response, 'UTF-8', 'UTF-8');
            $response = iconv('UTF-8', 'UTF-8//IGNORE', $response);

            /* ------------------------------------
           CLEAN RESPONSE
        ------------------------------------ */

            $clean = preg_replace('/```.*?```/s', '', $response);

            // Normalize bullet styles
            $clean = preg_replace('/^[\*\•]\s*/m', '- ', $clean);

            // Join wrapped lines inside bullets
            $clean = preg_replace('/\n(?!- |Key Points|Client Interest|Pain Points|Recommended Next Steps)/', ' ', $clean);

            // Ensure bullet begins new line
            $clean = preg_replace('/(?<!\n)- /', "\n- ", $clean);

            // Remove empty bullets
            $clean = preg_replace('/\n-\s*(?=\n|$)/', '', $clean);

            // Normalize section headers
            $clean = preg_replace('/Key Points\s*/i', "Key Points\n", $clean);
            $clean = preg_replace('/Client Interest\s*/i', "\nClient Interest\n", $clean);
            $clean = preg_replace('/Pain Points\s*/i', "\nPain Points\n", $clean);
            $clean = preg_replace('/Recommended Next Steps\s*/i', "\nRecommended Next Steps\n", $clean);

            // Remove extra spacing
            $clean = preg_replace("/\n{3,}/", "\n\n", $clean);

            Log::info("AI CALL SUMMARY OUTPUT", [
                'summary' => $clean
            ]);

            return trim($clean);
        } catch (\Exception $e) {

            Log::error('AI Call Summary Error: ' . $e->getMessage());

            return "Key Points
- Call completed with {$contactName}
- Discussion with {$company}

Client Interest
- Medium

Pain Points
- No major pain points mentioned

Recommended Next Steps
- Schedule follow-up call
- Share service details";
        }
    }
    public function analyzeLead(Contact $contact): array
    {
        $callCount = $contact->callLogs->count();
        $lastCallNote = $contact->callLogs->first()?->notes ?? 'No calls yet';

        $system = "You are a B2B sales analyst. Analyze leads for an IT company called Nexevo that provides software development and IT services.";

        $user = "Analyze this lead and provide a JSON response with these fields:
    score (0-100),
    stage (cold/warm/hot),
    buy_intent (low/medium/high),
    recommended_approach (string),
    key_insights (array of 3 strings),
    next_best_action (string).

Contact: {$contact->name}, {$contact->designation} at {$contact->company}
Industry: {$contact->industry}
Status: {$contact->status}
Calls made: {$callCount}
Last call note: {$lastCallNote}

Return ONLY valid JSON.";

        $response = $this->chat($system, $user, 400);

        try {

            $clean = preg_replace('/```json|```/', '', $response);
            $analysis = json_decode(trim($clean), true) ?? $this->defaultAnalysis();

            /* --------------------------------------------------
           CALCULATE CRM LEAD SCORE (REAL SCORING)
        -------------------------------------------------- */

            $score = $this->calculateLeadScore($contact);

            /* --------------------------------------------------
           UPDATE CONTACT AI SCORE
        -------------------------------------------------- */

            $contact->update([
                'ai_score' => $score
            ]);

            /* --------------------------------------------------
           ADD SCORE INTO RESPONSE
        -------------------------------------------------- */

            $analysis['score'] = $score;

            return $analysis;
        } catch (\Exception $e) {

            $analysis = $this->defaultAnalysis();

            $score = $this->calculateLeadScore($contact);

            $contact->update([
                'ai_score' => $score
            ]);

            $analysis['score'] = $score;

            return $analysis;
        }
    }

    private function contactQuery(User $user)
    {
        $query = Contact::query();

        // Admin can see all contacts
        if (!$user->can('admin')) {
            $query->where('assigned_to', $user->id);
        }

        return $query;
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
        $cmd = strtolower(trim($command));

        /*
    |--------------------------------------------------------------------------
    | NAVIGATION COMMANDS
    |--------------------------------------------------------------------------
    */

        if (str_contains($cmd, 'open contacts') || str_contains($cmd, 'show contacts')) {
            return [
                'action' => 'navigate',
                'params' => ['page' => 'contacts'],
                'response' => 'Opening contacts.'
            ];
        }

        if (str_contains($cmd, 'open calls') || str_contains($cmd, 'open call logs')) {
            return [
                'action' => 'navigate',
                'params' => ['page' => 'calls'],
                'response' => 'Opening call logs.'
            ];
        }

        if (str_contains($cmd, 'open followups') || str_contains($cmd, 'open follow ups')) {
            return [
                'action' => 'navigate',
                'params' => ['page' => 'followups'],
                'response' => 'Opening followups.'
            ];
        }

        if (str_contains($cmd, 'open dashboard') || str_contains($cmd, 'go home')) {
            return [
                'action' => 'navigate',
                'params' => ['page' => 'dashboard'],
                'response' => 'Opening dashboard.'
            ];
        }

        /*
|--------------------------------------------------------------------------
| SHOW HOT LEADS
|--------------------------------------------------------------------------
*/

        if (
            str_contains($cmd, 'hot leads') ||
            str_contains($cmd, 'show hot leads') ||
            str_contains($cmd, 'high priority leads')
        ) {

            $leads = $this->contactQuery($user)
                ->where('priority', 'high')
                ->take(5)
                ->get();

            if ($leads->isEmpty()) {
                return [
                    'action' => 'none',
                    'params' => [],
                    'response' => 'You currently have no high priority leads.'
                ];
            }

            $names = $leads->pluck('name')->implode(', ');

            return [
                'action' => 'navigate',
                'params' => ['page' => 'contacts'],
                'response' => "You have {$leads->count()} hot leads: {$names}. I'm opening the contacts list."
            ];
        }

        /*
|--------------------------------------------------------------------------
| BEST LEADS
|--------------------------------------------------------------------------
*/

        if (
            str_contains($cmd, 'best leads') ||
            str_contains($cmd, 'top leads') ||
            str_contains($cmd, 'highest scoring leads')
        ) {

            $leads = $this->contactQuery($user)
                ->orderByDesc('ai_score')
                ->take(3)
                ->get();

            if ($leads->isEmpty()) {
                return [
                    'action' => 'none',
                    'params' => [],
                    'response' => 'No leads available.'
                ];
            }

            $text = "Your top leads right now are:\n\n";

            foreach ($leads as $lead) {
                $text .= "{$lead->name} from {$lead->company} with a score of {$lead->ai_score}.\n";
            }

            return [
                'action' => 'navigate',
                'params' => ['page' => 'contacts'],
                'response' => $text
            ];
        }

        /*
|--------------------------------------------------------------------------
| INACTIVE LEADS
|--------------------------------------------------------------------------
*/

        if (
            str_contains($cmd, 'inactive leads') ||
            str_contains($cmd, 'cold leads') ||
            str_contains($cmd, 'not contacted')
        ) {

            $leads = $this->contactQuery($user)
                ->whereDoesntHave('callLogs', function ($q) {
                    $q->where('created_at', '>=', now()->subDays(7));
                })
                ->take(5)
                ->get();

            if ($leads->isEmpty()) {
                return [
                    'action' => 'none',
                    'params' => [],
                    'response' => 'All leads have been contacted recently. Good job!'
                ];
            }

            $names = $leads->pluck('name')->implode(', ');

            return [
                'action' => 'navigate',
                'params' => ['page' => 'contacts'],
                'response' => "{$leads->count()} leads haven't been contacted in over a week: {$names}."
            ];
        }

        /*
|--------------------------------------------------------------------------
| FOLLOWUPS TODAY
|--------------------------------------------------------------------------
*/

        if (
            str_contains($cmd, 'follow ups today') ||
            str_contains($cmd, 'followups today') ||
            str_contains($cmd, 'who should i follow up')
        ) {

            $count = $user->followUps()
                ->whereDate('scheduled_at', today())
                ->count();

            if ($count === 0) {
                return [
                    'action' => 'none',
                    'params' => [],
                    'response' => 'You have no follow-ups scheduled for today.'
                ];
            }

            return [
                'action' => 'navigate',
                'params' => ['page' => 'followups'],
                'response' => "You have {$count} follow-ups scheduled today. Opening your follow-ups."
            ];
        }

        /*
|--------------------------------------------------------------------------
| DEALS AT RISK
|--------------------------------------------------------------------------
*/

        if (
            str_contains($cmd, 'deals at risk') ||
            str_contains($cmd, 'risk deals') ||
            str_contains($cmd, 'at risk')
        ) {

            $leads = $this->contactQuery($user)
                ->whereNotIn('status', ['closed_won', 'closed_lost'])
                ->get()
                ->filter(function ($lead) {

                    $last = $this->getLastContactDate($lead);

                    $days = $last
                        ? now()->diffInDays($last)
                        : 30;

                    $risk = false;

                    if ($days > 14) {
                        $risk = true;
                    }

                    if ($days > 7 && in_array($lead->status, ['hot', 'proposal'])) {
                        $risk = true;
                    }

                    if ($lead->ai_score < 50) {
                        $risk = true;
                    }

                    return $risk;
                })
                ->sortBy('ai_score')
                ->take(5);

            if ($leads->isEmpty()) {
                return [
                    'action' => 'none',
                    'params' => [],
                    'response' => 'No deals appear to be at risk right now.'
                ];
            }

            $text = "⚠ These deals may be at risk:\n\n";

            foreach ($leads as $lead) {

                $last = $this->getLastContactDate($lead);

                $days = $last
                    ? now()->diffInDays($last)
                    : 'never';

                $text .= "{$lead->name} from {$lead->company}\n";
                $text .= "AI score: {$lead->ai_score}\n";
                $text .= "Last contact: {$days} days ago\n\n";
            }

            $text .= "I recommend reaching out to re-engage these leads.";

            return [
                'action' => 'navigate',
                'params' => ['page' => 'contacts'],
                'response' => $text
            ];
        }
        /*
    |--------------------------------------------------------------------------
    | DAILY BRIEFING
    |--------------------------------------------------------------------------
    */

        if (
            str_contains($cmd, 'brief') ||
            str_contains($cmd, 'daily briefing') ||
            str_contains($cmd, 'today update')
        ) {

            $data = $this->generateDailyBriefing($user);

            $response = "Good morning {$user->name}. Here's your CRM update.\n\n";

            $response .= "You have {$data['followups_today']} follow-ups scheduled today";

            if ($data['overdue_followups'] > 0) {
                $response .= " and {$data['overdue_followups']} overdue follow-ups.";
            }

            $response .= "\n\nYou've logged {$data['calls_today']} calls today.";

            $response .= "\n\nThere are {$data['hot_leads']} hot leads in the pipeline.";

            if ($data['inactive_leads'] > 0) {
                $response .= "\n\n{$data['inactive_leads']} leads haven't been contacted in 7 days.";
            }

            if ($data['top_rep']) {
                if (!empty($data['top_rep'])) {
                    $response .= "\n\nTop performer this week is {$data['top_rep']} with {$data['top_rep_score']} activities.";
                }
            }

            if ($data['hot_leads'] > 0) {
                $response .= "\n\nFocus on your hot leads first.";
            } elseif ($data['followups_today'] > 0) {
                $response .= "\n\nStart with your scheduled follow-ups.";
            } else {
                $response .= "\n\nConsider reaching out to new prospects.";
            }

            return [
                'action' => 'daily_briefing',
                'params' => $data,
                'response' => $response
            ];
        }

        /*
|--------------------------------------------------------------------------
| SUMMARIZE CALL NOTES
|--------------------------------------------------------------------------
*/

        if (
            str_contains($cmd, 'summarize call') ||
            str_contains($cmd, 'summarize my call') ||
            str_contains($cmd, 'call summary')
        ) {

            return [
                'action' => 'open_call_log',
                'params' => [
                    'mode' => 'ai_summary'
                ],
                'response' => 'Please paste your call notes and I will summarize them.'
            ];
        }

        /*
|--------------------------------------------------------------------------
| DEAL PROBABILITY
|--------------------------------------------------------------------------
*/

        if (
            str_contains($cmd, 'deal probability') ||
            str_contains($cmd, 'chance to close') ||
            str_contains($cmd, 'closing chance')
        ) {

            $lead = $this->getNextBestLead($user);

            if (!$lead) {
                return [
                    'action' => 'none',
                    'params' => [],
                    'response' => 'No leads available for prediction.'
                ];
            }

            if (in_array($lead->status, ['closed_won', 'closed_lost'])) {

                return [
                    'action' => 'none',
                    'params' => [],
                    'response' => "This lead is already {$lead->status}. No probability prediction is needed."
                ];
            }

            $probability = $this->predictDealProbability($lead);

            $response = "The lead most likely to close is {$lead->name} from {$lead->company}.\n\n";
            $response .= "Estimated closing probability: {$probability}%.\n\n";

            if ($probability > 75) {
                $response .= "This is a strong opportunity. I recommend scheduling a demo or proposal.";
            } elseif ($probability > 50) {
                $response .= "This lead is promising but needs further engagement.";
            } else {
                $response .= "This lead still needs nurturing.";
            }

            return [
                'action' => 'open_contact',
                'params' => ['contact_id' => $lead->id],
                'response' => $response
            ];
        }
        /*
|--------------------------------------------------------------------------
| NEXT BEST LEAD
|--------------------------------------------------------------------------
*/

        if (
            str_contains($cmd, 'which lead') ||
            str_contains($cmd, 'who should i call') ||
            str_contains($cmd, 'next lead') ||
            str_contains($cmd, 'next call')
        ) {

            $lead = $this->getNextBestLead($user);

            if (!$lead) {
                return [
                    'action' => 'none',
                    'params' => [],
                    'response' => 'You currently have no leads that need attention.'
                ];
            }

            $days = $lead->last_contact_days ?? 0;

            $response = "You should call {$lead->name} from {$lead->company} next.\n\n";

            if ($lead->priority === 'high') {
                $response .= "This is a high priority lead. ";
            }

            if ($days > 0) {
                $response .= "Your last interaction was {$days} days ago. ";
            }

            $response .= "\n\nReaching out now could improve conversion chances.";

            return [
                'action' => 'open_contact',
                'params' => [
                    'contact_id' => $lead->id
                ],
                'response' => $response
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | CONTACT CLARIFICATION
    |--------------------------------------------------------------------------
    */

        if (session()->has('ai_contact_options')) {

            $options = Contact::whereIn('id', session('ai_contact_options'))->get();
            $original = session('ai_pending_command');

            foreach ($options as $c) {

                $name = strtolower($c->name);
                $parts = explode(' ', $name);
                $first = $parts[0] ?? '';
                $last  = $parts[1] ?? '';

                if (
                    str_contains($cmd, $name) ||
                    str_contains($cmd, $first) ||
                    ($last && str_contains($cmd, $last))
                ) {

                    session()->forget([
                        'ai_contact_options',
                        'ai_pending_command'
                    ]);

                    $updatedCommand = preg_replace(
                        '/\b' . preg_quote($first, '/') . '\b/i',
                        $c->name,
                        $original
                    );

                    return $this->processVoiceCommand($updatedCommand, $user);
                }
            }

            return [
                'action' => 'clarify_contact',
                'params' => [
                    'options' => $options->pluck('name')
                ],
                'response' => 'Please select one of the listed contacts.'
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | CONTACT MATCHING (FIXED)
    |--------------------------------------------------------------------------
    */

        $contacts = Contact::select('id', 'name')->get();

        $matches = [];
        $firstMatches = [];

        foreach ($contacts as $c) {

            $name = strtolower($c->name);
            $parts = explode(' ', $name);

            $first = $parts[0] ?? '';
            $last  = $parts[1] ?? '';

            /* FULL NAME MATCH (highest priority) */

            if (str_contains($cmd, $name)) {
                $matches[] = $c;
                continue;
            }

            /* FIRST NAME MATCH (fallback) */

            if ($first && str_contains($cmd, $first)) {
                $firstMatches[] = $c;
            }
        }

        /* If full name found, ignore first-name matches */

        if (count($matches) === 0) {
            $matches = $firstMatches;
        }

        $matches = collect($matches)->unique('id')->values();

        /*
    |--------------------------------------------------------------------------
    | MULTIPLE MATCHES
    |--------------------------------------------------------------------------
    */

        if ($matches->count() > 1) {

            session([
                'ai_pending_command' => $command,
                'ai_contact_options' => $matches->pluck('id')->toArray()
            ]);

            return [
                'action' => 'clarify_contact',
                'params' => [
                    'options' => $matches->pluck('name')
                ],
                'response' => 'I found multiple contacts: ' . $matches->pluck('name')->implode(', ') . '. Which one?'
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | SINGLE CONTACT
    |--------------------------------------------------------------------------
    */

        $contact = $matches->first();

        /*
    |--------------------------------------------------------------------------
    | FOLLOWUP / CALL
    |--------------------------------------------------------------------------
    */

        if (
            str_contains($cmd, 'call') ||
            str_contains($cmd, 'follow up') ||
            str_contains($cmd, 'meeting') ||
            str_contains($cmd, 'schedule')
        ) {

            if (!$contact) {
                return [
                    'action' => 'contact_not_found',
                    'params' => [],
                    'response' => 'Contact not found in CRM.'
                ];
            }

            $scheduled = now();

            if (str_contains($cmd, 'tomorrow')) {
                $scheduled = now()->addDay();
            }

            if (str_contains($cmd, 'today')) {
                $scheduled = now();
            }

            if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(a\.?m\.?|p\.?m\.?|am|pm)?/i', $cmd, $m)) {

                $hour = (int)$m[1];
                $minute = isset($m[2]) ? (int)$m[2] : 0;

                $period = strtolower($m[3] ?? '');

                // Normalize period
                $period = str_replace('.', '', $period);

                if ($period === 'pm' && $hour < 12) {
                    $hour += 12;
                }

                if ($period === 'am' && $hour == 12) {
                    $hour = 0;
                }

                $scheduled->setTime($hour, $minute, 0);
            }



            /* -----------------------------
   EXTRACT NOTES FROM COMMAND
------------------------------*/

            $notes = '';

            if (preg_match('/(?:note|notes|with notes|add note|message)\s+(.*)/i', $cmd, $match)) {
                $notes = ucfirst(trim($match[1]));
            }
            return [
                'action' => 'open_followup',
                'params' => [
                    'contact_id' => $contact->id,
                    'contact_name' => $contact->name,
                    'type' => 'call',
                    'subject' => "Call with {$contact->name}",
                    'message' => $notes,
                    'scheduled_at' => $scheduled->toDateTimeString()
                ],
                'response' => "Scheduling call with {$contact->name}."
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | OPEN CONTACT
    |--------------------------------------------------------------------------
    */

        if ($contact) {

            return [
                'action' => 'open_contact',
                'params' => [
                    'contact_id' => $contact->id,
                    'contact_name' => $contact->name
                ],
                'response' => "Opening {$contact->name}'s contact."
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | UNKNOWN
    |--------------------------------------------------------------------------
    */

        return [
            'action' => 'unknown',
            'params' => [],
            'response' => 'Sorry, I could not understand.'
        ];
    }


    public function generateFollowUpFromCall(Contact $contact, string $summary): string
    {
        $system = "You are a senior sales executive at Nexevo IT company.";

        $user = "Write a follow-up email after a sales call.

Contact: {$contact->name} from {$contact->company}

Call summary:
{$summary}

Rules:
- Friendly professional tone
- Under 150 words
- Mention next steps if possible
- End with:
Best regards,
Nexevo Sales Team";

        return $this->chat($system, $user, 300);
    }
    private function getNextBestLead(User $user)
    {
        $query = $this->contactQuery($user)
            ->whereNotIn('status', ['closed_won', 'closed_lost'])
            ->withMax('callLogs', 'created_at');

        $lead = $query
            ->orderByRaw("
            CASE status
                WHEN 'proposal' THEN 5
                WHEN 'hot' THEN 4
                WHEN 'qualified' THEN 3
                WHEN 'interested' THEN 2
                WHEN 'contacted' THEN 1
                ELSE 0
            END DESC
        ")
            ->orderByDesc('ai_score')
            ->first();

        if (!$lead) {
            return null;
        }

        if ($lead->call_logs_max_created_at) {
            $lead->last_contact_days = now()->diffInDays($lead->call_logs_max_created_at);
        }

        return $lead;
    }
    /*
    |--------------------------------------------------------------------------
    | LAST CONTACT DATE
    |--------------------------------------------------------------------------
    */

    private function getLastContactDate(Contact $contact)
    {
        $lastCall = $contact->callLogs()->max('created_at');

        $lastFollowup = $contact->followUps()->max('created_at');

        $lastEmail = method_exists($contact, 'emails')
            ? $contact->emails()->max('created_at')
            : null;

        return collect([$lastCall, $lastFollowup, $lastEmail])
            ->filter()
            ->max();
    }

    private function calculateLeadScore(Contact $contact): int
    {
        $score = 0;

        if ($contact->priority === 'high') {
            $score += 30;
        }

        if ($contact->priority === 'medium') {
            $score += 15;
        }

        if ($contact->status === 'interested') {
            $score += 25;
        }

        if ($contact->status === 'hot') {
            $score += 35;
        }

        $callCount = $contact->callLogs()->count();

        if ($callCount >= 3) {
            $score += 20;
        }

        if ($callCount >= 5) {
            $score += 10;
        }

        $lastCall = $contact->callLogs()->latest()->first();

        if ($lastCall) {

            $days = now()->diffInDays($lastCall->created_at);

            if ($days <= 2) {
                $score += 20;
            }

            if ($days > 7) {
                $score -= 15;
            }
        }

        $pendingFollowups = $contact->followUps()
            ->where('status', 'pending')
            ->count();

        if ($pendingFollowups > 0) {
            $score += 10;
        }

        return max(0, min(100, $score));
    }

    private function predictDealProbability(Contact $contact): int
    {
        $base = $contact->ai_score ?? 0;

        $stageBoost = match ($contact->status) {
            'proposal' => 40,
            'hot' => 30,
            'qualified' => 20,
            'interested' => 10,
            default => 0
        };

        $callCount = $contact->callLogs()->count();
        $engagementBoost = min(20, $callCount * 5);

        $probability = $base + $stageBoost + $engagementBoost;

        return min(100, $probability);
    }
    public function generateLinkedInMessage(Contact $contact, string $purpose): string
    {
        $system = "You are a LinkedIn outreach specialist for Nexevo IT company. Write personalized connection requests and messages that get responses.";
        $user = "Write a LinkedIn {$purpose} message to {$contact->name}, {$contact->designation} at {$contact->company} (Industry: {$contact->industry}).\nKeep it under 300 characters for connection request, 1000 for message. Be genuine, not salesy.";

        return $this->chat($system, $user, 300);
    }

    public function generateDailyBriefing(User $user): array
    {
        $today = now()->startOfDay();

        $followupsToday = $user->followUps()
            ->whereDate('scheduled_at', $today)
            ->count();

        $overdueFollowups = $user->followUps()
            ->where('scheduled_at', '<', $today)
            ->where('status', 'pending')
            ->count();

        $callsToday = $user->callLogs()
            ->whereDate('created_at', $today)
            ->count();

        $query = Contact::whereIn('status', ['interested', 'qualified', 'hot', 'proposal']);

        if (!$user->can('admin')) {
            $query->where('assigned_to', $user->id);
        }

        $hotLeads = $query->count();

        $query = Contact::where('created_at', '<=', now()->subDays(7))
            ->whereDoesntHave('callLogs', function ($q) {
                $q->where('created_at', '>=', now()->subDays(7));
            });

        if (!$user->can('admin')) {
            $query->where('assigned_to', $user->id);
        }

        $inactiveLeads = $query->count();
        // Team stats
        // Team performance (last 7 days)

        $topRep = null;
        $topScore = 0;

        $users = User::withCount([
            'callLogs as calls_week' => function ($q) {
                $q->where('created_at', '>=', now()->subDays(7));
            },
            'followUps as followups_week' => function ($q) {
                $q->where('created_at', '>=', now()->subDays(7));
            }
        ])->get();

        foreach ($users as $u) {

            $score = $u->calls_week + $u->followups_week;

            if ($score > $topScore) {
                $topScore = $score;
                $topRep = $u;
            }
        }

        if ($topScore === 0) {
            $topRep = null;
        }

        return [
            'followups_today' => $followupsToday,
            'overdue_followups' => $overdueFollowups,
            'calls_today' => $callsToday,
            'hot_leads' => $hotLeads,
            'inactive_leads' => $inactiveLeads,
            'top_rep' => $topRep?->name,
            'top_rep_score' => $topScore
        ];
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
