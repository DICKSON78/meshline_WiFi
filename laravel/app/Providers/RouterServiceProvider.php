<?php

namespace App\Providers;

use App\Services\Routers\MikrotikDriver;
use App\Services\Routers\OmadaDriver;
use App\Services\Routers\RouterManager;
use Illuminate\Support\ServiceProvider;

class RouterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RouterManager::class, function () {
            $manager = new RouterManager();
            if (class_exists(MikrotikDriver::class)) {
                $manager->register(new MikrotikDriver());
            }
            if (class_exists(OmadaDriver::class)) {
                $manager->register(new OmadaDriver());
            }

            return $manager;
        });
    }
}
