<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\HealthController;

Route::get('/', function () {
    return view('welcome');
});

// Health check endpoint - no middleware
Route::get('/health', [HealthController::class, 'check']);

// WhatsApp webhook endpoint from Twilio
Route::post('/webhook/whatsapp', [WhatsAppController::class, 'handle'])
    ->middleware('throttle:60,1');
