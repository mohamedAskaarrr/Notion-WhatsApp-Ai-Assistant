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
     * Keywords that trigger listing all tasks (exact match after lowercasing & trimming)
     */
    private const LIST_TASK_PHRASES = [
        'list tasks', 'list my tasks', 'show tasks', 'show my tasks',
        'get tasks', 'tasks', 'my tasks', 'all tasks', 'view tasks',
    ];

    /**
     * Prefixes that trigger creating a task (message must start with one of these)
     */
    private const CREATE_TASK_PREFIXES = [
        'create task', 'add task', 'new task', 'task:', 'task ',
    ];

    /**
     * Prefixes that trigger saving an idea
     */
    private const SAVE_IDEA_PREFIXES = [
        'save idea', 'add idea', 'create idea', 'new idea', 'idea:', 'idea ',
    ];

    public function handleWebhook(Request $request, AIService $aiService, NotionService $notionService): Response
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];
            Log::info('Telegram payload', $data);

            $message_text = trim((string) ($data['message']['text'] ?? ''));
            $chat_id = $data['message']['chat']['id']
                ?? $data['message']['from']['id']
                ?? $data['chat_join_request']['chat']['id']
                ?? null;

            Log::info('Telegram incoming', ['chat_id' => $chat_id, 'text' => $message_text]);

            if (!$chat_id) {
                return response('ok', 200);
            }

            $botToken = config('services.telegram.bot_token');

            // Check for voice note
            $voiceInfo = $data['message']['voice'] ?? null;
            $audioData = null;

            if ($voiceInfo && isset($voiceInfo['file_id']) && $botToken) {
                $fileResponse = Http::timeout(10)->get(self::TELEGRAM_API . $botToken . '/getFile', [
                    'file_id' => $voiceInfo['file_id']
                ]);
                
                if ($fileResponse->successful() && isset($fileResponse->json()['result']['file_path'])) {
                    $filePath = $fileResponse->json()['result']['file_path'];
                    $downloadUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
                    
                    $audioResponse = Http::timeout(20)->get($downloadUrl);
                    if ($audioResponse->successful()) {
                        $audioData = base64_encode($audioResponse->body());
                        Log::info('Downloaded Telegram voice note', ['size' => strlen($audioData)]);
                    } else {
                        Log::error('Failed to download Telegram voice note', ['status' => $audioResponse->status()]);
                    }
                } else {
                    Log::error('Failed to get Telegram file info', ['response' => $fileResponse->json()]);
                }
            }

            $replyText = "👋 Hi! I can help you manage your Notion workspace.\n\n"
                . "Try:\n"
                . "• \"create task buy groceries\"\n"
                . "• \"create task finish report tomorrow\"\n"
                . "• \"list tasks\"\n"
                . "• \"save idea build a habit tracker\"\n"
                . "• Or just send me a 🎤 Voice Note!";

            if ($message_text !== '' || $audioData !== null) {
                $lower          = strtolower(trim($message_text));
                $matched_prefix = '';

                // ── LIST TASKS ────────────────────────────────────────────
                if ($message_text !== '' && in_array($lower, self::LIST_TASK_PHRASES, true)) {
                    $result     = $notionService->listTasks();
                    $replyText  = $result['message'] ?? '❌ Could not fetch tasks.';
                    Log::info('Telegram list tasks', ['chat_id' => $chat_id, 'success' => $result['success'] ?? false]);

                // ── CREATE TASK ───────────────────────────────────────────
                } elseif ($message_text !== '' && $this->matchesPrefix($lower, self::CREATE_TASK_PREFIXES, $matched_prefix)) {
                    $rest = trim(substr($message_text, strlen($matched_prefix)));

                    if ($rest === '') {
                        $replyText = '✏️ Please include a task name. Example: "create task buy groceries"';
                    } else {
                        // Check if user appended a due date after the task title
                        // e.g. "create task buy groceries tomorrow"
                        // We pass the full rest to createTask; it will resolve the due date via AI if needed
                        $result    = $notionService->createTask($rest);
                        $replyText = ($result['success'] ?? false)
                            ? "✅ Task added: *{$rest}*"
                            : ($result['message'] ?? '❌ Failed to create task.');
                        Log::info('Telegram create task', ['chat_id' => $chat_id, 'title' => $rest, 'result' => $result]);
                    }

                // ── SAVE IDEA ─────────────────────────────────────────────
                } elseif ($message_text !== '' && $this->matchesPrefix($lower, self::SAVE_IDEA_PREFIXES, $matched_prefix)) {
                    $content = trim(substr($message_text, strlen($matched_prefix)));

                    if ($content === '') {
                        $replyText = '✏️ Please include idea content. Example: "save idea build habit tracker"';
                    } else {
                        $result    = $notionService->createIdea($content);
                        $replyText = ($result['success'] ?? false)
                            ? "💡 Idea saved: *{$content}*"
                            : ($result['message'] ?? '❌ Failed to save idea.');
                        Log::info('Telegram save idea', ['chat_id' => $chat_id, 'content' => $content, 'result' => $result]);
                    }

                // ── AI FALLBACK (TEXT OR AUDIO) ───────────────────────────
                } else {
                    if ($audioData) {
                        // If it's pure audio, let the user know we're processing it
                        // Optional: could send a chat action "record_voice" here
                    }
                    $command = $aiService->parseMessage($message_text, $audioData, 'audio/ogg');
                    Log::info('Telegram AI command', ['chat_id' => $chat_id, 'command' => $command]);

                    if ($command && isset($command['action']) && $command['action'] !== 'unknown') {
                        $result    = $notionService->executeCommand($command);
                        $replyText = $result['message'] ?? "✅ Done!";
                        Log::info('Telegram executeCommand result', ['chat_id' => $chat_id, 'result' => $result]);
                    } else {
                        $replyText = "🤔 I didn't understand that.\n\n"
                            . "Try:\n"
                            . "• \"create task buy groceries\"\n"
                            . "• \"create task finish report tomorrow\"\n"
                            . "• \"list tasks\"\n"
                            . "• \"save idea build habit tracker\"\n"
                            . "• Or just say it in a voice note!";
                    }
                }
            }

            // ── SEND REPLY ────────────────────────────────────────────────
            if (!$botToken) {
                Log::error('Telegram bot token not configured');
                return response('ok', 200);
            }

            $sendResponse = Http::timeout(15)->post(
                self::TELEGRAM_API . $botToken . '/sendMessage',
                [
                    'chat_id'    => $chat_id,
                    'text'       => $replyText,
                    'parse_mode' => 'Markdown',
                ]
            );

            Log::info('Telegram send result', [
                'chat_id' => $chat_id,
                'status'  => $sendResponse->status(),
                'body'    => $sendResponse->body(),
            ]);

        } catch (\Exception $e) {
            Log::error('Telegram Webhook Error', [
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        return response('ok', 200);
    }

    /**
     * Check if $haystack starts with any of $prefixes and set $matched to the matched prefix.
     */
    private function matchesPrefix(string $haystack, array $prefixes, string &$matched): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($haystack, $prefix)) {
                $matched = $prefix;
                return true;
            }
        }
        return false;
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
