# Pallaw — AI Question Panel

A locked, dashboard-based admin panel to **build math MCQs** and **auto-add solutions**
from CSV files — in the exact format your app imports. Powered by the **Groq API**.

Stack: **HTML + Tailwind CSS + JavaScript** (frontend) · **PHP** (backend) · **MySQL** (database).
Premium dark + cyan UI.

---

## Features

- 🔒 **Login lock** — the whole panel is behind a username/password (configured in `api/config.php`).
- 🧮 **Question Builder** — upload a sample CSV, generate fresh MCQs **with solutions already filled in**, same columns. **Topic is optional** — leave it empty and the AI infers it from your samples.
- ✅ **Solution Builder** — upload questions and let the AI fill the **explanation/answer** columns cleanly, without touching the questions.
- 📊 **Dashboard** — stats (total / generated / solved / batches) and recent activity with a **re-download CSV** button for every batch.
- ⚙️ **Settings** — only the **Groq API key + model** (DB & login stay in config for security).
- 💾 **MySQL history** — every batch is saved; tables auto-create on first connect.

---

## Expected CSV format

The included `sample_questions.csv` matches the MCQ format the app targets:

```
question_text,option_a,option_b,option_c,option_d,correct_option,explanation,difficulty
```

Any CSV works though — whatever columns you upload are detected and preserved on export.

---

## Requirements

- PHP 8.0+ with `pdo_mysql` and `curl`
- MySQL 5.7+ / MariaDB (optional — only for dashboard/history)
- A Groq API key — https://console.groq.com/keys

---

## Setup

### 1. Configure `api/config.php` (or environment variables)

```php
// DATABASE
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'pallaw');
define('DB_USER', 'root');
define('DB_PASS', '');

// ADMIN LOGIN — change these!
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'pallaw@admin');
```

Environment variables (`DB_HOST`, `DB_PASS`, `ADMIN_USERNAME`, `ADMIN_PASSWORD`, `GROQ_API_KEY`, …)
always take priority over the file, which is handy for production.

> **Change the default admin password before deploying.**

### 2. Run

```bash
php -S localhost:8000
```

Open **http://localhost:8000**, sign in, then go to **Settings** and paste your Groq API key.

The MySQL tables are created automatically on first connect (or run `sql/schema.sql` manually).

---

## Project structure

```
pallaw/
├── index.html              # Dashboard SPA + login screen
├── assets/app.js           # Auth, router, CSV utils, all views
├── api/
│   ├── config.php          # DB + admin login + Groq defaults  ← edit here
│   ├── auth.php            # Login / logout / session guard
│   ├── db.php              # PDO MySQL + auto schema + save_job()
│   ├── groq.php            # Prompts + Groq calls (generate & solve)
│   ├── generate.php        # POST: build new questions (topic optional)
│   ├── solve.php           # POST: add solutions to existing questions
│   ├── jobs.php            # GET: dashboard list + stats / one job
│   ├── download.php        # GET: stream a saved batch as CSV
│   ├── settings.php        # GET/POST: Groq key/model
│   └── health.php          # GET: status badges
├── sql/schema.sql          # MySQL schema (optional manual setup)
├── sample_questions.csv    # Example input (MCQ format)
└── README.md
```

---

## Security notes

- Every API endpoint requires a valid session — no data is reachable without logging in.
- The Groq key and DB password are **server-side only** and never returned to the browser in plain text.
- `api/settings.local.php` (the saved Groq key) is gitignored. Even if requested directly in a browser it returns nothing.
