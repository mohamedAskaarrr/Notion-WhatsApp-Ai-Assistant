<?php

namespace App\Services;

use GuzzleHttp\ClientInterface;

class TelegramBotService
{
    private string $botToken;

    public function __construct(private readonly ClientInterface $httpClient)
    {
        $this->botToken = config('services.telegram.bot_token', '');
    }

    public function sendMessage(int|string $chatId, string $text): void
    {
        if (empty($this->botToken)) {
            return;
        }

        try {
            $this->httpClient->request(
                'POST',
                "https://api.telegram.org/bot{$this->botToken}/sendMessage",
                ['json' => ['chat_id' => $chatId, 'text' => $text]]
            );
        } catch (\Throwable) {
            // Non-critical: a failed reply should not crash the webhook handler
        }
    }

    /**
     * Download a voice (or audio) file from Telegram and return it as base64.
     *
     * Telegram voice notes are in OGG/Opus format.  We first call getFile to
     * resolve the file path, then download the binary content.
     *
     * @return array{base64: string, mime_type: string}
     */
    public function downloadVoiceFile(string $fileId): array
    {
        // Step 1 – resolve the file path on Telegram's CDN
        $getFileResp = $this->httpClient->request(
            'GET',
            "https://api.telegram.org/bot{$this->botToken}/getFile",
            ['query' => ['file_id' => $fileId]]
        );

        $fileInfo = json_decode($getFileResp->getBody()->getContents(), true);
        $filePath = $fileInfo['result']['file_path'] ?? null;

        if (empty($filePath)) {
            throw new \RuntimeException('Could not retrieve file path from Telegram getFile API');
        }

        // Step 2 – download the raw binary
        $downloadResp = $this->httpClient->request(
            'GET',
            "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}"
        );

        $content  = $downloadResp->getBody()->getContents();
        $mimeType = $downloadResp->getHeaderLine('Content-Type') ?: 'audio/ogg';

        // Strip any charset / boundary suffix (e.g. "audio/ogg; codecs=opus")
        $mimeType = trim(explode(';', $mimeType)[0]);

        // Telegram voice notes that don't advertise a type should be treated as OGG
        if (empty($mimeType) || $mimeType === 'application/octet-stream') {
            $mimeType = 'audio/ogg';
        }

        return [
            'base64'    => base64_encode($content),
            'mime_type' => $mimeType,
        ];
    }
}
