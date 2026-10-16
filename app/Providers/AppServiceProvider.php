<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\InternalDeliveryNote;
use App\Models\InventoryLocation;
use App\Models\Project;
use App\Services\ActivityLogger;
use App\Services\InventoryLocationService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

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
        Route::bind('note', function (string $value, \Illuminate\Routing\Route $route) {
            $name = $route->getName() ?? '';

            if (str_contains($name, 'debit-notes')) {
                return DebitNote::query()->findOrFail($value);
            }

            if (str_contains($name, 'credit-notes')) {
                return CreditNote::query()->findOrFail($value);
            }

            abort(404);
        });

        Project::created(function (Project $project): void {
            app(InventoryLocationService::class)->ensureProjectStore($project);
        });

        $this->registerAuditTrail();
    }

    private function registerAuditTrail(): void
    {
        $audit = function (callable $write): void {
            try {
                $write(app(ActivityLogger::class));
            } catch (Throwable $exception) {
                report($exception);
            }
        };

        Event::listen(['eloquent.created: *', 'eloquent.updated: *', 'eloquent.deleted: *'], function (string $name, array $payload) use ($audit): void {
            $model = $payload[0] ?? null;

            if (! $model instanceof Model || $model instanceof ActivityLog || ! str_starts_with($model::class, 'App\\Models\\')) {
                return;
            }

            $event = Str::between($name, 'eloquent.', ':');
            $audit(fn (ActivityLogger $logger) => $logger->recordModel($event, $model));
        });

        Event::listen(Login::class, fn (Login $event) => $audit(fn (ActivityLogger $logger) => $logger->auth(ActivityLog::EVENT_LOGIN, $event->user, ['remember' => $event->remember])));
        Event::listen(Logout::class, fn (Logout $event) => $audit(fn (ActivityLogger $logger) => $logger->auth(ActivityLog::EVENT_LOGOUT, $event->user)));
        Event::listen(Failed::class, fn (Failed $event) => $audit(fn (ActivityLogger $logger) => $logger->auth(ActivityLog::EVENT_LOGIN_FAILED, $event->user, ['email' => $event->credentials['email'] ?? null])));
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => $audit(fn (ActivityLogger $logger) => $logger->auth(ActivityLog::EVENT_PASSWORD_RESET, $event->user)));
    }
}
