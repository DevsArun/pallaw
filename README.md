# Pallaw — AI Question Platform

A dashboard-based platform to **build math MCQs** and **auto-add solutions** from CSV files —
in the exact format your app imports. Powered by the **Groq API**.

Stack: **HTML + Tailwind CSS + JavaScript** (frontend) · **PHP** (backend) · **MySQL** (database).

---

## What it does

The platform has two main tools plus a dashboard and settings:

### 1. Question Builder (`#generate`)
- Upload a **sample CSV** — columns/structure are auto-detected.
- Type a **topic** and a **count**, pick a model.
- AI generates brand-new questions **with the solution/explanation already filled in**.
- Output uses the **exact same columns** as your sample → **Export CSV** ready to import.

### 2. Solution Builder (`#solve`)
- Upload a CSV of questions (with empty `explanation` / `correct_option` etc.).
- Tick which **column(s) to fill** (likely solution/answer columns are auto-selected).
- AI fills them **cleanly**, without touching your questions or options.
- **Export CSV** in the same format.

### 3. Dashboard (`#dashboard`)
- Stats: total questions, generated, solved, batches.
- Recent activity table with a **re-download CSV** button for every batch (generate or solve).
- Filter by Generated / Solved.

### 4. Settings (`#settings`)
- Configure your **Groq API key**, default **model**, and **MySQL** connection.
- Saved server-side to `api/settings.local.php` (gitignored). Secrets are never sent back to the browser in plain text.

---

## Expected CSV format

The included `sample_questions.csv` matches the MCQ format the app targets:

```
question_text,option_a,option_b,option_c,option_d,correct_option,explanation,difficulty
```

But any CSV works — whatever columns you upload are detected and preserved on export.

---

## Requirements

- PHP 8.0+ with `pdo_mysql` and `curl`
- MySQL 5.7+ / MariaDB (optional — only for the dashboard/history)
- A Groq API key — https://console.groq.com/keys

---

## Run

```bash
# from the project root
php -S localhost:8000
```

Open **http://localhost:8000**, go to **Settings**, paste your Groq API key
(and MySQL details if you want history), click **Save** — then start building.

> The database tables are created automatically the first time a working
> MySQL connection is made. You can also run `sql/schema.sql` manually.

### Configure via environment (alternative to Settings page)

```bash
export GROQ_API_KEY="gsk_your_key"
export DB_HOST="127.0.0.1" DB_NAME="pallaw" DB_USER="root" DB_PASS=""
php -S localhost:8000
```

Environment variables always take priority over the Settings page.

---

## Project structure

```
pallaw/
├── index.html              # Dashboard SPA (sidebar + 4 views)
├── assets/app.js           # Router, CSV utils, all view logic
├── api/
│   ├── config.php          # Settings resolver (env > file > default)
│   ├── db.php              # PDO MySQL + auto schema + save_job()
│   ├── groq.php            # Prompts + Groq calls (generate & solve)
│   ├── generate.php        # POST: build new questions
│   ├── solve.php           # POST: add solutions to existing questions
│   ├── jobs.php            # GET: dashboard list + stats / one job
│   ├── download.php        # GET: stream a saved batch as CSV
│   ├── settings.php        # GET/POST: read/save settings
│   └── health.php          # GET: status badges
├── sql/schema.sql          # MySQL schema (optional manual setup)
├── sample_questions.csv    # Example input (MCQ format)
└── README.md
```

---

## Notes

- The Groq API key stays **server-side** — never exposed to the browser.
- History/dashboard is **best-effort**: without MySQL, generation & export still work.
- Generated questions **always include their solution**; the Solution Builder is for
  questions you already have and want explained.
