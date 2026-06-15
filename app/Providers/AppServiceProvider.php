<?php

namespace App\Providers;

use App\Repositories\DeviceModelRepository;
use App\Repositories\SupplierRepository;
use App\Services\DeviceModelService;
use App\Services\SupplierService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SupplierRepository::class);
        $this->app->singleton(DeviceModelRepository::class);

        $this->app->singleton(SupplierService::class);
        $this->app->singleton(DeviceModelService::class);
    }

    public function boot(): void
    {
    }
}
