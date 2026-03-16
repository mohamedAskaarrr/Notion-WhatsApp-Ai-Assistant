<?php

namespace App\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class GeminiService
{
    private string $apiKey;
    private string $model;

    public function __construct(private readonly ClientInterface $httpClient)
    {
        $this->apiKey = config('services.gemini.api_key', '');
        $this->model  = config('services.gemini.model', 'gemini-1.5-flash');
    }

    /**
     * Parse a text message and return a structured intent for Notion operations.
     */
    public function parseIntent(string $message): array
    {
        $url = $this->buildApiUrl();

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => [
                    'system_instruction' => ['parts' => [['text' => $this->buildSystemPrompt()]]],
                    'contents'           => [['parts' => [['text' => $message]]]],
                    'generationConfig'   => [
                        'temperature'     => 0.2,
                        'maxOutputTokens' => 500,
                    ],
                ],
            ]);

            return $this->parseGeminiResponse($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to call Gemini API: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Transcribe a Telegram voice note and parse the spoken intent in a single Gemini call.
     *
     * @param string $audioBase64 Base64-encoded audio content (OGG/Opus from Telegram)
     * @param string $mimeType    MIME type of the audio file (e.g. "audio/ogg")
     */
    public function parseIntentFromAudio(string $audioBase64, string $mimeType): array
    {
        $url = $this->buildApiUrl();

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => [
                    'system_instruction' => ['parts' => [['text' => $this->buildSystemPrompt()]]],
                    'contents'           => [[
                        'parts' => [
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data'      => $audioBase64,
                                ],
                            ],
                            ['text' => 'Transcribe this voice message and extract the Notion operation intent following the system instructions. Return only JSON.'],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature'     => 0.2,
                        'maxOutputTokens' => 500,
                    ],
                ],
            ]);

            return $this->parseGeminiResponse($response->getBody()->getContents());
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Failed to call Gemini API: ' . $e->getMessage(), 0, $e);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildApiUrl(): string
    {
        return sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $this->model,
            $this->apiKey
        );
    }

    private function buildSystemPrompt(): string
    {
        $today    = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        return <<<PROMPT
You are an AI assistant that parses user messages and extracts intent for Notion operations.
Today's date is {$today}. Tomorrow is {$tomorrow}.

Analyze the user's message and return a JSON object with the following fields:
- action: one of "create_page", "add_to_database", "update_page", "query_database"
- database: one of "tasks" (for tasks, to-dos, action items) or "ideas" (for ideas, notes, thoughts, concepts)
- title: the title or name of the item when creating (string)
- content: the main content or description (string)
- properties: additional properties as key-value pairs (object).
  When action is "query_database", include "filter_preset": one of "today", "tomorrow", "this_week", or "all".
  When creating an item with a due date, include "due_date": "YYYY-MM-DD".

Examples:
- "Add task: fix login bug, priority high" -> {"action": "add_to_database", "database": "tasks", "title": "Fix login bug", "content": "", "properties": {"priority": "high"}}
- "Save idea: build a habit tracker app" -> {"action": "add_to_database", "database": "ideas", "title": "Build a habit tracker app", "content": "", "properties": {}}
- "Show all my tasks" -> {"action": "query_database", "database": "tasks", "title": "", "content": "", "properties": {"filter_preset": "all"}}
- "What are today's tasks?" -> {"action": "query_database", "database": "tasks", "title": "", "content": "", "properties": {"filter_preset": "today"}}
- "List this week's tasks" -> {"action": "query_database", "database": "tasks", "title": "", "content": "", "properties": {"filter_preset": "this_week"}}
- "Show tomorrow's tasks" -> {"action": "query_database", "database": "tasks", "title": "", "content": "", "properties": {"filter_preset": "tomorrow"}}
- "List my ideas" -> {"action": "query_database", "database": "ideas", "title": "", "content": "", "properties": {"filter_preset": "all"}}

Return ONLY valid JSON, no additional text or markdown.
PROMPT;
    }

    /**
     * Decode a raw Gemini API response body and return the normalised intent array.
     */
    private function parseGeminiResponse(string $rawBody): array
    {
        $body    = json_decode($rawBody, true);
        $rawText = $body['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

        // Strip markdown code fences that Gemini sometimes wraps JSON in
        $rawText = preg_replace('/^```(?:json)?\s*/i', '', trim($rawText));
        $rawText = preg_replace('/\s*```$/', '', $rawText);

        $parsed = json_decode(trim($rawText), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response from Gemini: ' . json_last_error_msg());
        }

        return [
            'action'     => $parsed['action']     ?? 'unknown',
            'database'   => $parsed['database']   ?? 'tasks',
            'title'      => $parsed['title']      ?? '',
            'content'    => $parsed['content']    ?? '',
            'properties' => $parsed['properties'] ?? [],
        ];
    }
}
