<?php
/**
 * Approximate parity checker: Express route declarations vs Laravel route:list.
 * Usage: php scripts/route-parity.php
 */
$root = dirname(__DIR__);
$mounts = [
    'hotspotApi' => ['/api/hotspot'],
    'admin' => ['/api/admin'],
    'adminLegacy' => ['/api/admin'],
    'hq' => ['/api/admin/hq'],
    'business' => ['/api/admin/business'],
    'campaignRoutes' => ['/api/campaigns'],
    'walletRoutes' => ['/api/wallet'],
    'revenueShareRoutes' => ['/api/revenue-share'],
    'locationRoutes' => ['/api/locations'],
    'publicRoutes' => ['/api/public'],
    'routerRoutes' => ['/api/routers'],
    'setupWizard' => ['/api/setup-wizard'],
    'autoDetect' => ['/api/auto-detect'],
    'mikrotikSetup' => ['/api/admin/mikrotik-setup'],
    'routerFleet' => ['/api/router-fleet', '/api/admin/router-fleet'],
    'zeroTouch' => ['/api/zero-touch'],
    'investor' => ['/api/investor'],
    'reseller' => ['/api/reseller'],
    'resellerAdmin' => ['/api/admin/resellers-v2'],
    'resellerOnboarding' => ['/api/admin/resellers-v2'],
    'resellerApplications' => ['/api/reseller-applications'],
    'resellerPortal' => ['/api/reseller-portal'],
    'clickpesa' => ['/api/clickpesa'],
    'webhooks' => ['/api/webhooks'],
    'partners' => ['/api/partners'],
];

$normalize = function (string $path): string {
    $path = preg_replace('/:[A-Za-z_]\w*/', '{}', $path);
    $path = preg_replace('/\{[A-Za-z_]\w*\}/', '{}', $path);
    $path = preg_replace('/\/+/', '/', $path);
    $path = trim($path, '/');

    return $path ?: '/';
};

$node = [];
foreach (glob($root.'/routes/*.js') as $file) {
    $name = basename($file, '.js');
    if (! isset($mounts[$name])) {
        continue;
    }
    $source = file_get_contents($file);
    preg_match_all('/\brouter\.(get|post|put|patch|delete)\s*\(\s*[\'"]([^\'"]+)[\'"]/i', $source, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        foreach ($mounts[$name] as $prefix) {
            $key = strtoupper($m[1]).' '.$normalize($prefix.'/'.$m[2]);
            $node[$key] = $name.'.js';
        }
    }
}

chdir($root.'/laravel');
$json = shell_exec('php artisan route:list --json 2>/dev/null');
$laravelRoutes = json_decode((string) $json, true) ?: [];
$laravel = [];
foreach ($laravelRoutes as $route) {
    foreach (explode('|', (string) ($route['method'] ?? '')) as $method) {
        $method = trim($method);
        if (! in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            continue;
        }
        $laravel[$method.' '.$normalize((string) ($route['uri'] ?? ''))] = true;
        if ($method === 'GET') {
            $laravel['HEAD '.$normalize((string) ($route['uri'] ?? ''))] = true;
        }
    }
}

$missing = [];
foreach ($node as $key => $origin) {
    [$method, $path] = explode(' ', $key, 2);
    if (! isset($laravel[$key]) && ! isset($laravel[$method.' '.$path.'/'])) {
        $missing[$origin][] = $key;
    }
}

printf("Express endpoints: %d | Laravel endpoints: %d | Missing in Laravel: %d\n", count($node), count($laravel), array_sum(array_map('count', $missing)));
foreach ($missing as $origin => $paths) {
    echo "\n[$origin]\n  ".implode("\n  ", $paths)."\n";
}
