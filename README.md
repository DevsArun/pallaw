# Pallaw — AI Question Generator

Upload a **sample question CSV**, type any **topic**, and let AI generate brand-new
**math questions** in the exact same table structure. Then **export everything back to CSV**.

Built with **HTML + Tailwind CSS + JavaScript** (frontend), **PHP** (backend), **MySQL** (database),
and the **Groq API** for generation. UI inspired by dub.co — clean, modern, premium.

---

## Features

- Upload sample CSV (drag & drop) — columns / table structure auto-detected
- Free-text **topic** box (no dropdown), choose how many questions (1–50)
- AI generation via **Groq**, with correct math answers & solutions
- **Export CSV** — same columns as your input file
- Generation history saved to **MySQL** (optional; app works without DB too)
- Switch Groq models from the UI

---

## Requirements

- PHP 8.0+ with `pdo_mysql` and `curl` extensions
- MySQL 5.7+ / MariaDB (optional — only needed for history)
- A Groq API key — https://console.groq.com/keys

---

## Setup

### 1. Configure

Set environment variables (recommended) or edit `api/config.php`:

```bash
export GROQ_API_KEY="gsk_your_key_here"
export DB_HOST="127.0.0.1"
export DB_NAME="pallaw"
export DB_USER="root"
export DB_PASS=""
# optional:
export GROQ_MODEL="llama-3.3-70b-versatile"
```

### 2. Create the database (optional, for history)

```bash
mysql -u root -p < sql/schema.sql
```

### 3. Run

Easiest — PHP's built-in server from the project root:

```bash
php -S localhost:8000
```

Then open **http://localhost:8000**

> For production, point Apache/Nginx (with PHP-FPM) at the project root.
> The `index.html` is the entry page and the API lives under `/api/*.php`.

---

## How to use

1. **Upload** the sample CSV (a ready-made `sample-questions.csv` is included).
2. **Type a topic** (e.g. "Quadratic Equations"), set the count, pick a model, hit **Generate**.
3. Review the generated rows, then click **Export CSV** to download — same columns as the input.

---

## Project structure

```
pallaw/
├── index.html              # Frontend (Tailwind + JS)
├── assets/
│   └── app.js              # CSV parse, generate, export, history
├── api/
│   ├── config.php          # DB + Groq config (env-driven)
│   ├── db.php              # PDO MySQL connection
│   ├── groq.php            # Prompt build + Groq API call
│   ├── generate.php        # POST: generate (+ save to DB)
│   ├── history.php         # GET: list sets / fetch one set
│   └── health.php          # Key/DB status for the UI
├── sql/
│   └── schema.sql          # MySQL schema (JSON storage for any columns)
├── sample-questions.csv    # Example input
└── README.md
```

---

## Notes

- The Groq API key stays **server-side** (PHP) — never exposed to the browser.
- History is **best-effort**: if MySQL isn't connected, generation & export still work.
- CSV columns are dynamic — whatever columns you upload are preserved on export.
