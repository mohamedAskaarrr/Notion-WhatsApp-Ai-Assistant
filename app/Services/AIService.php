<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class AIService
{
    /**
     * Google Gemini API base URL (model is appended dynamically)
     */
    private const GEMINI_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Gemini models to try in order. The first one that responds successfully wins.
     */
    private const GEMINI_MODELS = [
        'gemini-1.5-flash',
        'gemini-2.0-flash',
        'gemini-pro',
    ];

    /**
     * Parse a natural language message and convert it to a structured command
     *
     * @param string|null $message The user's natural language message
     * @param string|null $audioData Base64 encoded audio data
     * @param string $mimeType Mime type of the audio data
     * @return array|null Structured command with action and parameters
     */
    public function parseMessage(?string $message = null, ?string $audioData = null, string $mimeType = 'audio/ogg'): ?array
    {
        try {
            $apiKey = config('services.gemini.key') ?? env('GEMINI_API_KEY');

            if (!$apiKey) {
                throw new Exception('Gemini API key not configured');
            }

            // Create a system prompt that instructs the AI to parse WhatsApp commands
            $systemPrompt = $this->buildSystemPrompt();

            // Build the full prompt
            $promptText = $systemPrompt;
            if (!empty($message)) {
                $promptText .= "\n\nUser message: " . $message;
            } elseif ($audioData) {
                $promptText .= "\n\nPlease listen to the attached audio, transcribe it, and process it as the user message.";
            }

            $parts = [
                ['text' => $promptText]
            ];

            if ($audioData) {
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $mimeType,
                        'data' => $audioData
                    ]
                ];
            }

            // Try each model in order until one succeeds
            $content = null;
            foreach (self::GEMINI_MODELS as $model) {
                $url = self::GEMINI_BASE . $model . ':generateContent?key=' . $apiKey;

                Log::info('Calling Gemini API', ['model' => $model, 'has_audio' => !is_null($audioData)]);

                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post($url, [
                    'contents' => [
                        [
                            'parts' => $parts
                        ]
                    ]
                ]);

                Log::info('Gemini API Response', [
                    'model'      => $model,
                    'status'     => $response->status(),
                    'successful' => $response->successful(),
                ]);

                if ($response->successful()) {
                    $responseData = $response->json();
                    $content = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;
                    if ($content) {
                        break; // found a working model
                    }
                } else {
                    Log::warning('Gemini model failed, trying next', [
                        'model'  => $model,
                        'status' => $response->status(),
                        'error'  => $response->json(),
                    ]);
                }
            }

            if (!$content) {
                throw new Exception('All Gemini models failed to return a response');
            }

            Log::info('Gemini Content', ['content' => $content]);

            // Parse the JSON response from the AI (Gemini may wrap JSON in markdown fences)
            $cleanContent = trim($content);
            $cleanContent = preg_replace('/^```(?:json)?\s*/i', '', $cleanContent);
            $cleanContent = preg_replace('/\s*```$/', '', $cleanContent);

            $command = json_decode($cleanContent, true);

            if (!$command && preg_match('/\{.*\}/s', $cleanContent, $matches)) {
                $command = json_decode($matches[0], true);
            }

            if (!$command) {
                Log::warning('Failed to parse AI response as JSON', [
                    'content' => $content
                ]);
                return null;
            }

            return $command;

        } catch (Exception $e) {
            Log::error('AIService Error', [
                'message' => $e->getMessage(),
                'user_input' => $message ?? 'unknown'
            ]);
            return null;
        }
    }

    /**
     * Build the system prompt for the AI
     *
     * @return string
     */
    private function buildSystemPrompt(): string
    {
        $prompt = "You are an AI assistant that helps users manage their Notion workspace through natural language commands sent via WhatsApp.\n\n";
        $prompt .= "Your job is to parse the user's message and convert it into a structured JSON command.\n\n";
        $prompt .= "Supported actions:\n";
        $prompt .= "1. create_task - Create a new task in Notion (Parameters: title required, due optional)\n";
        $prompt .= "2. list_tasks - List all tasks from Notion (Parameters: none)\n";
        $prompt .= "3. save_idea - Save an idea/note to Notion (Parameters: content required, category optional)\n\n";
        $prompt .= "Rules:\n";
        $prompt .= "- Always respond with ONLY valid JSON\n";
        $prompt .= "- If the message doesn't match any known action, respond with: {\"action\": \"unknown\", \"reason\": \"message not understood\"}\n";
        $prompt .= "- Extract dates in natural language (e.g., 'tomorrow', 'next Monday') and normalize them\n";
        $prompt .= "- Be smart about inferring parameters from context\n\n";
        $prompt .= "Examples:\n";
        $prompt .= "User: 'Add task finish routing assignment tomorrow'\n";
        $prompt .= "Response: {\"action\": \"create_task\", \"title\": \"finish routing assignment\", \"due\": \"tomorrow\"}\n\n";
        $prompt .= "User: 'Create task buy groceries'\n";
        $prompt .= "Response: {\"action\": \"create_task\", \"title\": \"buy groceries\", \"due\": null}\n\n";
        $prompt .= "User: 'Save idea for new app feature: voice notes'\n";
        $prompt .= "Response: {\"action\": \"save_idea\", \"content\": \"voice notes\", \"category\": \"app feature\"}\n\n";
        $prompt .= "User: 'Show my tasks'\n";
        $prompt .= "Response: {\"action\": \"list_tasks\"}\n\n";
        $prompt .= "User: 'hello'\n";
        $prompt .= "Response: {\"action\": \"unknown\", \"reason\": \"message not understood\"}\n\n";
        $prompt .= "Respond ONLY with JSON, nothing else.";

        return $prompt;
    }
}
