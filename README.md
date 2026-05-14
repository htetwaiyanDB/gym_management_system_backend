
# Gym Management System

A Laravel-based gym management platform that supports member and trainer onboarding, subscriptions, trainer bookings, QR attendance tracking, blog content, and admin reporting. The project ships with a REST API secured by Laravel Sanctum and a Vite-powered frontend build.

## Features

- Member,Trainer and admin authentication with token-based APIs (Laravel Sanctum)
- Attendance tracking with RFID system support
- Subscription plans, pricing management, and renewals
- Trainer profiles and booking workflows
- User profiles and booking workflows
- Admin dashboard with export/reporting endpoints
- Blog/content management
- CAPTCHA integrations for user flows

## Tech Stack

- **Backend:** Laravel 12, PHP 8.2
- **Frontend tooling:** Vite, Tailwind CSS, Alpine.js and React
- **Auth:** Laravel Sanctum
- **Utilities:** Intervention Image, CAPTCHA providers

## Getting Started
### Prerequisites

- PHP 8.2+
- Composer
- Node.js 18+ and npm
- A database supported by Laravel (MySQL/PostgreSQL/SQLite)

### Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure your database credentials in `.env`, then run migrations:

```bash
php artisan migrate
```
### Development

Run the full dev stack (Laravel server, queue worker, logs, and Vite):

```bash
composer run dev
```

If you only need the API:

```bash
php artisan serve
```

### Build Assets

```bash
npm run build
```

### Tests

```bash
composer test
```

## API Documentation

API reference lives in [`GYM_API_DOCUMENTATION_v2.md`](GYM_API_DOCUMENTATION_v2.md). Base URLs are:

- Development: `http://127.0.0.1:8000/api`
- Production: `https://your-domain.com/api`

## Project Structure

- `app/` — Laravel application code
- `routes/` — Web and API route definitions
- `database/` — Migrations, factories, and seeders
- `resources/` — Frontend assets and Blade templates
- `public/` — Public web root
- `tests/` — Automated tests

## Environment Notes

- The API expects `Accept: application/json` and `Authorization: Bearer {token}` headers for protected endpoints.
- Queue processing is enabled in the dev script (`composer run dev`).

## License

This project uses the MIT license.
