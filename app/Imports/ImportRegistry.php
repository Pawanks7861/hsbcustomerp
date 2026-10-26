<?php

namespace App\Imports;

use App\Imports\Contracts\ImportDefinition;
use App\Imports\Definitions\CreditNotesImport;
use App\Imports\Definitions\DebitNotesImport;
use App\Imports\Definitions\ExpenseCategoriesImport;
use App\Imports\Definitions\ExpensesImport;
use App\Imports\Definitions\InventoryLocationsImport;
use App\Imports\Definitions\CentralStoreStockImport;
use App\Imports\Definitions\MaterialsImport;
use App\Imports\Definitions\ProjectsImport;
use App\Imports\Definitions\PurchaseOrdersImport;
use App\Imports\Definitions\PurchaseRequestsImport;
use App\Imports\Definitions\StockAdjustmentsImport;
use App\Imports\Definitions\UsersImport;
use App\Imports\Definitions\VendorsImport;
use App\Imports\Definitions\WorkOrdersImport;
use InvalidArgumentException;

class ImportRegistry
{
    /** @var array<string, class-string<ImportDefinition>> */
    private array $map = [
        'vendors' => VendorsImport::class,
        'projects' => ProjectsImport::class,
        'materials' => MaterialsImport::class,
        'central_store_stock' => CentralStoreStockImport::class,
        'stock_adjustments' => StockAdjustmentsImport::class,
        'expense_categories' => ExpenseCategoriesImport::class,
        'inventory_locations' => InventoryLocationsImport::class,
        'expenses' => ExpensesImport::class,
        'users' => UsersImport::class,
        'purchase_requests' => PurchaseRequestsImport::class,
        'purchase_orders' => PurchaseOrdersImport::class,
        'work_orders' => WorkOrdersImport::class,
        'debit_notes' => DebitNotesImport::class,
        'credit_notes' => CreditNotesImport::class,
    ];

    public function get(string $key): ImportDefinition
    {
        if (! isset($this->map[$key])) {
            throw new InvalidArgumentException("Unknown import module [{$key}].");
        }

        return app($this->map[$key]);
    }

    /**
     * @return list<array{key: string, label: string, permission: string}>
     */
    public function catalog(): array
    {
        $items = [];

        foreach ($this->map as $key => $class) {
            /** @var ImportDefinition $definition */
            $definition = app($class);
            $items[] = [
                'key' => $definition->key(),
                'label' => $definition->label(),
                'permission' => $definition->permission(),
            ];
        }

        // Existing specialized BOQ import remains on BOQ show pages.
        $items[] = [
            'key' => 'boq',
            'label' => 'BOQ Items (existing Excel import)',
            'permission' => 'boq.import',
        ];

        return $items;
    }

    public function has(string $key): bool
    {
        return isset($this->map[$key]);
    }
}
