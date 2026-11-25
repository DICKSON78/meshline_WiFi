# Meshline WiFi — Laravel 12 port

This directory is the Laravel replacement for the Node/Express backend in the
repository root. The Node code is the behavioural reference and is **not**
modified by this port. The Vue SPA in `../frontend` keeps talking to the same
JSON endpoints; `GET /api/csrf` still returns `{ csrfToken }` and the client
sends it as `X-CSRF-TOKEN`.

See [`ARCHITECTURE.md`](ARCHITECTURE.md) for the implementation contract and
[`docs/PORTING_STATUS.md`](docs/PORTING_STATUS.md) for parity gaps.

## Requirements

- PHP 8.3 with `pdo_mysql`, `mbstring`, `xml`, `bcmath`, `zip`, `curl`, `redis`
- Composer 2, Node.js 18+, MySQL 8, Redis 7 (optional but recommended)
- A built frontend: `../frontend/dist/index.html`
- nginx if you want a public reverse proxy in front of `php artisan serve`

## Install

```bash
cd laravel
composer install
cp ../.env.laravel.example .env
php artisan key:generate          # replaces the example APP_KEY
```

Edit `laravel/.env` and set `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD` and `REDIS_*` for your machine.

**Local XAMPP:** create a database named `meshline_wifi`, then use
`DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=meshline_wifi`,
`DB_USERNAME=root`, `DB_PASSWORD=` (empty). Redis is optional; set
`CACHE_STORE=array` or `database` if it is not running.

Legacy `$2a$` bcrypt hashes from the Node app are accepted via
`config/hashing.php` (`bcrypt.verify = false`). The seeded admin is
`admin@meshline.com` / `Admin@123`.

### Database

For a **fresh** database:

```bash
php artisan migrate --force
php artisan db:seed --force
```

For an **existing Node database**, do not blindly run the stock Laravel
`create_users_table` migration — the legacy `users` table already exists.
Import `../database/schema.sql`, then the incremental scripts your deployment
uses (for example `migrate.js` and `migrate-withdrawal-payouts.js`), and let
the database agent's compatibility
migrations create only `laravel_sessions`, `cache` and `jobs`. If a legacy-data
importer command is available (`php artisan db:import`), prefer it over running
raw SQL. Always take a dump first.

The session table is now `laravel_sessions` (cookie is still `meshline_sid`);
existing Express sessions are **not** readable — plan for re-login at cutover.

### Frontend

```bash
cd ../frontend && npm install && npm run build
cd ../laravel
```

`public/` in this directory is Laravel's public root; the SPA files live in
`../frontend/dist` and the legacy uploads remain in `../public/uploads`.
Stale prebuilt bundles in the repository-root `public/assets` are **not** used
by the Laravel SPA routes.

## Run locally

```bash
php artisan serve --host=0.0.0.0 --port=8000
php artisan pail                 # tail logs (optional)
# or: composer dev                # serve + queue:listen + pail + vite
```

Quick checks:

```bash
php artisan about
php artisan route:list
curl -i http://127.0.0.1:8000/health
php -l app/Console/Commands/meshlineTickJob.php
php artisan test
```

### Collector (WebSocket tunnel)

```bash
COLLECTOR_HTTP_PORT=8081 php artisan collector:serve
```

nginx proxies `/api/collector/ws` to this process; do not expose the collector
directly if `COLLECTOR_KEY` is set. `COLLECTOR_KEY` must match the value routers
send.

### Scheduler

```bash
php artisan schedule:work        # long-running worker, one per deployment
# or a single cron entry:
# * * * * * cd /path/to/laravel && php artisan schedule:run >> /dev/null 2>&1
php artisan schedule:list
```

| Schedule | Command | Behaviour |
|---|---|---|
| Every minute | `meshline:jobs:tick` | Expired-package disconnect, free-byte charging, `PaymentFulfillment::reconcile()` + `::processPayouts()` when that class exists. Each job has its own try/catch. |
| Every 5 minutes | `meshline:jobs:stale-routers` | Warn only when `setup_status='configured'` and `last_seen` is older than 10 minutes. |
| Every 30 seconds (opt-in) | `meshline:jobs:metrics` | Refresh gauges into cache and `storage/app/meshline-metrics.prom`. Enable with `METRICS_REFRESH_ENABLED=true`. |

Ad purging is no longer part of the scheduler: the ads/ad_views feature has
been removed from the product.

Manual runs:

```bash
php artisan meshline:jobs:tick --only=expiry
php artisan meshline:jobs:tick --only=payments
php artisan meshline:jobs:stale-routers --threshold=30
php artisan meshline:jobs:metrics --force --show
```

## nginx (optional reverse proxy)

Save this as `/etc/nginx/conf.d/meshline.conf`, adjust paths/ports, then run
`nginx -t && systemctl reload nginx`:

```nginx
server {
    listen 80;
    server_name meshline.example.com;
    client_max_body_size 50m;

    root /srv/meshline/laravel/public;
    index index.php;

    # Laravel's collector:serve on port 8081 owns the tunnel WebSocket.
    location /api/collector/ws {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    # Optional static gauges written by meshline:jobs:metrics.
    location = /metrics {
        alias /srv/meshline/laravel/storage/app/meshline-metrics.prom;
        default_type text/plain;
    }

    # Serve Laravel public assets directly; everything else goes to artisan.
    location ~* \.(?:js|css|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|mp4|webp|json)$ {
        try_files $uri @laravel;
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    location / {
        try_files $uri $uri/ @laravel;
    }

    location @laravel {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 300s;
        proxy_send_timeout 300s;
    }
}
```

`php artisan serve` is intended for development/small deployments; use PHP-FPM
behind nginx for high traffic.

## Routers: MikroTik vs Omada

`ROUTER_DRIVER` is the fallback (`mikrotik` or `omada`); an individual row's
`mikrotiks.driver` overrides it. Credentials are decrypted by
`App\Services\Routers\RouterManager` and operations go through
`RouterDriver`; unsupported actions throw `CapabilityException` instead of
pretending success.

- **MikroTik**: RouterOS API host/port/user/password, hotspot user and active
  session operations, scripts and raw commands.
- **Omada**: supports on-prem and cloud controllers. Capability limits:
  no `.rsc` script import, no raw RouterOS commands, no WireGuard bootstrap,
  and no MAC-as-username accounts (portal/RADIUS identities are used instead).

### Omada on-prem

```dotenv
ROUTER_DRIVER=omada
OMADA_MODE=onprem
OMADA_CONTROLLER_URL=https://192.168.88.10:8043
OMADA_SITE_ID=Default
OMADA_USERNAME=admin
OMADA_PASSWORD=
OMADA_VERIFY_TLS=false     # self-signed controller certificates
OMADA_TIMEOUT=10
```

On-prem uses `POST /api/v2/login` with username/password, then sends the
session cookie plus `X-CSRFToken`. Controller values can be set globally with
the `OMADA_*` variables above, or per router in `mikrotiks` using the admin
router API (`driver`, `omada_mode`, `omada_controller_url`, `omada_site_id`,
`omada_username`, `omada_password`). Secrets are encrypted at rest.

### Omada cloud

```dotenv
ROUTER_DRIVER=omada
OMADA_MODE=cloud
OMADA_CLOUD_URL=https://omada.tplinknetwork.com
OMADA_SITE_ID=<site-id>
OMADA_CLIENT_ID=<client-id>
OMADA_CLIENT_SECRET=<client-secret>
```

Cloud uses `clientId`/`clientSecret` against `/api/v2/login` and a Bearer token
on later calls. Per-router values (`omada_client_id`, `omada_client_secret`,
`omada_site_id`) take precedence over these globals.

### Verify the connection

```bash
php artisan omada:test <router-id>
```

This logs in and lists sites/devices without printing credentials. If it
returns no data, check the exact OpenAPI endpoints for your firmware (the
uncertain ones are marked in `app/Services/Routers/OmadaClient.php`) and
`storage/laravel.log`.

## Cutover checklist (Node → Laravel)

1. Freeze deploys and take a MySQL dump; record current `mikrotiks`, ClickPesa
   and `reseller_withdrawals` state.
2. Stop the Node process **and** its in-process timers (expiry scheduler,
   stale-router monitor, `setInterval(refreshMetrics, 30000)`). Note that
   payment budgets, per-order check counts and payout cooldowns lived in Node
   memory and restart from zero; the Laravel tick delegates to the new
   `PaymentFulfillment` class.
3. Import legacy schema/data; run Laravel migrations that only add session,
   cache and jobs tables. Verify `laravel_sessions` exists and
   `SESSION_COOKIE=meshline_sid`.
4. Build `../frontend/dist` and confirm `/`, `/admin`, `/hotspot/dashboard`,
   `/health`, `/api/csrf`, `/api/me`, `/api/packages`.
5. Run the smoke tests and a read-only parity sweep of admin/HQ/reseller endpoints.
6. Switch the reverse proxy to Laravel. Expect all users to be logged out once
   (session store change); CSRF tokens must be refetched after login.
7. Update ClickPesa webhook URLs and verify signature handling; confirm the
   collector tunnel connects through `/api/collector/ws` with `COLLECTOR_KEY`.
8. Start exactly one `php artisan schedule:work` process. Watch the first tick
   for router-capability warnings (especially on Omada).
9. Keep the Node stack stopped but available for rollback until a full
   business day of payments, payouts and router disconnects has been verified.
