# Uhamisho wa Node → Laravel (Meshline WiFi / meshline)

Mradi huu sasa una sehemu mbili:

- **`laravel/`** — programu mpya ya **Laravel 12** (PHP 8.3). Hii ndiyo seva inayotakiwa kutumika baada ya uhamisho.
- **Root (`index.js`, `routes/`, `controllers/`, `services/`, `utils/`)** — kodi ya zamani ya Node imehifadhiwa kama **reference**; haifai kuharibiwa wakati wa uhamisho.

`frontend/` (Vue 3 + Vite) imepunguzwa kwenye maeneo matatu tu: **voucher/packages, Reseller Portal, na HQ Panel**. Matangazo (ads/ad-rewards), kampeni, advertisers, mabenk, investor, revenue-share, locations portal, na mikopo ya vifaa vimeondolewa kwenye frontend na backend. Hakuna Docker: seva inaendeshwa moja kwa moja kwa `php artisan serve` + `php artisan collector:serve` + `php artisan schedule:work` (au nginx ya kawaida). Laravel inaitumika SPA kutoka `frontend/dist`.

## Kuanzisha

```bash
cd laravel
composer install
cp .env.example .env          # au tumia .env.laravel.example
php artisan key:generate
# Sanidi DB_*, CLICKPESA_*, RADIUS_*, OMADA_*, ROUTER_DRIVER n.k. kwenye .env
php artisan migrate --force
php artisan db:seed --force

cd ../frontend && npm ci && npm run build
```

Kuendesha seva:

```bash
cd laravel
php artisan serve --host 0.0.0.0 --port 8000     # HTTP + SPA + API
php artisan collector:serve                      # WebSocket tunnel ya collector (default :8081)
php artisan schedule:work                        # expiry, reconciliation, payouts, router monitor
```

Kwa uzalishaji: MySQL 8, Redis (hiari), FreeRADIUS na nginx vinatumika kama huduma za kawaida za mfumo — tazama `laravel/README.md` kwa mfano wa nginx config. Hakuna Docker/compose.

## Vitu muhimu

- Session cookie: **`hotbando.sid`**, meza **`laravel_sessions`**, saa 24, `SameSite=lax`.
- CSRF: `GET /api/csrf` → `{csrfToken}`; frontend hutuma `X-CSRF-TOKEN`. Njia za login/webhook/zero-touch zimetengwa (exempt).
- Rate limiting: `1200 / dakika 15` kwa akaunti (au IP), imehifadhiwa kwenye Redis/cache.
- `frontend/dist` inatumiwa moja kwa moja; `public/assets/` ya zamani haijatumiwa.
- **Ruta mbili za router**: `ROUTER_DRIVER=mikrotik` (default) au `omada`. Kila ruta inaweza kuweka `mikrotiks.driver=omada`.
  - Omada **on-prem**: `OMADA_MODE=onprem`, `OMADA_CONTROLLER_URL=https://<controller>:8043`, `OMADA_SITE_ID=...`, `OMADA_USERNAME/OMADA_PASSWORD` (au `omada_*` kwenye ruta).
  - Omada **cloud**: `OMADA_MODE=cloud`, `OMADA_CLIENT_ID`, `OMADA_CLIENT_SECRET`.
  - Omada haina `fetch/import`, raw RouterOS commands, wala WireGuard ya ruta; vitendo hivyo vinakataliwa wazi (CapabilityException) badala ya kujifanya vimefanikiwa.
- Repo lengwa la push: `https://github.com/DICKSON78/meshline_WiFi.git`.

## Kuthibitisha

```bash
php scripts/route-parity.php        # Express vs Laravel endpoint coverage
cd laravel && php artisan route:list
cd laravel && php artisan test
```
