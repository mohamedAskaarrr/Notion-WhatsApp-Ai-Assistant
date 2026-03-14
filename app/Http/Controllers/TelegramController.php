<?php

namespace App\Http\Controllers;

use App\Services\AIService;
use App\Services\NotionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    /**
     * Telegram Bot API base URL
     */
    private const TELEGRAM_API = 'https://api.telegram.org/bot';

    /**
     * Handle incoming Telegram webhook messages
     *
     * @param Request $request
     * @param AIService $aiService
     * @param NotionService $notionService
     * @return Response
     */
    public function handleWebhook(Request $request, AIService $aiService, NotionService $notionService): Response
    {
        try {
            $payload = $request->all();

            // Extract message text and chat ID from the Telegram update payload
            $messageText = $payload['message']['text'] ?? null;
            $chatId      = $payload['message']['chat']['id'] ?? null;

            // Log the incoming Telegram message
            Log::info('Telegram message received', [
                'chat_id'   => $chatId,
                'message'   => $messageText,
                'timestamp' => now()->toIso8601String(),
            ]);

            // Validate that we have the required fields
            if (!$messageText || !$chatId) {
                Log::warning('Telegram webhook missing required fields', [
                    'has_text'    => !empty($messageText),
                    'has_chat_id' => !empty($chatId),
                    'payload'     => $payload,
                ]);

                // Always return 200 so Telegram stops retrying
                return response('OK', 200);
            }

            // Parse the natural-language message with the AI service
            $command = $aiService->parseMessage($messageText);

            Log::info('Telegram AI parsed command', [
                'chat_id' => $chatId,
                'action'  => $command['action'] ?? null,
                'command' => $command,
            ]);

            // Handle unknown or unparseable commands gracefully
            if (!$command || !isset($command['action']) || $command['action'] === 'unknown') {
                $this->sendTelegramMessage(
                    $chatId,
                    "Sorry, I didn't understand that. Try something like:\n" .
                    "• \"Add task Buy groceries tomorrow\"\n" .
                    "• \"Show my tasks\"\n" .
                    "• \"Save idea Build a habit tracker app\""
                );

                return response('OK', 200);
            }

            // Execute the command against Notion
            $result = $notionService->executeCommand($command);

            Log::info('Telegram Notion command executed', [
                'chat_id' => $chatId,
                'action'  => $command['action'],
                'success' => $result['success'] ?? true,
                'message' => $result['message'] ?? null,
            ]);

            // Send the result back to the Telegram user
            $replyText = $result['message'] ?? 'Done! Your request has been processed.';
            $this->sendTelegramMessage($chatId, $replyText);

        } catch (\Exception $e) {
            Log::error('Telegram Webhook Error', [
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
                'timestamp' => now()->toIso8601String(),
            ]);

            // Attempt a best-effort error reply if we have a chat ID
            $chatId = $request->input('message.chat.id');
            if ($chatId) {
                $this->sendTelegramMessage($chatId, 'An error occurred while processing your request. Please try again.');
            }
        }

        // Always return HTTP 200 so Telegram does not retry the update
        return response('OK', 200);
    }

    /**
     * Send a text message to a Telegram chat via the Bot API
     *
     * @param int|string $chatId  The recipient chat / user ID
     * @param string     $text    The message to send
     * @return void
     */
    private function sendTelegramMessage(int|string $chatId, string $text): void
    {
        $botToken = config('services.telegram.bot_token') ?? env('TELEGRAM_BOT_TOKEN');

        if (!$botToken) {
            Log::error('Telegram bot token not configured');
            return;
        }

        $url = self::TELEGRAM_API . $botToken . '/sendMessage';

        $response = Http::post($url, [
            'chat_id' => $chatId,
            'text'    => $text,
        ]);

        if (!$response->successful()) {
            Log::error('Failed to send Telegram message', [
                'chat_id'  => $chatId,
                'status'   => $response->status(),
                'response' => $response->json(),
            ]);
        } else {
            Log::info('Telegram message sent', [
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
        }
    }
}
