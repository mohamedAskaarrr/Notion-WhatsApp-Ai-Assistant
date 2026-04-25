<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TelegramSetupWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:setup-webhook
                            {url? : Public HTTPS URL of your server (e.g. https://xxxx.ngrok-free.app)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register (or check) the Telegram bot webhook URL';

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');

        if (!$token) {
            $this->error('TELEGRAM_BOT_TOKEN is not set in .env');
            return self::FAILURE;
        }

        $baseApi = "https://api.telegram.org/bot{$token}";

        // ── No URL argument → just show current webhook info ─────────────
        $url = $this->argument('url');
        if (!$url) {
            $info = Http::get("{$baseApi}/getWebhookInfo")->json();
            $this->info('Current webhook info:');
            $this->line(json_encode($info['result'] ?? $info, JSON_PRETTY_PRINT));
            $this->newLine();
            $this->comment('To set a new webhook, run:');
            $this->comment('  php artisan telegram:setup-webhook https://YOUR-NGROK-URL');
            return self::SUCCESS;
        }

        // ── Set webhook ───────────────────────────────────────────────────
        $webhookUrl = rtrim($url, '/') . '/api/telegram/webhook';

        $this->info("Setting webhook to: {$webhookUrl}");

        $response = Http::post("{$baseApi}/setWebhook", [
            'url'             => $webhookUrl,
            'drop_pending_updates' => true,
        ]);

        $result = $response->json();

        if ($response->successful() && ($result['ok'] ?? false)) {
            $this->info('✅ Webhook set successfully!');
            $this->line('Description: ' . ($result['description'] ?? '—'));
        } else {
            $this->error('❌ Failed to set webhook:');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            return self::FAILURE;
        }

        // Verify
        $info = Http::get("{$baseApi}/getWebhookInfo")->json();
        $this->newLine();
        $this->info('Verification:');
        $this->line('  URL      : ' . ($info['result']['url'] ?? '—'));
        $this->line('  Pending  : ' . ($info['result']['pending_update_count'] ?? 0));
        $this->line('  Last err : ' . ($info['result']['last_error_message'] ?? 'none'));

        return self::SUCCESS;
    }
}
