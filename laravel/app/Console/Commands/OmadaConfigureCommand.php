<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'omada:configure', description: 'Store Omada controller credentials in the local .env (never committed)')]
class OmadaConfigureCommand extends Command
{
    protected $signature = 'omada:configure {--cloud : Configure Omada Cloud} {--onprem : Configure an on-prem controller}';

    public function handle(): int
    {
        $mode = $this->option('cloud') ? 'cloud' : ($this->option('onprem') ? 'onprem' : $this->choice(
            'Controller mode',
            ['cloud', 'onprem'],
            0
        ));

        $values = [
            'OMADA_MODE' => $mode,
            'OMADA_SITE_ID' => (string) $this->ask('Site ID', ''),
        ];

        if ($mode === 'cloud') {
            $values['OMADA_CLOUD_URL'] = (string) $this->ask('Cloud API URL', 'https://omada.tplinknetwork.com');
            $values['OMADA_CLIENT_ID'] = (string) $this->ask('Client ID', '');
            $values['OMADA_CLIENT_SECRET'] = (string) $this->secret('Client Secret');
        } else {
            $values['OMADA_CONTROLLER_URL'] = (string) $this->ask('Controller URL', 'https://192.168.88.10:8043');
            $values['OMADA_HOST'] = (string) $this->ask('Controller host/IP (used if URL is empty)', '192.168.88.10');
            $values['OMADA_USERNAME'] = (string) $this->ask('Username', 'admin');
            $values['OMADA_PASSWORD'] = (string) $this->secret('Password');
            $values['OMADA_VERIFY_TLS'] = $this->confirm('Verify TLS certificate?', false) ? 'true' : 'false';
        }

        $this->writeEnv($values);

        $this->info('Omada configuration saved to laravel/.env (this file is gitignored).');
        $this->line('Next: php artisan omada:test');

        return self::SUCCESS;
    }

    private function writeEnv(array $values): void
    {
        $path = base_path('.env');
        if (! is_file($path)) {
            throw new \RuntimeException('laravel/.env not found. Copy .env.example to .env first.');
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $seen = [];
        foreach ($lines as &$line) {
            foreach ($values as $key => $value) {
                if (str_starts_with($line, $key.'=')) {
                    $line = $key.'='.$value;
                    $seen[$key] = true;
                }
            }
        }
        unset($line);
        foreach ($values as $key => $value) {
            if (! isset($seen[$key])) {
                $lines[] = $key.'='.$value;
            }
        }
        file_put_contents($path, implode("\n", $lines)."\n");
    }
}
