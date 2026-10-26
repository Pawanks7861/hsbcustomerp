<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\InternalDeliveryNote;
use App\Models\InventoryLocation;
use App\Models\Project;
use App\Models\User;
use App\Services\Access;
use App\Services\ActivityLogger;
use App\Services\InventoryLocationService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
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
        Route::bind('transfer', function (string $value, \Illuminate\Routing\Route $route) {
            $note = InternalDeliveryNote::query()->findOrFail($value);
            $project = $route->parameter('project');
            if ($project instanceof Project) {
                $projectId = (int) $project->id;
                abort_unless(
                    (int) $note->source_project_id === $projectId
                    || (int) $note->destination_project_id === $projectId,
                    404
                );
            }

            $user = auth()->user();
            if ($user instanceof User) {
                app(Access::class)->abortUnlessCanResolveTransfer($user, $note);
            }

            return $note;
        });
        Route::bind('location', function (string $value) {
            $location = InventoryLocation::query()->findOrFail($value);
            $user = auth()->user();
            if ($user instanceof User) {
                app(Access::class)->abortUnlessCanAccessLocation($user, $location);
            }

            return $location;
        });
        Route::bind('note', function (string $value, \Illuminate\Routing\Route $route) {
            $name = $route->getName() ?? '';
            $query = match (true) {
                str_contains($name, 'debit-notes') => DebitNote::query(),
                str_contains($name, 'credit-notes') => CreditNote::query(),
                default => abort(404),
            };

            $project = $route->parameter('project');
            $projectId = $project instanceof Project
                ? (int) $project->id
                : (is_numeric($project) ? (int) $project : null);
            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            return $query->findOrFail($value);
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
