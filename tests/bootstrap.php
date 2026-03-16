<?php

require_once __DIR__.'/../vendor/autoload.php';

// Create a minimal Laravel application for testing
$app = new \Illuminate\Foundation\Application(
    dirname(__DIR__)
);

// Bind config repository with test values
$app->singleton('config', function () {
    return new \Illuminate\Config\Repository([
        'app' => [
            'name' => 'NotionTelegramAssistant',
            'env' => 'testing',
            'key' => 'base64:MMo75gYMsGnq0jOy0RMXfVHQSgmLV9g9hL1qkTVe9Zs=',
            'debug' => true,
            'url' => 'http://localhost',
            'timezone' => 'UTC',
            'locale' => 'en',
            'fallback_locale' => 'en',
            'cipher' => 'AES-256-CBC',
        ],
        'services' => [
            'telegram' => ['bot_token' => '', 'secret_token' => ''],
            'gemini'   => ['api_key' => '', 'model' => 'gemini-1.5-flash'],
            'notion'   => [
                'api_key'           => '',
                'tasks_database_id' => '',
                'ideas_database_id' => '',
                'version'           => '2022-06-28',
                'date_property'     => 'Date',
            ],
        ],
    ]);
});

// Bind response factory
$app->singleton(
    \Illuminate\Contracts\Http\Kernel::class,
    \Illuminate\Foundation\Http\Kernel::class
);

// Set as the global app instance
\Illuminate\Container\Container::setInstance($app);
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
