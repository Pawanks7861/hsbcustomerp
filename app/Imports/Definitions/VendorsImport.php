<?php

namespace App\Imports\Definitions;

use App\Imports\AbstractImportDefinition;
use App\Imports\Support\ColumnDefinition;
use App\Imports\Support\DuplicateMode;
use App\Imports\Support\ImportContext;
use App\Imports\Support\RowOutcome;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

class VendorsImport extends AbstractImportDefinition
{
    public function key(): string
    {
        return 'vendors';
    }

    public function label(): string
    {
        return 'Vendors';
    }

    public function permission(): string
    {
        return 'vendors.import';
    }

    public function supportedModes(): array
    {
        return [DuplicateMode::CREATE_ONLY, DuplicateMode::SKIP_DUPLICATES, DuplicateMode::UPDATE_MATCHED, DuplicateMode::UPSERT];
    }

    public function columns(): array
    {
        return [
            new ColumnDefinition('vendor_code', 'Vendor Code', false, 'string', ['code'], 40, help: 'Required to update existing vendors.'),
            new ColumnDefinition('name', 'Name', true, 'string', max: 255),
            new ColumnDefinition('party_type', 'Party Type', false, 'enum', ['type'], allowed: [Vendor::VENDOR, Vendor::CONTRACTOR, Vendor::BOTH]),
            new ColumnDefinition('contact_person', 'Contact Person', false, 'string', max: 255),
            new ColumnDefinition('phone', 'Phone', false, 'string', max: 30),
            new ColumnDefinition('email', 'Email', false, 'string', max: 255),
            new ColumnDefinition('address', 'Address', false, 'string', max: 2000),
            new ColumnDefinition('city', 'City', false, 'string', max: 100),
            new ColumnDefinition('state', 'State', false, 'string', max: 100),
            new ColumnDefinition('pincode', 'Pincode', false, 'string', max: 12),
            new ColumnDefinition('gst_number', 'GST Number', false, 'string', ['gst'], 20),
            new ColumnDefinition('pan_number', 'PAN Number', false, 'string', ['pan'], 20),
            new ColumnDefinition('bank_name', 'Bank Name', false, 'string', max: 255),
            new ColumnDefinition('account_number', 'Account Number', false, 'string', max: 40),
            new ColumnDefinition('ifsc', 'IFSC', false, 'string', max: 20),
            new ColumnDefinition('opening_advance', 'Opening Advance', false, 'decimal'),
            new ColumnDefinition('opening_outstanding', 'Opening Outstanding', false, 'decimal'),
            new ColumnDefinition('status', 'Status', false, 'enum', allowed: [Vendor::ACTIVE, Vendor::INACTIVE]),
            new ColumnDefinition('notes', 'Notes', false, 'string', max: 5000),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'vendor_code' => 'V-1001',
            'name' => 'Acme Supplies',
            'party_type' => 'vendor',
            'contact_person' => 'Ravi',
            'phone' => '9876543210',
            'email' => 'acme@example.com',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'gst_number' => '27AAAAA0000A1Z5',
            'status' => 'active',
        ]];
    }

    public function instructions(): string
    {
        return 'Match existing vendors by Vendor Code when using update modes. Opening balances are set only on create. Party Type: vendor, contractor, or both.';
    }

    public function validateRow(int $rowNumber, array $raw, ImportContext $context): RowOutcome
    {
        $cast = $this->castRow($raw);
        $errors = $cast['errors'];
        $data = $cast['data'];

        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is invalid.';
        }

        if (($data['opening_advance'] ?? 0) < 0 || ($data['opening_outstanding'] ?? 0) < 0) {
            $errors[] = 'Opening amounts cannot be negative.';
        }

        $existing = null;
        if (! empty($data['vendor_code'])) {
            $existing = Vendor::query()->where('vendor_code', $data['vendor_code'])->first();
        }

        if ($errors !== []) {
            return new RowOutcome($rowNumber, RowOutcome::INVALID, $data, $errors);
        }

        $decision = $this->decideAction($context, $existing?->id);

        if ($decision['error']) {
            return new RowOutcome($rowNumber, $decision['status'], $data, [$decision['error']], $data['vendor_code'] ?? $data['name'], $existing?->id);
        }

        return new RowOutcome(
            $rowNumber,
            $decision['status'],
            $data,
            [],
            $data['vendor_code'] ?? $data['name'],
            $existing?->id,
            $decision['action'],
        );
    }

    public function commit(RowOutcome $row, ImportContext $context): string
    {
        $data = $row->data;
        $data['party_type'] = $data['party_type'] ?? Vendor::VENDOR;
        $data['status'] = $data['status'] ?? Vendor::ACTIVE;
        $data['opening_advance'] = $data['opening_advance'] ?? 0;
        $data['opening_outstanding'] = $data['opening_outstanding'] ?? 0;

        if ($row->action === 'update' && $row->existingId) {
            $vendor = Vendor::query()->findOrFail($row->existingId);
            unset($data['opening_advance'], $data['opening_outstanding'], $data['vendor_code']);
            $vendor->update(array_filter($data, fn ($v) => $v !== null));

            return 'updated';
        }

        return DB::transaction(function () use ($data) {
            if (empty($data['vendor_code'])) {
                $data['vendor_code'] = $this->nextCode();
            }

            Vendor::query()->create($data);

            return 'created';
        });
    }

    private function nextCode(): string
    {
        $latest = Vendor::query()->orderByDesc('id')->lockForUpdate()->value('vendor_code');
        $sequence = 1;
        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $m)) {
            $sequence = ((int) $m[1]) + 1;
        }

        return 'V-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
