<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ActivityLogger
{
    private const LABEL_FIELDS = [
        'pr_number', 'po_number', 'work_order_number', 'receipt_number', 'invoice_number', 'issue_number',
        'adjustment_number', 'note_number', 'idn_number', 'boq_number', 'revision_number', 'reference_number',
        'project_code', 'vendor_code', 'material_code', 'location_code', 'project_name', 'name', 'email', 'title', 'description',
    ];

    private const IGNORED_CHANGES = ['updated_at', 'created_at', 'remember_token', 'last_login_at'];

    /**
     * @param  array<string, mixed>|null  $properties
     */
    public function log(?int $userId, string $action, ?Model $subject = null, ?array $properties = null): void
    {
        $this->write($userId, $action, ActivityLog::EVENT_ACTION, $subject, $properties);
    }

    public function recordModel(string $event, Model $model): void
    {
        $properties = match ($event) {
            ActivityLog::EVENT_UPDATED => $this->changes($model),
            default => ['attributes' => $this->clean($model, $model->getAttributes())],
        };

        if ($event === ActivityLog::EVENT_UPDATED && $properties === null) {
            return;
        }

        $this->write(auth()->id(), Str::snake(class_basename($model)).'.'.$event, $event, $model, $properties);
    }

    /**
     * @param  array<string, mixed>|null  $properties
     */
    public function auth(string $event, ?Model $user, ?array $properties = null): void
    {
        $this->write($user?->getKey(), 'auth.'.$event, $event, $user, $properties);
    }

    /**
     * @param  array<string, mixed>|null  $properties
     */
    private function write(?int $userId, string $action, string $event, ?Model $subject, ?array $properties): void
    {
        ActivityLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'event' => $event,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject ? $this->label($subject) : null,
            'project_id' => $subject ? $this->projectId($subject) : null,
            'properties' => $properties,
            ...$this->context(),
        ]);
    }

    /**
     * @return array{old: array<string, mixed>, new: array<string, mixed>}|null
     */
    private function changes(Model $model): ?array
    {
        $new = array_diff_key($model->getChanges(), array_flip(self::IGNORED_CHANGES));

        if ($new === []) {
            return null;
        }

        $old = [];
        foreach (array_keys($new) as $key) {
            $old[$key] = $model->getRawOriginal($key);
        }

        return ['old' => $this->clean($model, $old), 'new' => $this->clean($model, $new)];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function clean(Model $model, array $values): array
    {
        $hidden = $model->getHidden();

        foreach ($values as $key => $value) {
            if (in_array($key, $hidden, true) || preg_match('/password|token|secret/i', $key)) {
                $values[$key] = $value === null ? null : '[hidden]';
            } elseif (is_string($value) && mb_strlen($value) > 1000) {
                $values[$key] = mb_substr($value, 0, 1000).'…';
            }
        }

        return $values;
    }

    private function label(Model $model): ?string
    {
        $attributes = $model->getAttributes();
        $parts = [];

        foreach (self::LABEL_FIELDS as $field) {
            $value = $attributes[$field] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $parts[] = trim((string) $value);

            if (count($parts) === 2 || in_array($field, ['project_name', 'name', 'email', 'title', 'description'], true)) {
                break;
            }
        }

        return $parts === [] ? null : Str::limit(implode(' · ', array_unique($parts)), 180);
    }

    private function projectId(Model $model): ?int
    {
        if ($model instanceof Project) {
            return $model->getKey();
        }

        $id = $model->getAttributes()['project_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<string, string|null>
     */
    private function context(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return ['method' => 'CLI', 'url' => implode(' ', array_slice($_SERVER['argv'] ?? [], 0, 3)) ?: null];
        }

        $request = request();

        if (! $request->attributes->has('audit_batch_id')) {
            $request->attributes->set('audit_batch_id', (string) Str::uuid());
        }

        return [
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
            'method' => $request->method(),
            'url' => Str::limit($request->fullUrl(), 2000, ''),
            'batch_id' => $request->attributes->get('audit_batch_id'),
        ];
    }
}
