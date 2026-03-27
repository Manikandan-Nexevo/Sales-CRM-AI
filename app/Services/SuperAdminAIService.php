<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\Company;
use App\Models\User;

class SuperAdminAIService
{
    private string $model;

    public function __construct()
    {
        $this->model = config('services.groq.model');
    }
    private string $apiUrl = 'https://api.groq.com/openai/v1/chat/completions';

    public function ask(string $query)
    {
        $context = $this->buildContext();

        $systemPrompt = "
You are a Super Admin AI.

You MUST return JSON only.

Format:
{
  \"type\": \"action\" | \"query\" | \"text\",
  \"action\": \"create_company\" | \"delete_company\" | \"list_companies\" | null,
  \"payload\": { ... },
  \"message\": \"human readable response\"
}

Rules:
- If user asks to create/delete/update → type = action
- If user asks data → type = query
- Otherwise → type = text

Data:
$context
";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.groq.key'),
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl, [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $query],
            ],
        ]);

        if (!$response->successful()) {
            return [
                'type' => 'text',
                'message' => 'AI error: ' . $response->body()
            ];
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? '';

        // Try decode JSON
        $decoded = json_decode($content, true);

        return $decoded ?: [
            'type' => 'text',
            'message' => $content
        ];
    }

    private function buildContext(): string
    {
        $companies = Company::select('id', 'name', 'status')->get();
        $users = User::select('id', 'name', 'company_id')->get();

        return json_encode([
            'companies' => $companies,
            'users_count' => $users->count(),
        ]);
    }
}
