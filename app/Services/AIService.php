<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class AIService
{
    /**
     * OpenAI API endpoint
     */
    private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * Parse a natural language message and convert it to a structured command
     *
     * @param string $message The user's natural language message
     * @return array|null Structured command with action and parameters
     */
    public function parseMessage(string $message): ?array
    {
        try {
            $apiKey = config('services.openai.key');

            if (!$apiKey) {
                throw new Exception('OpenAI API key not configured');
            }

            // Create a system prompt that instructs the AI to parse WhatsApp commands
            $systemPrompt = $this->buildSystemPrompt();

            // Make request to OpenAI
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->post(self::OPENAI_ENDPOINT, [
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt
                    ],
                    [
                        'role' => 'user',
                        'content' => $message
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 500
            ]);

            if (!$response->successful()) {
                Log::error('OpenAI API Error', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);
                throw new Exception('Failed to get response from OpenAI');
            }

            $responseData = $response->json();

            // Extract the AI's response
            $content = $responseData['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                throw new Exception('No content in OpenAI response');
            }

            // Parse the JSON response from the AI
            $command = json_decode($content, true);

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
