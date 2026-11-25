# Meshline WiFi Laravel port — implementation contract

The Node backend in the repository root is the **reference implementation** and must stay
untouched. This directory is the new Laravel 12 application. Port behaviour, not file names:
the Vue client in `../frontend` keeps talking to the same JSON endpoints.

**Product scope (post-scope-change):** vouchers/packages, resellers, HQ Panel and internet
only. Ads/ad-rewards, campaigns/advertisers, bank partners, investors, revenue share,
locations portal and equipment financing are intentionally absent from both backend routes
and frontend views. No Docker — run PHP/natively with nginx.

## Runtime

- PHP 8.3, Laravel 12, MySQL 8 (port 3307 locally), Redis optional.
- All API endpoints live in `routes/api/*.php`, loaded by `routes/web.php` inside the
  `api` prefix **with the `web` middleware group** so sessions and CSRF work exactly as
  in Express. Root-level captive probes live in `routes/captive.php`.
- Session cookie `hotbando.sid`, stored in table `laravel_sessions`, 24h lifetime,
  `SameSite=lax`, `HttpOnly`, `Secure` only when `SESSION_SECURE_COOKIE=true`.
- Login/register/logout/webhooks/zero-touch are CSRF-exempt (list in `bootstrap/app.php`).
  Everywhere else the Vue client sends `X-CSRF-TOKEN` obtained from `GET /api/csrf`.
- Role gates use `->middleware('role:admin')`, `'role:sponsor'`, `'role:reseller'`,
  `'role:customer'`, etc. `App\Support\LegacySession` reads/writes the same per-role
  session keys as the Node app; never introduce a different auth scheme.
- WiFi-protected endpoints use `->middleware('wifi')` (mirrors `requireWifi`).

## Coding conventions

- Controllers: plain classes extending `Controller`, no resource controllers unless the
  route file says so. Keep one controller per legacy route/controller file.
- Data access: use `Illuminate\Support\Facades\DB::table(...)` and `DB::transaction(...)`
  with `->lockForUpdate()` where the Node code used `FOR UPDATE`. Column names and JSON
  storage (`json_encode` strings) must stay byte-compatible with legacy rows.
- Responses: `App\Support\ApiResponse::{ok,success,fail,error,unauthorized,forbidden,json}`.
  Preserve each module's envelope: hotspot/admin use `{success,...}`, HQ/investor use
  `{success:true,data:{...}}`, reseller apps use `{error:'...'}` errors, webhooks use
  `{status:'received'}`. Do not normalize everything to one shape.
- Helpers: `App\Support\meshline::{normalizePhone,intlPhone,setting,decode,encode,activity,notify}`
  and `App\Support\Encryption::{encrypt,decrypt,isEncrypted}` (AES-256-GCM PBKDF2, wire
  compatible with `utils/encryption.js`). `MIB = 1048576`.
- Never invent a MAC address or router id. Router ids must be validated against
  `mikrotiks.is_active = 1` (same as `resolveRegisteredRouter`).
- Router driver contract: `App\Services\Routers\RouterDriver`; `RouterManager` selects
  `mikrotik` or `omada` from `mikrotiks.driver` / `ROUTER_DRIVER` and decrypts secrets.
  Unsupported operations throw `CapabilityException` — never silently pretend success
  on Omada (scripts, raw RouterOS commands, WireGuard, MAC-as-username users).
- Omada supports both on-prem controllers (`https://<host>:8043/api/v2`, cookie +
  `X-CSRFToken`) and cloud (`https://omada.tplinknetwork.com/api/v2`, client credentials).
  Keep both modes behind one driver, configurable per router.

## File ownership during the port

| Area | Files |
|---|---|
| Database | `database/migrations/*`, `database/seeders/*`, `app/Support/SqlImporter.php` |
| Auth + hotspot + public + captive | `app/Http/Controllers/{AuthController,HotspotController,PublicController,CaptiveController}.php`, `routes/api/{auth,hotspot,public}.php`, `routes/captive.php` |
| Admin | `app/Http/Controllers/AdminController.php`, `app/Http/Controllers/Admin/*.php`, `routes/api/{admin,admin-legacy,business}.php` |
| Payments + reseller + wallet | `app/Http/Controllers/Payment/*.php`, `app/Services/Payment/*.php`, `routes/api/{clickpesa,webhooks,reseller,wallet,applications}.php` |
| Routers + Omada + collector | `app/Services/Routers/*`, `app/Console/Commands/CollectorServe.php`, `app/Http/Controllers/Router/*.php`, `routes/api/{routers,fleet,zero-touch,setup}.php` |
| HQ/investor/location/revenue/campaigns | `app/Http/Controllers/Modules/*.php`, `routes/api/{hq,investor,locations,revenue,campaigns}.php` |
| Schedulers + deployment docs | `routes/console.php`, `app/Console/Commands/*Job*.php`, `docs/*`, root `docker-compose.laravel.yml` |

Common files (`routes/web.php`, `bootstrap/app.php`, `app/Support/*`,
`app/Services/Routers/RouterDriver.php`, `RouterManager.php`) are owned by the coordinator —
do not edit them; report needed changes instead.

## Verification

`php artisan route:list`, `php artisan about`, `php -l` on changed files, and
`php artisan migrate --force` against the local MySQL. Every committed module must at
least boot without fatal errors, even when an external device is unreachable.
