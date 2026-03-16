# Notion Telegram AI Assistant

A free, self-hosted Laravel 10 backend that connects **Telegram → Google Gemini → Notion**.  
Send a message to your Telegram bot and it will automatically create or query entries in your **Tasks** or **Ideas** Notion databases.

```
You (Telegram) → Bot webhook → Gemini (parses intent) → Notion (Tasks / Ideas DB) → reply back to you
```

---

## What You Need (credentials checklist)

Before you can run the bot you need **4 things**:

| # | What | Where to get it | Env variable |
|---|------|-----------------|--------------|
| 1 | Telegram Bot Token | [@BotFather](https://t.me/BotFather) on Telegram (`/newbot`) | `TELEGRAM_BOT_TOKEN` |
| 2 | Telegram Webhook Secret | Any random string you invent (e.g. `mySecret123`) | `TELEGRAM_WEBHOOK_SECRET` |
| 3 | Google Gemini API Key (free) | [aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey) | `GEMINI_API_KEY` |
| 4 | Notion Integration Token | [notion.so/my-integrations](https://www.notion.so/my-integrations) → New integration | `NOTION_API_KEY` |
| 5 | Notion **Tasks** database ID | From the database URL (see below) | `NOTION_TASKS_DATABASE_ID` |
| 6 | Notion **Ideas** database ID | From the database URL (see below) | `NOTION_IDEAS_DATABASE_ID` |

---

## Step-by-Step Setup

### 1 — Create a Telegram Bot

1. Open Telegram, search for **@BotFather** and send `/newbot`.
2. Follow the prompts (give it a name and username).
3. BotFather will give you a token like `123456:ABC-DEF...` → that is your `TELEGRAM_BOT_TOKEN`.

### 2 — Get a free Gemini API key

1. Go to [aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey).
2. Click **Create API key** (free tier, no credit card needed).
3. Copy the key → `GEMINI_API_KEY`.

### 3 — Create a Notion Integration

1. Go to [notion.so/my-integrations](https://www.notion.so/my-integrations) and click **+ New integration**.
2. Give it a name (e.g. "Telegram Bot"), set the workspace, and click **Submit**.
3. Copy the **Internal Integration Secret** → `NOTION_API_KEY`.

### 4 — Get your Notion Database IDs

For each database (**Tasks** and **Ideas**):

1. Open the database in Notion (full-page view).
2. Look at the browser URL — it looks like:  
   `https://www.notion.so/yourworkspace/xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx?v=...`
3. The 32-character hex string before the `?` is the database ID.  
   Example: `https://notion.so/ws/`**`a1b2c3d4e5f6...`**`?v=...`
4. Also click **… → Add connections** and add the integration you just created.

### 5 — Deploy / run the Laravel app

```bash
# Clone the repo (or use this branch directly)
git clone https://github.com/mohamedAskaarrr/Notion-WhatsApp-Ai-Assistant.git
cd Notion-WhatsApp-Ai-Assistant

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Copy and fill in the environment file
cp .env.example .env
# Edit .env and paste all your credentials (see table above)

# Generate the Laravel application key
php artisan key:generate

# The app needs to be reachable from the internet.
# For local testing, use a tunnel like ngrok:
#   ngrok http 8000
# For production, deploy to any PHP host (Render, Railway, Fly.io, etc.)

# Start the local server
php artisan serve
```

> **No database is required.** This app is stateless — it does not use MySQL/SQLite.  
> You can remove all `DB_*` lines from `.env` if you like.

### 6 — Register the Telegram Webhook

Once the app is publicly accessible (e.g. `https://your-app.example.com`), run this **once** in your browser or with curl:

```
https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook?url=https://your-app.example.com/api/webhook/telegram&secret_token=<TELEGRAM_WEBHOOK_SECRET>
```

Replace `<TELEGRAM_BOT_TOKEN>` and `<TELEGRAM_WEBHOOK_SECRET>` with your actual values.  
Telegram will confirm with `{"ok":true,"result":true}`.

---

## How to Use the Bot

Just send a message to your bot on Telegram:

| You say | What happens |
|---------|-------------|
| `Add task: deploy hotfix, priority critical` | Creates an entry in your **Tasks** Notion database |
| `Save idea: build a habit tracker app` | Creates an entry in your **Ideas** Notion database |
| `Show all my tasks` | Queries the **Tasks** database and tells you how many entries exist |
| `List my ideas` | Queries the **Ideas** database |
| `Update page <page-id> set status Done` | Updates a Notion page's status |

---

## Merging This PR to Main

1. Go to the **Pull Requests** tab in GitHub.
2. Open this PR ("Add Laravel 10 backend API middleware…").
3. Click **Merge pull request → Confirm merge**.
4. Done — your `main` branch will have the full working code.

---

## Environment File Reference

```dotenv
TELEGRAM_BOT_TOKEN=123456:ABC-DEF...          # from @BotFather
TELEGRAM_WEBHOOK_SECRET=anyRandomStringYouPick

GEMINI_API_KEY=AIza...                         # from Google AI Studio
GEMINI_MODEL=gemini-1.5-flash                  # free tier model (leave as-is)

NOTION_API_KEY=secret_...                      # from notion.so/my-integrations
NOTION_TASKS_DATABASE_ID=a1b2c3d4...           # 32-char hex from Tasks DB URL
NOTION_IDEAS_DATABASE_ID=e5f6g7h8...           # 32-char hex from Ideas DB URL
NOTION_VERSION=2022-06-28                      # leave as-is
```

---

## Running Tests

```bash
php vendor/bin/phpunit --testsuite Unit
```

All 27 unit tests should pass.