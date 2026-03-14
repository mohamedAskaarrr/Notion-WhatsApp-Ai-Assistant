# WhatsApp-Notion AI Assistant Backend Setup Guide

## 📋 Project Overview

This is a Laravel backend that acts as a middleware between **WhatsApp (Twilio)**, **OpenAI**, and **Notion APIs**. Users can send messages on WhatsApp, and the system will intelligently parse the intent and perform actions in Notion.

## 🏗️ Architecture

```
WhatsApp (Twilio)
    ↓
    ↓ Webhook Request
    ↓
[WhatsAppController] → Receives & validates request
    ↓
[AIService] → Parses message intent using OpenAI
    ↓
[NotionService] → Executes actions in Notion
    ↓
Response → Sent back to WhatsApp
```

## 📁 Project Structure

```
app/
├── Http/
│   └── Controllers/
│       └── WhatsAppController.php    # Main webhook handler
├── Services/
│   ├── AIService.php                 # OpenAI integration & message parsing
│   └── NotionService.php             # Notion API integration
└── Models/

config/
└── services.php                       # API configuration

routes/
└── web.php                            # Webhook route definition

.env                                   # API credentials & configuration
```

## ✅ Setup Instructions

### 1. Install Dependencies

```bash
composer install
```

### 2. Environment Configuration

All required credentials are already configured in `.env`:

```dotenv
# Twilio WhatsApp Integration
TWILIO_ACCOUNT_SID=US493d6e822d038e23ffbcbe9ce1485e16
TWILIO_AUTH_TOKEN=d26c1cea40c288b2c7dbd5c41ac5381b

# OpenAI API (Add your key)
OPENAI_API_KEY=your_openai_api_key

# Notion Integration
NOTION_TOKEN=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
NOTION_DATABASE_TASKS=323912af73080f2828ec679b604c04b
NOTION_DATABASE_IDEAS=3239127af73080919ce4dd5b0acb7a9a
```

### 3. Add OpenAI API Key

Get your API key from [OpenAI](https://platform.openai.com/api-keys) and update:

```dotenv
OPENAI_API_KEY=sk-your-actual-api-key-here
```

### 4. Start the Server

```bash
php artisan serve
```

The server will run at: `http://localhost:8000`

### 5. Configure Twilio Webhook

1. Go to your Twilio WhatsApp sandbox settings
2. Set the webhook URL to: `http://your-domain.com/webhook/whatsapp`
3. Use the `Post` method
4. Save

## 🚀 Supported Commands

### 1. Create a Task
**User message:** "Add task finish routing assignment tomorrow"

**AI output:**
```json
{
  "action": "create_task",
  "title": "finish routing assignment",
  "due": "tomorrow"
}
```

**Result:** Task created in Notion with due date set to tomorrow

### 2. List Tasks
**User message:** "Show my tasks"

**AI output:**
```json
{
  "action": "list_tasks"
}
```

**Result:** Returns all tasks from Notion

### 3. Save an Idea
**User message:** "Save idea for new app feature: voice notes"

**AI output:**
```json
{
  "action": "save_idea",
  "content": "voice notes",
  "category": "app feature"
}
```

**Result:** Idea saved to Notion Ideas database

## 📱 API Endpoint

### POST `/webhook/whatsapp`

**Request (from Twilio):**
```json
{
  "From": "whatsapp:+1234567890",
  "Body": "Add task finish routing assignment tomorrow"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Task 'finish routing assignment' created successfully! Due: tomorrow",
  "action": "create_task",
  "data": {
    "task_id": "page-uuid"
  }
}
```

## 🔧 Class Details

### WhatsAppController
- **File:** `app/Http/Controllers/WhatsAppController.php`
- **Method:** `handle(Request $request, AIService $aiService, NotionService $notionService)`
- **Responsibility:** Receive Twilio webhook, coordinate between AIService and NotionService

### AIService
- **File:** `app/Services/AIService.php`
- **Method:** `parseMessage(string $message): ?array`
- **Responsibility:** Send user message to OpenAI, parse response into structured JSON command

### NotionService
- **File:** `app/Services/NotionService.php`
- **Methods:**
  - `executeCommand(array $command): array` - Route command to appropriate action
  - `createTask(array $command): array` - Create task in Notion
  - `listTasks(array $command): array` - Fetch all tasks from Notion
  - `saveIdea(array $command): array` - Save idea to Notion
- **Responsibility:** Execute actions in Notion using its API

## 🔑 Environment Variables

| Variable | Description |
|----------|-------------|
| `TWILIO_ACCOUNT_SID` | Your Twilio account ID |
| `TWILIO_AUTH_TOKEN` | Twilio authentication token |
| `OPENAI_API_KEY` | OpenAI API key for message parsing |
| `NOTION_TOKEN` | Notion integration token |
| `NOTION_DATABASE_TASKS` | ID of the Tasks database in Notion |
| `NOTION_DATABASE_IDEAS` | ID of the Ideas database in Notion |

## 📊 How It Works

1. **User sends WhatsApp message** → Twilio receives it
2. **Twilio sends webhook POST** → `POST /webhook/whatsapp`
3. **WhatsAppController processes request** → Extracts message text
4. **AIService parses message** → Calls OpenAI to understand intent
5. **OpenAI returns JSON command** → e.g., `{"action": "create_task", "title": "...", "due": "..."}`
6. **NotionService executes command** → Creates task/saves idea in Notion
7. **Response sent to WhatsApp** → User receives confirmation

## 🛠️ Troubleshooting

### Error: "OpenAI API key not configured"
- Add your OpenAI API key to `.env`
- Clear config cache: `php artisan config:clear`

### Error: "Notion credentials not configured"
- Verify `NOTION_TOKEN` and `NOTION_DATABASE_*` are set in `.env`
- Ensure the Notion integration has proper permissions

### Error: "Failed to create task in Notion"
- Check if the database ID is correct
- Verify the Notion integration has edit permissions
- Check database schema has expected properties (Name, Status, Due Date)

### Webhook not receiving messages
- Ensure server is publicly accessible (use ngrok for local testing)
- Verify URL is correct in Twilio settings
- Check Laravel logs: `storage/logs/laravel.log`

## 📝 Logging

All errors are logged to:
```
storage/logs/laravel.log
```

Monitor real-time logs with:
```bash
php artisan pail
```

## 🧪 Testing

Send a test message to the WhatsApp sandbox:
```
Message: "Add task test integration today"
Expected response: Task created with today's date
```

## 📚 Additional Resources

- [Twilio WhatsApp API](https://www.twilio.com/docs/whatsapp)
- [OpenAI Chat Completions](https://platform.openai.com/docs/guides/gpt)
- [Notion API Documentation](https://developers.notion.com/)
- [Laravel Documentation](https://laravel.com/docs)

## 🔐 Security Notes

- Never commit `.env` to version control
- Keep API keys secure and rotate them regularly
- Use environment variables for all credentials
- Validate all incoming webhook requests

---

**Status:** ✅ Backend is ready to use!
