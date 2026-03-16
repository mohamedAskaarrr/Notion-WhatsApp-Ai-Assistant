<?php

namespace App\Http\Controllers;

use App\Services\GeminiService;
use App\Services\NotionService;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebhookController extends Controller
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly NotionService $notionService,
        private readonly TelegramBotService $telegramBot,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $chatId = $request->input('message.chat.id');
        $text   = $request->input('message.text', '');
        $voice  = $request->input('message.voice');

        if (empty($chatId)) {
            return new JsonResponse(['ok' => true]);
        }

        // Ignore updates that carry neither text nor a voice note
        if (empty($text) && empty($voice)) {
            return new JsonResponse(['ok' => true]);
        }

        try {
            $parsedIntent = !empty($voice)
                ? $this->handleVoiceMessage($voice)
                : $this->geminiService->parseIntent($text);

            $result       = $this->executeNotionAction($parsedIntent);
            $replyMessage = $this->buildReplyMessage($parsedIntent, $result);
        } catch (\Exception $e) {
            $replyMessage = 'Sorry, I encountered an error processing your request. Please try again.';
        }

        $this->telegramBot->sendMessage($chatId, $replyMessage);

        return new JsonResponse(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // Voice handling
    // -------------------------------------------------------------------------

    private function handleVoiceMessage(array $voice): array
    {
        $fileId   = $voice['file_id']   ?? '';
        $mimeType = $voice['mime_type'] ?? 'audio/ogg';

        $fileData = $this->telegramBot->downloadVoiceFile($fileId);

        return $this->geminiService->parseIntentFromAudio(
            $fileData['base64'],
            $fileData['mime_type'] ?: $mimeType
        );
    }

    // -------------------------------------------------------------------------
    // Notion action dispatch
    // -------------------------------------------------------------------------

    private function executeNotionAction(array $parsedIntent): array
    {
        $database     = $parsedIntent['database']                        ?? 'tasks';
        $filterPreset = $parsedIntent['properties']['filter_preset'] ?? 'all';

        return match ($parsedIntent['action'] ?? 'unknown') {
            'create_page'     => $this->notionService->createPage($parsedIntent, $database),
            'add_to_database' => $this->notionService->addToDatabase($parsedIntent, $database),
            'update_page'     => $this->notionService->updatePage(
                $parsedIntent['properties']['page_id'] ?? '',
                $parsedIntent['properties'] ?? []
            ),
            'query_database'  => $this->notionService->queryDatabase(
                $parsedIntent['properties']['filter'] ?? [],
                $database,
                $filterPreset
            ),
            default => ['status' => 'unknown_action'],
        };
    }

    // -------------------------------------------------------------------------
    // Reply formatting
    // -------------------------------------------------------------------------

    private function buildReplyMessage(array $parsedIntent, array $result): string
    {
        $action   = $parsedIntent['action']   ?? 'unknown';
        $title    = $parsedIntent['title']    ?? 'Untitled';
        $database = $parsedIntent['database'] ?? 'tasks';
        $dbLabel  = ucfirst($database);

        return match ($action) {
            'create_page'     => "✅ Page '{$title}' created in Notion successfully.",
            'add_to_database' => "✅ Entry '{$title}' added to your {$dbLabel} database.",
            'update_page'     => "✅ Notion page updated successfully.",
            'query_database'  => $this->formatQueryResults($result, $database),
            default           => "⚠️ I couldn't understand the action. Please try rephrasing your request.",
        };
    }

    /**
     * Format Notion query results as a human-readable numbered list.
     * Limits the displayed items to 20 to stay within Telegram message limits.
     */
    private function formatQueryResults(array $result, string $database): string
    {
        $allItems = $result['results'] ?? [];
        $total    = count($allItems);
        $items    = array_slice($allItems, 0, 20);
        $dbLabel  = ucfirst($database);

        if ($total === 0) {
            return "📋 No items found in your {$dbLabel} database.";
        }

        $lines = ["📋 {$dbLabel} ({$total} found):"];

        foreach ($items as $index => $page) {
            $lines[] = ($index + 1) . '. ' . $this->extractPageTitle($page);
        }

        if ($total > 20) {
            $lines[] = '... and ' . ($total - 20) . ' more';
        }

        return implode("\n", $lines);
    }

    /**
     * Pull the plain-text title out of a Notion page object.
     * Notion titles are stored in whichever property has type "title".
     */
    private function extractPageTitle(array $page): string
    {
        foreach ($page['properties'] ?? [] as $prop) {
            if (isset($prop['type']) && $prop['type'] === 'title') {
                return $prop['title'][0]['plain_text'] ?? 'Untitled';
            }
        }

        return 'Untitled';
    }
}
