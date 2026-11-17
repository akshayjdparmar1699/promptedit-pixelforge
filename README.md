# PromptEdit x Pixelforge

Image generation on top of the Pixelforge API, built with Laravel, Vue and Inertia.
Users write a prompt, see the price before they start, follow each image live, and are charged
exactly Pixelforge's cost plus 25%. Failed images are free.

Includes both stretch goals: an operator report reconciled against `GET /v1/usage`, and credit
balances with a "Buy credits" flow that blocks generating without enough credits.

## How it works

```
Browser --POST /generations--> GenerationService
                                 |- estimate from GET /v1/pricing
                                 |- insert generation (queued) + reserve max charge   (one DB transaction)
                                 `- dispatch SubmitGenerationToPixelforge
                                         `- POST /v1/generations  (Idempotency-Key, metadata.app_generation_id)
                                             |- 202 -> GenerationStateMachine::apply()
                                             |- 429/503/network -> release() with Retry-After + backoff
                                             `- 4xx -> failed, reserve refunded
Pixelforge --webhook--> PixelforgeWebhookController
                         |- verify HMAC signature + timestamp
                         `- one transaction: insert event id (unique) + GenerationStateMachine::apply()
Scheduler --every minute--> pixelforge:sync  (polls generations whose webhooks have not arrived)
```

Every status change goes through `GenerationStateMachine::apply()`, whichever path delivered it.

## Decisions

**Money is integer nanodollars.** Pixelforge amounts are exact with nine decimals, so `1 USD = 1e9`
nanodollars stores every provider cost exactly in a `BIGINT`, with no floats anywhere. Rates are
converted to nanodollars per 1M tokens, multiplied by token counts, summed, and divided by 1M once.
Rounding only happens in the browser for display (4 decimals, with the exact value on hover).

**Markup rounding.** Charge = `ceil(cost × 1.25)` to the nanodollar, so we never charge below cost
plus markup. With the current rates the result is always exact anyway.

**Actual cost comes from the generation itself.** The final cost uses the `usage` and the `rates`
on the finished generation object (rates are fixed at creation), not the current price list. This
is why our provider cost matches `GET /v1/usage` to the nanodollar.

**Estimates.** Text tokens are `ceil(characters / 4)` ±15%; reasoning tokens span the quality's
min..max. The UI shows the expected price (expected text, midpoint reasoning) and the full range.
Unicode characters are counted as code points on both client and server.

**Credits use a reserve.** Starting an image reserves the maximum possible charge, so a user can
never spend more than they have. When it finishes the unused part is refunded (`settle`); a failure
refunds it fully (`release`). `credit_transactions` is an append-only ledger, and
`users.balance_nanos` is updated in the same transaction under a row lock. A unique index on
`(generation_id, type)` plus a final-state check means money moves at most once per generation.

**Webhooks are idempotent and order-independent.**
- Signature: HMAC-SHA256 of `t.rawBody`, constant-time compare, timestamps older than 5 minutes rejected.
- Duplicates: the event id is inserted with `insertOrIgnore` into a unique column. A duplicate inserts nothing and is acknowledged.
- Order: status only moves forward (`queued < processing < succeeded|failed`); final states never change.
- Durability: recording the event and applying it share one transaction. If applying fails, we return 500 and Pixelforge retries, so an event is never marked seen without its effect.
- Early webhooks: a webhook can arrive before `POST /v1/generations` returns, so generations are also matched by `metadata.app_generation_id`.

**Retries.** Submission runs in a queued job so waiting never blocks a request. Rate, concurrency
and overload errors respect `Retry-After`, back off exponentially with jitter, and give up after 30
attempts or 20 minutes. Every attempt reuses the same `Idempotency-Key`, so a retry after a timeout
cannot create or bill a second image. Content policy, validation and daily-limit errors fail at once,
for free. While waiting, the user sees why (for example "Waiting for a free slot").

**Polling fallback.** Webhook delivery is not guaranteed, so `pixelforge:sync` polls active
generations older than a minute, at most 10 per run to respect the 60 requests per minute limit.

**Report days.** Pixelforge bills a generation on the UTC day it completed, so the report groups by
the provider's `completed_at` and compares each day with `GET /v1/usage` for this key.

**Failed images can be retried in one click.** `internal_error` usually succeeds on retry, so the
history card reopens the form with the same settings. It is a new generation with a new price.

### Known limitations

- If submission crashes with an unexpected exception after Pixelforge accepted the request, the
  generation is marked failed locally; a later success would then be billed by Pixelforge but not
  charged to the user, and would show as a mismatch in the report.
- If Pixelforge raises its rates between the estimate and the moment the generation is created,
  the actual charge can exceed the reserve and the balance can go slightly negative. We still
  charge the true cost.

## Running locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET (local key)
php artisan migrate
composer run dev                # app, queue worker and Vite
php artisan schedule:work       # polling fallback
ngrok http 8000                 # set https://<tunnel>/webhooks/pixelforge as the local key's webhook URL
php artisan app:make-admin you@example.com
```

Tests: `php artisan test`

Test prompts: `[fail]`, `[slow]`, `[duplicate]` and the other keywords in the Pixelforge docs.

## Deploying (Laravel Cloud)

Connect the repository, add a Postgres database, set the `PIXELFORGE_*` variables from a separate
production API key, enable a queue worker and the scheduler, and use
`https://<app-domain>/webhooks/pixelforge` as that key's webhook URL.
# promptedit-pixelforge
