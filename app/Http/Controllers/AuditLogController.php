<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use App\Services\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(Request $request, Access $access): Response
    {
        abort_unless($access->canViewAuditLog($request->user()), 403);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'user_id' => $request->integer('user_id') ?: null,
            'event' => array_key_exists((string) $request->query('event'), ActivityLog::EVENTS) ? (string) $request->query('event') : null,
            'module' => (string) $request->query('module', '') ?: null,
            'project_id' => $request->integer('project_id') ?: null,
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
            'batch' => Str::isUuid((string) $request->query('batch')) ? (string) $request->query('batch') : null,
        ];

        $logs = ActivityLog::query()
            ->with(['user:id,name,email', 'project:id,project_name,project_code'])
            ->when($filters['user_id'], fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filters['event'], fn (Builder $query, string $event) => $event === ActivityLog::EVENT_ACTION
                ? $query->where(fn (Builder $inner) => $inner->whereNull('event')->orWhere('event', $event))
                : $query->where('event', $event))
            ->when($filters['module'], fn (Builder $query, string $module) => $query->where('subject_type', $module))
            ->when($filters['project_id'], fn (Builder $query, int $id) => $query->where('project_id', $id))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('created_at', '>=', $this->utc($from.' 00:00:00')))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->where('created_at', '<=', $this->utc($to.' 23:59:59')))
            ->when($filters['batch'], fn (Builder $query, string $batch) => $query->where('batch_id', $batch))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('action', 'like', $term)
                    ->orWhere('subject_label', 'like', $term)
                    ->orWhere('ip_address', 'like', $term)
                    ->orWhere('url', 'like', $term)
                    ->orWhere('properties', 'like', $term)
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('AuditLog/Index', [
            'filters' => $filters,
            'logs' => $logs->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at?->copy()->timezone(config('app.display_timezone'))->format('d M Y, h:i:s A'),
                'user' => $log->user?->name,
                'user_email' => $log->user?->email,
                'event' => $log->event ?? ActivityLog::EVENT_ACTION,
                'action' => $log->action,
                'module' => $log->subject_type ? Str::headline(class_basename($log->subject_type)) : null,
                'subject_id' => $log->subject_id,
                'subject_label' => $log->subject_label,
                'project' => $log->project ? $log->project->project_code.' · '.$log->project->project_name : null,
                'properties' => $log->properties,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'method' => $log->method,
                'url' => $log->url,
                'batch_id' => $log->batch_id,
            ]),
            'events' => collect(ActivityLog::EVENTS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            'modules' => ActivityLog::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')
                ->map(fn (string $type) => ['key' => $type, 'label' => Str::headline(class_basename($type))])->values(),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::query()->orderBy('project_name')->get(['id', 'project_name', 'project_code']),
        ]);
    }

    private function utc(string $local): string
    {
        return Carbon::parse($local, config('app.display_timezone'))->timezone(config('app.timezone'))->toDateTimeString();
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
