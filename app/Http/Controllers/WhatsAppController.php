<?php

namespace App\Http\Controllers;

use App\Services\AIService;
use App\Services\NotionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WhatsAppController extends Controller
{
    /**
     * Handle incoming WhatsApp webhook messages from Twilio
     *
     * @param Request $request
     * @param AIService $aiService
     * @param NotionService $notionService
     * @return JsonResponse
     */
    public function handle(Request $request, AIService $aiService, NotionService $notionService): JsonResponse
    {
        try {
            // Extract the message text from the Twilio WhatsApp webhook
            $messageText = $request->input('Body');
            $senderPhone = $request->input('From');

            // Validate that we have the required message data
            if (!$messageText || !$senderPhone) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing message or sender information'
                ], 400);
            }

            // Send the message to AI service for intent parsing
            $command = $aiService->parseMessage($messageText);

            // Validate that we got a valid command from AI
            if (!$command || !isset($command['action'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not understand the request',
                    'response' => 'Sorry, I didn\'t understand that. Please try again with a different command.'
                ]);
            }

            // Execute the command using Notion service
            $result = $notionService->executeCommand($command);

            // Return a confirmation response for WhatsApp
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Action completed successfully',
                'action' => $command['action'],
                'data' => $result['data'] ?? null
            ]);

        } catch (\Exception $e) {
            \Log::error('WhatsApp Webhook Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred processing your request',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
