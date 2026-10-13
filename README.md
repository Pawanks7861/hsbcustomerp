# BOQ & Budget

Construction project BOQ and budget application.

Modules: Projects, BOQ, BOQ item budgets, and a two-card project dashboard.

## Requirements

- PHP 8.2 or newer
- MySQL
- Node.js

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan serve
```

Set `DB_*` in `.env` to the MySQL database before migrating.
