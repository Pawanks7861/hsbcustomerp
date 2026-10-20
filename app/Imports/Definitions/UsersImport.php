<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsersImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'Users';
    }

    public function permission(): string
    {
        return 'users.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY, DuplicateMode::SKIP_DUPLICATES];
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('name', 'Name', true, 'string', max: 255),
            new ColumnDefinition('email', 'Email', true, 'string', max: 255),
            new ColumnDefinition('phone', 'Phone', false, 'string', max: 30),
            new ColumnDefinition('designation', 'Designation', false, 'string', max: 100),
            new ColumnDefinition('role_slug', 'Role Slug', true, 'string', ['role'], 50, help: 'e.g. viewer, accounts, storekeeper'),
            new ColumnDefinition('status', 'Status', false, 'enum', allowed: [User::STATUS_ACTIVE, User::STATUS_INACTIVE]),
            new ColumnDefinition('temporary_password', 'Temporary Password', false, 'string', max: 100, help: 'Optional. A random password is generated if blank.'),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'name' => 'Demo User',
            'email' => 'demo.user@example.com',
            'role_slug' => 'viewer',
            'status' => 'inactive',
            'temporary_password' => 'ChangeMe123!',
        ]];
    }

    public function instructions(): string
    {
        return 'Creates users only (no silent updates). Default status is inactive. Role Slug must match an existing role. Admin flags are never set by import.';
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        $data = $cast['data'];
        $errors = $cast['errors'];

        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is invalid.';
        }

        $existing = ! empty($data['email'])
            ? User::query()->where('email', $data['email'])->first()
            : null;

        $role = ! empty($data['role_slug'])
            ? Role::query()->where('slug', $data['role_slug'])->where('is_active', true)->first()
            : null;

        if (! $role) {
            $errors[] = 'Role Slug was not found or is inactive.';
        } else {
            $data['role_id'] = $role->id;
        }

        if (in_array(strtolower((string) ($data['role_slug'] ?? '')), ['super_admin', 'administrator'], true)
            && ! $context->user->isSuperAdmin()) {
            $errors[] = 'Only a super admin can assign administrator roles via import.';
        }

        if ($errors !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors);
        }

        $decision = $this->decideAction($context, $existing?->id);
        if ($decision['error'] || $decision['action'] === 'update') {
            return new RowOutcome(
                $rowNumber,
                $decision['status'],
                $data,
                $decision['error'] ? [$decision['error']] : ['User updates are not supported via import.'],
                $data['email'],
                $existing?->id,
            );
        }

        return new RowOutcome($rowNumber, $decision['status'], $data, [], $data['email'], $existing?->id, $decision['action']);
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        if ($row->action === 'skip') {
            return 'skipped';
        }

        $password = $row->data['temporary_password'] ?: Str::password(12);

        $user = User::query()->create([
            'name' => $row->data['name'],
            'email' => $row->data['email'],
            'phone' => $row->data['phone'],
            'designation' => $row->data['designation'],
            'status' => $row->data['status'] ?? User::STATUS_INACTIVE,
            'is_admin' => false,
            'project_access' => 'all',
            'password' => Hash::make($password),
        ]);

        $user->roles()->sync([$row->data['role_id']]);

        return 'created';
    }
}
