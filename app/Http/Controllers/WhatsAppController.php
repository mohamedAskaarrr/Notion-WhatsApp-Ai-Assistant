<?php

namespace App\Http\Controllers;

use App\Services\AIService;
use App\Services\NotionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    /**
     * Handle incoming WhatsApp webhook messages from Twilio
     *
     * @param Request $request
     * @param AIService $aiService
     * @param NotionService $notionService
     * @return Response
     */
    public function handleWebhook(Request $request, AIService $aiService, NotionService $notionService): Response
    {
        try {
            // Extract the message text from the Twilio WhatsApp webhook
            $messageText = $request->input('Body');
            $senderPhone = $request->input('From');

            // Log the incoming message
            Log::info('WhatsApp message received', [
                'from' => $senderPhone,
                'message' => $messageText,
                'timestamp' => now()->toIso8601String()
            ]);

            // Validate that we have the required message data
            if (!$messageText || !$senderPhone) {
                Log::warning('WhatsApp webhook missing data', [
                    'has_body' => !empty($messageText),
                    'has_from' => !empty($senderPhone)
                ]);
                
                return $this->getTwiMLResponse("Error: Missing message or sender information");
            }

            // Send the message to AI service for intent parsing
            $command = $aiService->parseMessage($messageText);

            Log::info('AI parsed command', [
                'action' => $command['action'] ?? null,
                'entities' => $command['entities'] ?? null
            ]);

            // Validate that we got a valid command from AI
            if (!$command || !isset($command['action'])) {
                Log::warning('AI could not parse command', [
                    'original_message' => $messageText
                ]);
                
                return $this->getTwiMLResponse("Sorry, I didn't understand that. Please try again with a different command.");
            }

            // Execute the command using Notion service
            $result = $notionService->executeCommand($command);

            Log::info('Notion command executed', [
                'action' => $command['action'],
                'success' => $result['success'] ?? true,
                'result_message' => $result['message'] ?? null
            ]);

            // Return a TwiML response for WhatsApp
            $replyMessage = "Message received: {$messageText}";
            if (isset($result['message'])) {
                $replyMessage = $result['message'];
            }

            return $this->getTwiMLResponse($replyMessage);

        } catch (\Exception $e) {
            Log::error('WhatsApp Webhook Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()->toIso8601String()
            ]);

            return $this->getTwiMLResponse("An error occurred processing your request. Please try again later.");
        }
    }

    /**
     * Generate a TwiML response for Twilio WhatsApp
     *
     * @param string $message
     * @return Response
     */
    private function getTwiMLResponse(string $message): Response
    {
        $twiml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Message>{$message}</Message>
</Response>
XML;

        return response($twiml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Legacy handle method for backward compatibility
     *
     * @param Request $request
     * @param AIService $aiService
     * @param NotionService $notionService
     * @return Response
     */
    public function handle(Request $request, AIService $aiService, NotionService $notionService): Response
    {
        return $this->handleWebhook($request, $aiService, $notionService);
    }
}

