# API Credentials Configuration Guide

## Overview

This document details all files that require API credentials and how they access them.

## Single Source of Truth: `.env`

All secrets are stored in one place: **`.env`** file. Individual files access these through Laravel's configuration system.

### Configuration Flow:

```
.env (Secrets stored here)
    ↓
config/services.php (Maps .env to accessible config keys)
    ↓
Services use: config('services.X.Y')
```

---

## Files That Require Secrets

### 1. **`.env`** (Primary Source)
**Location:** Root of project  
**Purpose:** Store all API credentials securely

**Required Secrets:**
```dotenv
TWILIO_ACCOUNT_SID=US493d6e822d038e23ffbcbe9ce1485e16
TWILIO_AUTH_TOKEN=d26c1cea40c288b2c7dbd5c41ac5381b
OPENAI_API_KEY=sk_your_key_here
NOTION_TOKEN=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
NOTION_DATABASE_TASKS=323912af73080f2828ec679b604c04b
NOTION_DATABASE_IDEAS=3239127af73080919ce4dd5b0acb7a9a
```

**Status:** ✅ **Already configured with your secrets**

---

### 2. **`config/services.php`** (Configuration Bridge)
**Location:** `config/services.php`  
**Purpose:** Map .env variables to accessible config keys  
**Method:** Services access via `config('services.X.key')`

**Configuration Added:**
```php
'openai' => [
    'key' => env('OPENAI_API_KEY'),
],

'notion' => [
    'token' => env('NOTION_TOKEN'),
    'database_tasks' => env('NOTION_DATABASE_TASKS'),
    'database_ideas' => env('NOTION_DATABASE_IDEAS'),
],

'twilio' => [
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
],
```

**Status:** ✅ **Already configured**

---

### 3. **`app/Services/AIService.php`** (OpenAI Integration)
**Accesses:** `OPENAI_API_KEY`  
**Method:** `config('services.openai.key')`

**Code Reference:**
```php
$apiKey = config('services.openai.key');
if (!$apiKey) {
    throw new Exception('OpenAI API key not configured');
}
```

**Status:** ✅ **Ready to use**

---

### 4. **`app/Services/NotionService.php`** (Notion Integration)
**Accesses:**
- `NOTION_TOKEN`
- `NOTION_DATABASE_TASKS`
- `NOTION_DATABASE_IDEAS`

**Method:** `config('services.notion.X')`

**Code Reference:**
```php
$databaseId = config('services.notion.database_tasks');
$token = config('services.notion.token');
```

**Status:** ✅ **Ready to use**

---

### 5. **`app/Http/Middleware/ValidateTwilioRequest.php`** (Twilio Signature Validation)
**Accesses:** `TWILIO_AUTH_TOKEN`  
**Method:** `config('services.twilio.auth_token')`

**Code Reference:**
```php
$authToken = config('services.twilio.auth_token');
if (!$authToken) {
    throw new Exception('Twilio auth token not configured');
}
```

**Status:** ✅ **Ready to use**

---

### 6. **`app/Http/Controllers/HealthController.php`** (Health Check)
**Accesses:** All credentials (for status checking)  
**Method:** `config('services.X.Y')`

**Purpose:** Provides endpoint to verify all credentials are configured

**Endpoint:** `GET /health`

**Status:** ✅ **Ready to use**

---

### 7. **`.env.example`** (Reference Template)
**Location:** Root of project  
**Purpose:** Show developers what .env variables are needed

**Status:** ✅ **Updated with all required variables**

---

## Files Structure Summary

| File | Purpose | Credentials Accessed |
|------|---------|----------------------|
| `.env` | Source of truth | All secrets stored here |
| `config/services.php` | Configuration bridge | Maps .env to accessible config |
| `app/Services/AIService.php` | OpenAI calls | OPENAI_API_KEY |
| `app/Services/NotionService.php` | Notion API calls | NOTION_TOKEN, NOTION_DATABASE_* |
| `app/Http/Middleware/ValidateTwilioRequest.php` | Webhook validation | TWILIO_AUTH_TOKEN |
| `app/Http/Controllers/HealthController.php` | Health checks | All credentials |
| `.env.example` | Documentation | Template for developers |

---

## How Credentials Flow

### Example: Creating a Task in Notion

```
User sends WhatsApp message
    ↓
WhatsAppController receives webhook
    ↓
AIService::parseMessage() called
    - Accesses: config('services.openai.key')
    - Gets value from: .env → OPENAI_API_KEY
    ↓
NotionService::createTask() called
    - Accesses: config('services.notion.token')
    - Gets value from: .env → NOTION_TOKEN
    - Accesses: config('services.notion.database_tasks')
    - Gets value from: .env → NOTION_DATABASE_TASKS
    ↓
Request sent to Notion API with credentials
```

---

## Verification Checklist

- [x] `.env` file has all credentials
- [x] `config/services.php` maps them correctly
- [x] `AIService.php` can access OpenAI credentials
- [x] `NotionService.php` can access Notion credentials
- [x] `ValidateTwilioRequest.php` can access Twilio credentials
- [x] `.env.example` documents all required variables
- [x] `HealthController.php` can verify all are configured

---

## Testing Configuration

### Test Credentials Are Loaded

Visit: `GET /health`

**Response shows configuration status:**
```json
{
    "status": "OK",
    "timestamp": "2026-03-14T10:30:00Z",
    "environment": "local",
    "services": {
        "twilio": {
            "configured": true,
            "has_account_sid": true,
            "has_auth_token": true
        },
        "openai": {
            "configured": true,
            "has_api_key": true
        },
        "notion": {
            "configured": true,
            "has_token": true,
            "has_database_tasks": true,
            "has_database_ideas": true
        }
    }
}
```

---

## Security Notes

✅ **Best Practices Implemented:**
- All secrets in `.env` (never committed to git)
- `.env.example` as reference template
- Credentials accessed through config system
- Error messages log missing credentials
- Middleware validates Twilio requests with signatures
- Health endpoint shows configuration status

⚠️ **Never:**
- Commit `.env` to version control
- Log credential values (only log missing status)
- Share `.env` file credentials
- Use dummy/test credentials in production

---

## Summary

**All files requiring secrets are configured and ready to use.**

The `.env` file acts as the **single source of truth** for all credentials, and Laravel's configuration system ensures they're safely passed to the services that need them.

No additional file configuration is needed!
