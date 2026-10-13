<?php

namespace App\Providers;

use App\Models\InternalDeliveryNote;
use App\Models\InventoryLocation;
use App\Models\Project;
use App\Services\InventoryLocationService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Route::bind('transfer', fn (string $value) => InternalDeliveryNote::query()->findOrFail($value));
        Route::bind('location', fn (string $value) => InventoryLocation::query()->findOrFail($value));

        Project::created(function (Project $project): void {
            app(InventoryLocationService::class)->ensureProjectStore($project);
        });
    }
}
