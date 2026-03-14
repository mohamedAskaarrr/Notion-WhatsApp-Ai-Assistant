<?php

namespace App\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class OpenAIService
{
    private string $apiKey;
    private string $model;

    public function __construct(private readonly ClientInterface $httpClient)
    {
        $this->apiKey = config('services.openai.api_key', '');
        $this->model = config('services.openai.model', 'gpt-4');
    }

    public function parseIntent(string $message): array
    {
        $systemPrompt = <<<'PROMPT'
You are an AI assistant that parses user messages and extracts intent for Notion operations.
Analyze the user's message and return a JSON object with the following fields:
- action: one of "create_page", "add_to_database", "update_page", "query_database"
- title: the title or name of the item (string)
- content: the main content or description (string)
- properties: additional properties as key-value pairs (object)

Examples:
- "Create a note about my meeting with John" -> {"action": "create_page", "title": "Meeting with John", "content": "Meeting notes", "properties": {}}
- "Add a task: Fix the login bug, priority high" -> {"action": "add_to_database", "title": "Fix the login bug", "content": "", "properties": {"priority": "high"}}
- "Find all tasks with status done" -> {"action": "query_database", "title": "", "content": "", "properties": {"filter": {"property": "Status", "select": {"equals": "Done"}}}}

Return ONLY valid JSON, no additional text.
PROMPT;

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $message],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 500,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $content = $body['choices'][0]['message']['content'] ?? '{}';

            $parsed = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from OpenAI: ' . json_last_error_msg());
            }

            return [
                'action' => $parsed['action'] ?? 'unknown',
                'title' => $parsed['title'] ?? '',
                'content' => $parsed['content'] ?? '',
                'properties' => $parsed['properties'] ?? [],
            ];
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to call OpenAI API: ' . $e->getMessage(), 0, $e);
        }
    }
}
