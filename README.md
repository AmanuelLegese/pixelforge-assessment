# PromptEdit × Pixelforge assessment

Laravel 12 + Vue + Inertia + Tailwind + Postgres. Users generate images through Pixelforge and are charged
Pixelforge's cost + 25%.

## Run locally (Docker, Alpine images)

```bash
cp .env.example .env            # set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm vite sh -c "npm install && npm run build"
docker compose up -d app queue scheduler vite    # http://localhost:8000
docker compose run --rm app php artisan test
```

Expose `http://localhost:8000/webhooks/pixelforge` with a tunnel and set it (plus the `whsec_…` secret) on your key in the Pixelforge dashboard.
Set `ADMIN_EMAILS` to see `/admin/report`.

## How money works

- Money is an integer count of **nanodollars** (1 USD = 1e9), matching Pixelforge's nine-decimal precision. No floats; BCMath for the arithmetic.
- Cost = `Σ tokens × rate` using the **rates stored on the generation** (not today's published rates), exactly, then `charge = cost × 1.25` rounded half up to a nanodollar.
- Failed generations are recorded at `0` and never touch the balance. The pre-submit price is an estimate (typical, with a min–max range from the
  documented ±15% text variance and reasoning range); the real amount is recorded when the generation finishes.

## Reliability

- `GenerationSettler` is the only code that applies provider state. Webhooks, the create response and the polling command all call it.
  It de-duplicates by event id (unique row in the same transaction), locks the generation row, never leaves `succeeded`/`failed`, and
  records the charge through a unique `(generation_id, type)` ledger row, so a generation can be charged at most once.
- Webhooks are matched by `metadata.generation_id`, so they work even if they arrive before the create call returns.
- `SubmitGeneration` uses the generation's uuid as `Idempotency-Key`, retries 429/503 honouring `Retry-After`, and fails (free) on rejection, daily limit, or after 15 minutes.
- `generations:poll` (scheduled every minute) covers webhooks that never arrive.
- Balances: in-flight generations reserve their worst-case cost, so concurrent requests can't overspend.
