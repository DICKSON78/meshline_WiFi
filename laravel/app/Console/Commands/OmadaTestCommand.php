<?php

namespace App\Console\Commands;

use App\Services\Routers\RouterManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'omada:test', description: 'Test the Omada OpenAPI login, sites and devices for a router')]
class OmadaTestCommand extends Command
{
    protected $signature = 'omada:test {router? : Router ID or database id from mikrotiks}';

    public function handle(): int
    {
        $manager = app(RouterManager::class);
        $id = $this->argument('router');
        $row = $id ? $manager->row((string) $id) : null;

        if (! $id) {
            // Allow testing the global OMADA_* config before a router is added.
            $row = [
                'router_id' => 'env',
                'router_name' => 'Omada (env)',
                'driver' => 'omada',
                'omada_mode' => env('OMADA_MODE', 'onprem'),
                'omada_controller_url' => env('OMADA_CONTROLLER_URL'),
                'omada_site_id' => env('OMADA_SITE_ID'),
                'omada_username' => env('OMADA_USERNAME', 'admin'),
                'password' => env('OMADA_PASSWORD'),
                'omada_client_id' => env('OMADA_CLIENT_ID'),
                'omada_client_secret' => env('OMADA_CLIENT_SECRET'),
                'host' => env('OMADA_HOST', ''),
                'omada_port' => env('OMADA_PORT', 8043),
            ];
            $this->line('Testing global OMADA_* configuration (no router id given).');
        } elseif (! $row) {
            $this->error('Router not found. Pass a router id: php artisan omada:test <router-id>');

            return self::FAILURE;
        }
        if ($manager->driverFor($row)->name() !== 'omada') {
            $this->error('This router is not configured for Omada. Set mikrotiks.driver = omada.');

            return self::FAILURE;
        }

        try {
            $session = $manager->connect($row);
            /** @var \App\Services\Routers\OmadaClient $client */
            $client = $session->connection;
            $client->authenticate();
            $this->info('Omada login OK');
            $this->line('Base URL: '.$client->baseUrl());
            $this->line('Mode: '.$client->mode().' | Site: '.($client->siteId() ?: '(auto)'));

            $sites = $client->sites();
            $this->line('Sites: '.count($sites));
            foreach (array_slice($sites, 0, 5) as $site) {
                $this->line('  - '.($site['name'] ?? $site['id'] ?? 'unknown').' ['.($site['id'] ?? $site['siteId'] ?? '?').']');
            }

            $devices = $client->devices();
            $this->line('Devices: '.count($devices));
            foreach (array_slice($devices, 0, 5) as $device) {
                $this->line('  - '.($device['name'] ?? $device['deviceId'] ?? 'unknown'));
            }
            if ($client->lastError()) {
                $this->warn('Last API error: '.$client->lastError());
            }
            if (! $sites && ! $devices) {
                $this->warn('No data returned. Check OMADA_* config and endpoint compatibility for this firmware.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Omada connection failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
