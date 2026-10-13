<?php

use App\Http\Controllers\BoqController;
use App\Http\Controllers\BoqImportController;
use App\Http\Controllers\BoqRevisionController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\InternalDeliveryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryLocationController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MaterialIssueController;
use App\Http\Controllers\MaterialReceiptController;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorPaymentController;
use App\Http\Controllers\VendorRecoveryController;
use App\Http\Controllers\BoqItemBudgetController;
use App\Http\Controllers\BoqItemProcurementController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'project.access'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::resource('projects', ProjectController::class);

    Route::get('vendors/report', [VendorController::class, 'report'])->name('vendors.report');
    Route::patch('vendors/{vendor}/status', [VendorController::class, 'updateStatus'])->name('vendors.status');
    Route::get('vendors/{vendor}/payments/create', [VendorPaymentController::class, 'create'])->name('vendors.payments.create');
    Route::post('vendors/{vendor}/payments', [VendorPaymentController::class, 'store'])->name('vendors.payments.store');
    Route::delete('vendors/{vendor}/payments/{payment}', [VendorPaymentController::class, 'destroy'])->name('vendors.payments.destroy');
    Route::post('vendors/{vendor}/recoveries', [VendorRecoveryController::class, 'store'])->name('vendors.recoveries.store');
    Route::post('vendors/{vendor}/recoveries/{recovery}/refunds', [VendorRecoveryController::class, 'receive'])->name('vendors.recoveries.refunds.store');
    Route::resource('vendors', VendorController::class);

    Route::scopeBindings()->group(function () {
        Route::resource('projects.boqs', BoqController::class)->except(['edit']);

        Route::post('projects/{project}/boqs/{boq}/revisions', [BoqRevisionController::class, 'store'])
            ->name('projects.boqs.revisions.store');
        Route::post('projects/{project}/boqs/{boq}/revisions/{revision}/finalize', [BoqRevisionController::class, 'finalize'])
            ->name('projects.boqs.revisions.finalize');

        Route::get('projects/{project}/boqs/{boq}/template', [BoqImportController::class, 'template'])
            ->name('projects.boqs.template');
        Route::get('projects/{project}/boqs/{boq}/import', [BoqImportController::class, 'create'])
            ->name('projects.boqs.import');
        Route::post('projects/{project}/boqs/{boq}/import', [BoqImportController::class, 'preview'])
            ->name('projects.boqs.import.preview');
        Route::post('projects/{project}/boqs/{boq}/import/confirm', [BoqImportController::class, 'confirm'])
            ->name('projects.boqs.import.confirm');
        Route::delete('projects/{project}/boqs/{boq}/import', [BoqImportController::class, 'cancel'])
            ->name('projects.boqs.import.cancel');

        Route::get('projects/{project}/boqs/{boq}/items/{item}/budget', [BoqItemBudgetController::class, 'edit'])
            ->name('projects.boqs.items.budget.edit');
        Route::put('projects/{project}/boqs/{boq}/items/{item}/budget', [BoqItemBudgetController::class, 'update'])
            ->name('projects.boqs.items.budget.update');
        Route::delete('projects/{project}/boqs/{boq}/items/{item}/budgets/{budget}', [BoqItemBudgetController::class, 'destroy'])
            ->name('projects.boqs.items.budgets.destroy');

        Route::get('projects/{project}/boqs/{boq}/items/{item}/procurement', [BoqItemProcurementController::class, 'show'])
            ->name('projects.boqs.items.procurement');

        Route::get('projects/{project}/boqs/{boq}/items/{item}/purchase-requests/create', [PurchaseRequestController::class, 'create'])
            ->name('projects.boqs.items.purchase-requests.create');
        Route::post('projects/{project}/boqs/{boq}/items/{item}/purchase-requests', [PurchaseRequestController::class, 'store'])
            ->name('projects.boqs.items.purchase-requests.store');

        Route::get('projects/{project}/purchase-requests', [PurchaseRequestController::class, 'index'])
            ->name('projects.purchase-requests.index');
        Route::get('projects/{project}/purchase-requests/create', [PurchaseRequestController::class, 'createMany'])
            ->name('projects.purchase-requests.create');
        Route::post('projects/{project}/purchase-requests', [PurchaseRequestController::class, 'storeMany'])
            ->name('projects.purchase-requests.store');
        Route::get('projects/{project}/purchase-requests/{purchase_request}/pdf/download', [PurchaseRequestController::class, 'downloadPdf'])
            ->name('projects.purchase-requests.pdf.download');
        Route::get('projects/{project}/purchase-requests/{purchase_request}/pdf', [PurchaseRequestController::class, 'pdf'])
            ->name('projects.purchase-requests.pdf');
        Route::get('projects/{project}/purchase-requests/{purchase_request}', [PurchaseRequestController::class, 'show'])
            ->name('projects.purchase-requests.show');
        Route::get('projects/{project}/purchase-requests/{purchase_request}/edit', [PurchaseRequestController::class, 'edit'])
            ->name('projects.purchase-requests.edit');
        Route::put('projects/{project}/purchase-requests/{purchase_request}', [PurchaseRequestController::class, 'update'])
            ->name('projects.purchase-requests.update');
        Route::put('projects/{project}/purchase-requests/{purchase_request}/lines', [PurchaseRequestController::class, 'updateMany'])
            ->name('projects.purchase-requests.lines.update');
        Route::delete('projects/{project}/purchase-requests/{purchase_request}', [PurchaseRequestController::class, 'destroy'])
            ->name('projects.purchase-requests.destroy');
        Route::post('projects/{project}/purchase-requests/{purchase_request}/submit', [PurchaseRequestController::class, 'submit'])
            ->name('projects.purchase-requests.submit');
        Route::post('projects/{project}/purchase-requests/{purchase_request}/reopen', [PurchaseRequestController::class, 'reopen'])
            ->name('projects.purchase-requests.reopen');
        Route::post('projects/{project}/purchase-requests/{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])
            ->middleware('permission:purchase_requests.approve')
            ->name('projects.purchase-requests.approve');
        Route::post('projects/{project}/purchase-requests/{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])
            ->middleware('permission:purchase_requests.reject')
            ->name('projects.purchase-requests.reject');

        Route::get('projects/{project}/purchase-orders', [PurchaseOrderController::class, 'index'])
            ->name('projects.purchase-orders.index');
        Route::get('projects/{project}/purchase-requests/{purchase_request}/purchase-orders/create', [PurchaseOrderController::class, 'create'])
            ->name('projects.purchase-requests.purchase-orders.create');
        Route::post('projects/{project}/purchase-requests/{purchase_request}/purchase-orders', [PurchaseOrderController::class, 'store'])
            ->name('projects.purchase-requests.purchase-orders.store');
        Route::get('projects/{project}/purchase-orders/{purchase_order}/pdf/download', [PurchaseOrderController::class, 'downloadPdf'])
            ->name('projects.purchase-orders.pdf.download');
        Route::get('projects/{project}/purchase-orders/{purchase_order}/pdf', [PurchaseOrderController::class, 'pdf'])
            ->name('projects.purchase-orders.pdf');
        Route::get('projects/{project}/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])
            ->name('projects.purchase-orders.show');
        Route::get('projects/{project}/purchase-orders/{purchase_order}/edit', [PurchaseOrderController::class, 'edit'])
            ->name('projects.purchase-orders.edit');
        Route::put('projects/{project}/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'update'])
            ->name('projects.purchase-orders.update');
        Route::delete('projects/{project}/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'destroy'])
            ->name('projects.purchase-orders.destroy');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/submit', [PurchaseOrderController::class, 'submit'])
            ->name('projects.purchase-orders.submit');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/reopen', [PurchaseOrderController::class, 'reopen'])
            ->name('projects.purchase-orders.reopen');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])
            ->middleware('permission:purchase_orders.approve')
            ->name('projects.purchase-orders.approve');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/reject', [PurchaseOrderController::class, 'reject'])
            ->middleware('permission:purchase_orders.reject')
            ->name('projects.purchase-orders.reject');

        Route::get('projects/{project}/work-orders', [WorkOrderController::class, 'index'])
            ->middleware('permission:work_orders.view')
            ->name('projects.work-orders.index');
        Route::get('projects/{project}/work-orders/create', [WorkOrderController::class, 'create'])
            ->middleware('permission:work_orders.create')
            ->name('projects.work-orders.create');
        Route::post('projects/{project}/work-orders', [WorkOrderController::class, 'store'])
            ->middleware('permission:work_orders.create')
            ->name('projects.work-orders.store');
        Route::get('projects/{project}/work-orders/{work_order}/pdf/download', [WorkOrderController::class, 'downloadPdf'])
            ->middleware('permission:work_orders.print')
            ->name('projects.work-orders.pdf.download');
        Route::get('projects/{project}/work-orders/{work_order}/pdf', [WorkOrderController::class, 'pdf'])
            ->middleware('permission:work_orders.print')
            ->name('projects.work-orders.pdf');
        Route::get('projects/{project}/work-orders/{work_order}', [WorkOrderController::class, 'show'])
            ->middleware('permission:work_orders.view')
            ->name('projects.work-orders.show');
        Route::get('projects/{project}/work-orders/{work_order}/edit', [WorkOrderController::class, 'edit'])
            ->middleware('permission:work_orders.edit')
            ->name('projects.work-orders.edit');
        Route::put('projects/{project}/work-orders/{work_order}', [WorkOrderController::class, 'update'])
            ->middleware('permission:work_orders.edit')
            ->name('projects.work-orders.update');
        Route::post('projects/{project}/work-orders/{work_order}/submit', [WorkOrderController::class, 'submit'])
            ->middleware('permission:work_orders.submit')
            ->name('projects.work-orders.submit');
        Route::post('projects/{project}/work-orders/{work_order}/approve', [WorkOrderController::class, 'approve'])
            ->middleware('permission:work_orders.approve')
            ->name('projects.work-orders.approve');
        Route::post('projects/{project}/work-orders/{work_order}/reject', [WorkOrderController::class, 'reject'])
            ->middleware('permission:work_orders.reject')
            ->name('projects.work-orders.reject');
        Route::post('projects/{project}/work-orders/{work_order}/issue', [WorkOrderController::class, 'issue'])
            ->middleware('permission:work_orders.issue')
            ->name('projects.work-orders.issue');
        Route::post('projects/{project}/work-orders/{work_order}/execute', [WorkOrderController::class, 'execute'])
            ->middleware('permission:work_orders.execute')
            ->name('projects.work-orders.execute');
        Route::post('projects/{project}/work-orders/{work_order}/amend', [WorkOrderController::class, 'amend'])
            ->middleware('permission:work_orders.amend')
            ->name('projects.work-orders.amend');
        Route::post('projects/{project}/work-orders/{work_order}/cancel', [WorkOrderController::class, 'cancel'])
            ->middleware('permission:work_orders.cancel')
            ->name('projects.work-orders.cancel');

        Route::get('projects/{project}/store', [MaterialReceiptController::class, 'index'])->name('projects.store.index');
        Route::get('projects/{project}/store/create', [MaterialReceiptController::class, 'create'])->name('projects.store.create');
        Route::post('projects/{project}/store', [MaterialReceiptController::class, 'store'])->name('projects.store.store');
        Route::get('projects/{project}/store/{receipt}/pdf/download', [MaterialReceiptController::class, 'downloadPdf'])->name('projects.store.pdf.download');
        Route::get('projects/{project}/store/{receipt}/pdf', [MaterialReceiptController::class, 'pdf'])->name('projects.store.pdf');
        Route::get('projects/{project}/store/{receipt}', [MaterialReceiptController::class, 'show'])->name('projects.store.show');
        Route::get('projects/{project}/store/{receipt}/edit', [MaterialReceiptController::class, 'edit'])->name('projects.store.edit');
        Route::put('projects/{project}/store/{receipt}', [MaterialReceiptController::class, 'update'])->name('projects.store.update');
        Route::delete('projects/{project}/store/{receipt}', [MaterialReceiptController::class, 'destroy'])->name('projects.store.destroy');
        Route::get('projects/{project}/store/{receipt}/images/{image}', [MaterialReceiptController::class, 'image'])->name('projects.store.images.show');

        Route::get('projects/{project}/inventory', [InventoryController::class, 'dashboard'])->name('projects.inventory.dashboard');
        Route::get('projects/{project}/inventory/stock', [InventoryController::class, 'stock'])->name('projects.inventory.stock');
        Route::get('projects/{project}/inventory/ledger', [InventoryController::class, 'ledger'])->name('projects.inventory.ledger');

        Route::get('projects/{project}/inventory/issues', [MaterialIssueController::class, 'index'])->name('projects.inventory.issues.index');
        Route::get('projects/{project}/inventory/issues/create', [MaterialIssueController::class, 'create'])->name('projects.inventory.issues.create');
        Route::post('projects/{project}/inventory/issues', [MaterialIssueController::class, 'store'])->name('projects.inventory.issues.store');
        Route::get('projects/{project}/inventory/issues/{material_issue}', [MaterialIssueController::class, 'show'])->name('projects.inventory.issues.show');
        Route::put('projects/{project}/inventory/issues/{material_issue}', [MaterialIssueController::class, 'update'])->name('projects.inventory.issues.update');
        Route::post('projects/{project}/inventory/issues/{material_issue}/post', [MaterialIssueController::class, 'post'])->middleware('permission:inventory.post')->name('projects.inventory.issues.post');
        Route::post('projects/{project}/inventory/issues/{material_issue}/cancel', [MaterialIssueController::class, 'cancel'])->middleware('permission:inventory.post')->name('projects.inventory.issues.cancel');

        Route::get('projects/{project}/inventory/adjustments', [StockAdjustmentController::class, 'index'])->name('projects.inventory.adjustments.index');
        Route::get('projects/{project}/inventory/adjustments/create', [StockAdjustmentController::class, 'create'])->name('projects.inventory.adjustments.create');
        Route::post('projects/{project}/inventory/adjustments', [StockAdjustmentController::class, 'store'])->middleware('permission:inventory.adjust')->name('projects.inventory.adjustments.store');
        Route::get('projects/{project}/inventory/adjustments/{stock_adjustment}/attachment', [StockAdjustmentController::class, 'attachment'])->name('projects.inventory.adjustments.attachment');
        Route::get('projects/{project}/inventory/adjustments/{stock_adjustment}', [StockAdjustmentController::class, 'show'])->name('projects.inventory.adjustments.show');
        Route::post('projects/{project}/inventory/adjustments/{stock_adjustment}/post', [StockAdjustmentController::class, 'post'])->middleware('permission:inventory.adjust')->name('projects.inventory.adjustments.post');

        Route::get('projects/{project}/grns', [GoodsReceiptController::class, 'index'])->name('projects.grns.index');
        Route::get('projects/{project}/purchase-orders/{purchase_order}/grns/create', [GoodsReceiptController::class, 'create'])->name('projects.purchase-orders.grns.create');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/grns', [GoodsReceiptController::class, 'store'])->name('projects.purchase-orders.grns.store');
        Route::get('projects/{project}/grns/{receipt}/pdf/download', [GoodsReceiptController::class, 'downloadPdf'])->name('projects.grns.pdf.download');
        Route::get('projects/{project}/grns/{receipt}/pdf', [GoodsReceiptController::class, 'pdf'])->name('projects.grns.pdf');
        Route::get('projects/{project}/grns/{receipt}/images/{image}', [GoodsReceiptController::class, 'image'])->name('projects.grns.images.show');
        Route::get('projects/{project}/grns/{receipt}', [GoodsReceiptController::class, 'show'])->name('projects.grns.show');
        Route::get('projects/{project}/grns/{receipt}/edit', [GoodsReceiptController::class, 'edit'])->name('projects.grns.edit');
        Route::put('projects/{project}/grns/{receipt}', [GoodsReceiptController::class, 'update'])->name('projects.grns.update');
        Route::post('projects/{project}/grns/{receipt}/submit', [GoodsReceiptController::class, 'submit'])->name('projects.grns.submit');
        Route::post('projects/{project}/grns/{receipt}/post', [GoodsReceiptController::class, 'post'])->middleware('permission:grn.post')->name('projects.grns.post');
        Route::post('projects/{project}/grns/{receipt}/reject', [GoodsReceiptController::class, 'reject'])->middleware('permission:grn.reject')->name('projects.grns.reject');
        Route::post('projects/{project}/grns/{receipt}/cancel', [GoodsReceiptController::class, 'cancel'])->middleware('permission:grn.post')->name('projects.grns.cancel');

        Route::get('projects/{project}/inventory/transfers', [InternalDeliveryController::class, 'index'])->name('projects.inventory.transfers.index');
        Route::get('projects/{project}/inventory/transfers/create', [InternalDeliveryController::class, 'create'])->name('projects.inventory.transfers.create');

        Route::get('projects/{project}/purchase-invoices', [PurchaseInvoiceController::class, 'index'])->name('projects.purchase-invoices.index');
        Route::get('projects/{project}/purchase-invoices/create', [PurchaseInvoiceController::class, 'create'])->name('projects.purchase-invoices.create');
        Route::post('projects/{project}/purchase-invoices', [PurchaseInvoiceController::class, 'store'])->name('projects.purchase-invoices.store');
        Route::get('projects/{project}/purchase-invoices/{invoice}/pdf/download', [PurchaseInvoiceController::class, 'downloadPdf'])->name('projects.purchase-invoices.pdf.download');
        Route::get('projects/{project}/purchase-invoices/{invoice}/pdf', [PurchaseInvoiceController::class, 'pdf'])->name('projects.purchase-invoices.pdf');
        Route::get('projects/{project}/purchase-invoices/{invoice}', [PurchaseInvoiceController::class, 'show'])->name('projects.purchase-invoices.show');
        Route::put('projects/{project}/purchase-invoices/{invoice}', [PurchaseInvoiceController::class, 'update'])->name('projects.purchase-invoices.update');
        Route::delete('projects/{project}/purchase-invoices/{invoice}', [PurchaseInvoiceController::class, 'destroy'])->name('projects.purchase-invoices.destroy');
        Route::get('projects/{project}/purchase-invoices/{invoice}/attachment', [PurchaseInvoiceController::class, 'attachment'])->name('projects.purchase-invoices.attachment');
        Route::post('projects/{project}/purchase-invoices/{invoice}/advance', [PurchaseInvoiceController::class, 'applyAdvance'])->name('projects.purchase-invoices.advance');
    });

    Route::get('inventory/locations', [InventoryLocationController::class, 'index'])->name('inventory.locations.index');
    Route::post('inventory/locations', [InventoryLocationController::class, 'store'])->name('inventory.locations.store');
    Route::put('inventory/locations/{location}', [InventoryLocationController::class, 'update'])->name('inventory.locations.update');
    Route::get('inventory/locations/{location}/stock', [InventoryLocationController::class, 'stock'])->name('inventory.locations.stock');

    Route::get('inventory/transfers', [InternalDeliveryController::class, 'index'])->name('inventory.transfers.index');
    Route::get('inventory/transfers/create', [InternalDeliveryController::class, 'create'])->name('inventory.transfers.create');
    Route::post('inventory/transfers', [InternalDeliveryController::class, 'store'])->name('inventory.transfers.store');
    Route::get('inventory/transfers/{transfer}/pdf/download', [InternalDeliveryController::class, 'downloadPdf'])->name('inventory.transfers.pdf.download');
    Route::get('inventory/transfers/{transfer}/pdf', [InternalDeliveryController::class, 'pdf'])->name('inventory.transfers.pdf');
    Route::get('inventory/transfers/{transfer}/attachments/{attachment}', [InternalDeliveryController::class, 'attachment'])->name('inventory.transfers.attachments.show');
    Route::get('inventory/transfers/{transfer}', [InternalDeliveryController::class, 'show'])->name('inventory.transfers.show');
    Route::get('inventory/transfers/{transfer}/edit', [InternalDeliveryController::class, 'edit'])->name('inventory.transfers.edit');
    Route::put('inventory/transfers/{transfer}', [InternalDeliveryController::class, 'update'])->name('inventory.transfers.update');
    Route::post('inventory/transfers/{transfer}/submit', [InternalDeliveryController::class, 'submit'])->name('inventory.transfers.submit');
    Route::post('inventory/transfers/{transfer}/approve', [InternalDeliveryController::class, 'approve'])->middleware('permission:idn.approve')->name('inventory.transfers.approve');
    Route::post('inventory/transfers/{transfer}/reject', [InternalDeliveryController::class, 'reject'])->middleware('permission:idn.reject')->name('inventory.transfers.reject');
    Route::post('inventory/transfers/{transfer}/dispatch', [InternalDeliveryController::class, 'dispatch'])->middleware('permission:idn.dispatch')->name('inventory.transfers.dispatch');
    Route::post('inventory/transfers/{transfer}/receive', [InternalDeliveryController::class, 'receive'])->middleware('permission:idn.receive')->name('inventory.transfers.receive');
    Route::post('inventory/transfers/{transfer}/cancel', [InternalDeliveryController::class, 'cancel'])->middleware('permission:idn.approve')->name('inventory.transfers.cancel');

    Route::get('projects/{project}/inventory/materials', [MaterialController::class, 'index'])->name('projects.inventory.materials.index');
    Route::post('projects/{project}/inventory/materials', [MaterialController::class, 'store'])->name('projects.inventory.materials.store');
    Route::put('projects/{project}/inventory/materials/{material}', [MaterialController::class, 'update'])->name('projects.inventory.materials.update');
    Route::delete('projects/{project}/inventory/materials/{material}', [MaterialController::class, 'destroy'])->name('projects.inventory.materials.destroy');

    Route::middleware('permission:users.view')->group(function () {
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');
        Route::get('users/{user}/permissions', [UserController::class, 'permissions'])->name('users.permissions.edit');
        Route::put('users/{user}/permissions', [UserController::class, 'updatePermissions'])->name('users.permissions.update');
        Route::resource('users', UserController::class)->except(['show']);
    });

    Route::middleware('permission:roles.view')->group(function () {
        Route::get('roles', [\App\Http\Controllers\RoleController::class, 'index'])->name('roles.index');
        Route::get('roles/create', [\App\Http\Controllers\RoleController::class, 'create'])->name('roles.create');
        Route::post('roles', [\App\Http\Controllers\RoleController::class, 'store'])->name('roles.store');
        Route::get('roles/{role}/edit', [\App\Http\Controllers\RoleController::class, 'edit'])->name('roles.edit');
        Route::put('roles/{role}', [\App\Http\Controllers\RoleController::class, 'update'])->name('roles.update');
    });

    Route::middleware('permission:settings.view')->group(function () {
        Route::get('settings/company', [CompanySettingController::class, 'edit'])->name('settings.company.edit');
        Route::post('settings/company', [CompanySettingController::class, 'update'])->name('settings.company.update');
    });
});

require __DIR__.'/auth.php';
