<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Health check endpoint
     *
     * @return JsonResponse
     */
    public function check(): JsonResponse
    {
        $health = [
            'status' => 'OK',
            'timestamp' => now()->toIso8601String(),
            'environment' => config('app.env'),
            'services' => $this->checkServices()
        ];

        return response()->json($health);
    }

    /**
     * Check if all required services are configured
     *
     * @return array
     */
    private function checkServices(): array
    {
        return [
            'twilio' => [
                'configured' => !empty(config('services.twilio.account_sid')),
                'has_account_sid' => !empty(config('services.twilio.account_sid')),
                'has_auth_token' => !empty(config('services.twilio.auth_token'))
            ],
            'openai' => [
                'configured' => !empty(config('services.openai.key')),
                'has_api_key' => !empty(config('services.openai.key'))
            ],
            'notion' => [
                'configured' => !empty(config('services.notion.token')),
                'has_token' => !empty(config('services.notion.token')),
                'has_database_tasks' => !empty(config('services.notion.database_tasks')),
                'has_database_ideas' => !empty(config('services.notion.database_ideas'))
            ]
        ];
    }
}
