# Sazito ⇄ Anar360 Syncer

A production-grade Laravel 12 application that synchronizes product and variant information from Anar360 into Sazito while offering observability, resiliency, and operational tooling.

## Features

- **Incremental product ingestion** from Anar360 with DTO mapping and validation.
- **Price and stock updates** pushed to Sazito through resilient queue jobs with circuit breaker, rate limiting, retry & idempotency controls.
- **Observability** via database-backed run tracking, external request auditing, semantic events, and `/health` endpoint.
- **Operational tooling** including resumable cursors, failure replay command, and health reporting command.
- **Config-driven** behaviour with `.env` toggles and Makefile/CI hooks for linting, static analysis, and testing.

## Getting Started

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --force
```

Populate the following environment variables with credentials from Anar360 and Sazito:

```
ANAR360_BASE=https://api.anar360.com/api/360
ANAR360_TOKEN=***
SAZITO_BASE=https://www.example.com/api/v1
SAZITO_KEY=***
```

Optional tuning variables are available in `.env.example` for timeouts, retries, rate limits, and sync paging.

## Running the Synchronizer

- **Manual run:** `php artisan sync:products`
- **Replay failures:** `php artisan sync:retry-failures`
- **Inspect health:** `php artisan sync:health`
- **HTTP health probe:** `GET /health`

The scheduler (configured in `bootstrap/app.php`) automatically executes the sync and retry commands.

## Testing & Tooling

| Command | Description |
| --- | --- |
| `make test` | Execute the automated test suite |
| `make lint` | Run Laravel Pint code style checks |
| `make stan` | Execute PHPStan static analysis |
| `make format` | Check formatting without applying fixes |

## Operational Dashboards

Example SQL snippets for monitoring:

- Recent runs: `SELECT id, status, scope, started_at, finished_at FROM sync_runs ORDER BY started_at DESC LIMIT 20;`
- Top failure contexts: `SELECT context, COUNT(*) FROM failures GROUP BY context ORDER BY COUNT(*) DESC;`
- p95 request duration: `SELECT percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms) FROM external_requests WHERE created_at >= NOW() - INTERVAL '1 day';`

## License

This project is open-sourced under the [MIT license](LICENSE.md).
