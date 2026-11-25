# Porting status — Node → Laravel

Reference: Node sources in the repository root. The Node backend is the
behavioural contract and remains untouched. "Ported" means the behaviour is
implemented in Laravel; endpoints owned by other agents are marked as such and
must be verified with `php artisan route:list` before cutover.

## Scheduled modules

| Node module | Laravel equivalent | Interval | Status | Notes |
|---|---|---|---|---|
| `utils/expiryScheduler.js` → `disconnectExpiredUsers()` | `App\Console\Commands\MeshlineTickJob::disconnectExpiredUsers()` | 1 min | Ported | App-clock expiry check; DB grant reset even when router unreachable; sessions kicked via `RouterManager` + `activeSessions()`/`kickSession()`; `KARIBU HOT BANDO` reset + connection log. |
| `utils/expiryScheduler.js` → `consumeFreeBytes()` | `MeshlineTickJob::consumeFreeBytes()` | 1 min | Ported | `DB::transaction` + `lockForUpdate()`; each `disconnect` row charged once via `bytes_charged`; free bytes clamped at 0; best-effort disconnect when time and data are exhausted. |
| `services/paymentReconciler.js` | `PaymentFulfillment::reconcile()` via class_exists guard | 1 min tick | Ported by payments agent | `App\Services\Payment\PaymentFulfillment` is present and called by the tick. Node rate limits: run every 10 min, 2 orders/run, max 4 checks/order, 20 status checks/day — preserved with static (in-process) budgets. |
| `services/withdrawalPayouts.js` | `PaymentFulfillment::processPayouts()` via class_exists guard | 1 min tick | Ported by payments agent | Node: one create per 60s, max 20 attempts, refund once on terminal failure, reconcile `sent` after 2 min. Static cooldowns do not survive deploys. |
| `utils/routerStatusMonitor.js` | `App\Console\Commands\StaleRouterJob` | 5 min | Ported | `setup_status='configured'` and `last_seen` older than 10 minutes → `Log::warning` only; never mutates router rows. |
| `utils/monitoring.js` → `refreshMetrics()` | `App\Console\Commands\MetricsRefreshJob` | 30 s, **opt-in** | Partial | Gauges written to cache (`hotbando:metrics:*`) and `storage/app/meshline-metrics.prom`. Enabled with `METRICS_REFRESH_ENABLED=true`. No PHP `prom-client`, no HTTP middleware, no request histograms/counters. |
| Timers started in `index.js` (`setInterval`, monitors) | `routes/console.php` schedule + `php artisan schedule:work` | — | Ported | `withoutOverlapping()` mirrors the legacy `running` flag; each job continues after a sibling failure. |

`utils/adPolicy.js` → `purgeExpiredAds()` is **deliberately not ported**:
ads/ad_views are being removed from the product, so the tick has no ad purge
and there are no ad-related smoke-test assertions.

Run `php artisan schedule:list` to confirm registration.

## Other modules (owned by other agents during the port)

| Node area | Laravel area | Status |
|---|---|---|
| Auth, hotspot, public/captive | `app/Http/Controllers/{AuthController,HotspotController,PublicController,CaptiveController}.php`, `routes/api/{auth,hotspot,public}.php`, `routes/captive.php` | Verify with `route:list` |
| Admin + business | `app/Http/Controllers/Admin*.php`, `routes/api/{admin,admin-legacy,business}.php` | Verify with `route:list` |
| Payments, reseller, wallet | `app/Http/Controllers/Payment/*`, `app/Services/Payment/*`, `routes/api/{clickpesa,webhooks,reseller,wallet,applications}.php` | Verify with `route:list` |
| Routers, Omada, collector | `app/Services/Routers/*`, `app/Console/Commands/CollectorServe.php`, `routes/api/{routers,fleet,zero-touch,setup}.php` | Verify with `route:list` |
| HQ / investor / locations / revenue / campaigns | `app/Http/Controllers/Modules/*`, matching `routes/api/*.php` | Verify with `route:list` |
| Schema and legacy data | `database/migrations/*`, `database/seeders/*`, `app/Support/SqlImporter.php` | Verify against a restored dump |

## Known gaps

1. **Stale `public/assets` in the repository root.** The Express app served
   prebuilt bundles from root `public/`. Laravel serves `../frontend/dist` via
   `SpaController`; root `public/assets` is not authoritative and may be stale.
   Rebuild with `cd ../frontend && npm install && npm run build` before cutover.
2. **Session store changed.** Express used `express-mysql-session`; Laravel uses
   the `laravel_sessions` database table with the same `meshline_sid` cookie and
   24h (`SESSION_LIFETIME=1440`) lifetime. Existing sessions are not portable —
   users log in again once at cutover.
3. **CSRF is now session-native.** Tokens come from `csrf_token()` and
   `GET /api/csrf` returns `{ csrfToken }`; clients send `X-CSRF-TOKEN`.
   Login/register/logout, webhooks and zero-touch are exempt (see
   `bootstrap/app.php`). Legacy `CSRF_SECRET` is informational only.
4. **Omada capability limits.** No script/fetch/import, no raw RouterOS command
   channel, no WireGuard provisioning and no MAC-as-username users. Job-driven
   disconnects degrade to a warning on unsupported operations; the DB grant
   reset still happens.
5. **Metrics are partial.** No `prom-client` histogram/counter instrumentation
   and no in-app `/metrics` middleware. `/metrics` is a static file refreshed
   every 30 s when opted in; the Node `type=daily` counter increment is
   represented as a gauge instead.
6. **No automated Jest suite was ported.** The new smoke coverage is PHP
   (`tests/Feature/ApiSmokeTest.php`), which skips DB/frontend-dependent cases
   when MySQL or `frontend/dist` is unavailable. It contains no ad assertions.
   Router-driver, payment and captive-flow tests still need to be added.
7. **`php artisan serve` is a development server.** Use PHP-FPM behind a native
   nginx install for high traffic; a plain-text nginx example is in the README.

## Parity risks and mitigations

| Risk | Mitigation |
|---|---|
| Two schedulers running (Node timers + `schedule:work`) double-charge bytes or double-activate payments | Stop the Node process before starting `schedule:work`; verify only one worker exists. |
| Payment budgets/cooldowns reset on deploy | Move budgets to DB/cache in `PaymentFulfillment`; keep ClickPesa's daily 100-call / 20-check limits in mind. |
| Legacy status enums reject new payout states (`sending`, `sent`) | Apply `migrate-withdrawal-payouts.js` and related migrations before enabling payouts. |
| Router id validation | `RouterManager::row()` plus `mikrotiks.is_active=1`; never fabricate a MAC or router id. |
| Wrong session/CSRF behaviour after cutover | Rebuild frontend, clear browser cookies, refetch `/api/csrf`, test login for each role. |
| Legacy MySQL clock vs app clock expiry | Candidate rows are narrowed in SQL, expiry decided with PHP `Carbon`; mirrors Node. |
| Missing legacy tables during a tick | Each job catches its own `\Throwable` and logs; remaining jobs still run. |
| Frontend not built | SPA routes return the "Frontend not built" JSON; smoke test skips the SPA assertions. |
