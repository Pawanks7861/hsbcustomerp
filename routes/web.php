<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BoqController;
use App\Http\Controllers\BoqImportController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientInvoiceController;
use App\Http\Controllers\BoqItemBudgetController;
use App\Http\Controllers\BoqItemProcurementController;
use App\Http\Controllers\BoqRevisionController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebitNoteController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InternalDeliveryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryLocationController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MaterialIssueController;
use App\Http\Controllers\MaterialReceiptController;
use App\Http\Controllers\MenuTutorialController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorLedgerController;
use App\Http\Controllers\VendorPaymentController;
use App\Http\Controllers\VendorRecoveryController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
| Standalone error pages (full HTML documents).
| Used so Inertia can force a full-page visit instead of showing a modal.
*/
Route::get('/error/403', fn () => response()->view('errors.403', [], 403))->name('errors.403');
Route::get('/error/404', fn () => response()->view('errors.404', [], 404))->name('errors.404');

Route::middleware(['auth', 'active', 'project.access', 'throttle.operation'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('dashboard');

    Route::post('assistant/ask', [ChatbotController::class, 'ask'])->name('assistant.ask');
    Route::get('assistant/suggestions', [ChatbotController::class, 'suggestions'])->name('assistant.suggestions');
    Route::post('assistant/clear', [ChatbotController::class, 'clear'])->name('assistant.clear');

    Route::get('imports/history', [ImportController::class, 'history'])->name('imports.history');
    Route::post('imports/batches/{batch}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
    Route::get('imports/batches/{batch}/errors', [ImportController::class, 'errorReport'])->name('imports.errors');
    Route::get('imports/{module}/template', [ImportController::class, 'template'])->name('imports.template');
    Route::post('imports/{module}/preview', [ImportController::class, 'preview'])->name('imports.preview');
    Route::get('imports/{module}', [ImportController::class, 'show'])->name('imports.show');

    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::get('approvals/settings', [ApprovalController::class, 'settings'])->name('approvals.settings');
    Route::put('approvals/settings', [ApprovalController::class, 'updateSettings'])->name('approvals.settings.update');
    Route::get('audit-log', AuditLogController::class)->name('audit-log.index');

    Route::post('menu-tutorial/complete', [MenuTutorialController::class, 'complete'])->name('menu-tutorial.complete');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('projects', [ProjectController::class, 'index'])->middleware('permission:projects.view')->name('projects.index');
    Route::get('projects/create', [ProjectController::class, 'create'])->middleware('permission:projects.create')->name('projects.create');
    Route::post('projects', [ProjectController::class, 'store'])->middleware('permission:projects.create')->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->middleware('permission:projects.view')->name('projects.show');
    Route::get('projects/{project}/edit', [ProjectController::class, 'edit'])->middleware('permission:projects.edit')->name('projects.edit');
    Route::put('projects/{project}', [ProjectController::class, 'update'])->middleware('permission:projects.edit')->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->middleware('permission:projects.delete')->name('projects.destroy');

    Route::get('reports', [ReportController::class, 'index'])->middleware('permission:reports.view')->name('reports.index');
    Route::get('reports/{report}', [ReportController::class, 'show'])->middleware('permission:reports.view')->name('reports.show');
    Route::get('reports/{report}/export/excel', [ReportController::class, 'exportExcel'])->middleware('permission:reports.export')->name('reports.export.excel');
    Route::get('reports/{report}/export/pdf', [ReportController::class, 'exportPdf'])->middleware('permission:reports.print')->name('reports.export.pdf');
    Route::get('vendors/report', [VendorController::class, 'report'])->middleware('permission:vendors.view')->name('vendors.report');
    Route::get('vendors/reconciliation', [VendorLedgerController::class, 'reconciliationIndex'])->middleware('permission:vendors.ledger')->name('vendors.reconciliation.index');
    Route::get('vendors/{vendor}/reconciliation', [VendorLedgerController::class, 'reconciliationShow'])->middleware('permission:vendors.ledger')->name('vendors.reconciliation.show');
    Route::get('vendors/{vendor}/ledger', [VendorLedgerController::class, 'show'])->middleware('permission:vendors.ledger')->name('vendors.ledger');

    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:expenses.view')->name('expenses.index');
    Route::get('expenses/create', [ExpenseController::class, 'create'])->middleware('permission:expenses.create')->name('expenses.create');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware('permission:expenses.create')->name('expenses.store');
    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->middleware('permission:expenses.view')->name('expenses.show');
    Route::get('expenses/{expense}/pdf/download', [ExpenseController::class, 'downloadPdf'])->middleware('permission:expenses.print')->name('expenses.pdf.download');
    Route::get('expenses/{expense}/pdf', [ExpenseController::class, 'pdf'])->middleware('permission:expenses.print')->name('expenses.pdf');
    Route::get('expenses/{expense}/edit', [ExpenseController::class, 'edit'])->middleware('permission:expenses.edit')->name('expenses.edit');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->middleware('permission:expenses.edit')->name('expenses.update');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('permission:expenses.edit')->name('expenses.destroy');
    Route::post('expenses/{expense}/submit', [ExpenseController::class, 'submit'])->middleware('permission:expenses.submit')->name('expenses.submit');
    Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('permission:expenses.approve')->name('expenses.approve');
    Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware('permission:expenses.reject')->name('expenses.reject');

    Route::middleware('permission:expenses.manage_categories')->group(function () {
        Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        Route::put('expense-categories/{expense_category}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
    });
    Route::patch('vendors/{vendor}/status', [VendorController::class, 'updateStatus'])->middleware('permission:vendors.edit')->name('vendors.status');
    Route::get('vendors/{vendor}/payments/create', [VendorPaymentController::class, 'create'])->middleware('permission:payments.create')->name('vendors.payments.create');
    Route::post('vendors/{vendor}/payments', [VendorPaymentController::class, 'store'])->middleware('permission:payments.create')->name('vendors.payments.store');
    Route::scopeBindings()->group(function () {
        Route::delete('vendors/{vendor}/payments/{payment}', [VendorPaymentController::class, 'destroy'])->middleware('permission:payments.edit')->name('vendors.payments.destroy');
        Route::post('vendors/{vendor}/recoveries/{recovery}/refunds', [VendorRecoveryController::class, 'receive'])->middleware('permission:payments.create')->name('vendors.recoveries.refunds.store');
    });
    Route::post('vendors/{vendor}/recoveries', [VendorRecoveryController::class, 'store'])->middleware('permission:payments.create')->name('vendors.recoveries.store');
    Route::get('vendors', [VendorController::class, 'index'])->middleware('permission:vendors.view')->name('vendors.index');
    Route::get('vendors/create', [VendorController::class, 'create'])->middleware('permission:vendors.create')->name('vendors.create');
    Route::post('vendors', [VendorController::class, 'store'])->middleware('permission:vendors.create')->name('vendors.store');
    Route::get('vendors/{vendor}/edit', [VendorController::class, 'edit'])->middleware('permission:vendors.edit')->name('vendors.edit');
    Route::get('vendors/{vendor}', [VendorController::class, 'show'])->middleware('permission:vendors.view')->name('vendors.show');
    Route::put('vendors/{vendor}', [VendorController::class, 'update'])->middleware('permission:vendors.edit')->name('vendors.update');
    Route::delete('vendors/{vendor}', [VendorController::class, 'destroy'])->middleware('permission:vendors.edit')->name('vendors.destroy');

    Route::get('clients', [ClientController::class, 'index'])->middleware('permission:clients.view')->name('clients.index');
    Route::get('clients/create', [ClientController::class, 'create'])->middleware('permission:clients.create')->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])->middleware('permission:clients.create')->name('clients.store');
    Route::get('clients/{client}/ledger', [ClientController::class, 'ledger'])->middleware('permission:clients.ledger')->name('clients.ledger');
    Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->middleware('permission:clients.edit')->name('clients.edit');
    Route::get('clients/{client}', [ClientController::class, 'show'])->middleware('permission:clients.view')->name('clients.show');
    Route::put('clients/{client}', [ClientController::class, 'update'])->middleware('permission:clients.edit')->name('clients.update');

    Route::get('client-invoices', [ClientInvoiceController::class, 'index'])->middleware('permission:client_invoices.view')->name('client-invoices.index');

    Route::scopeBindings()->group(function () {
        Route::get('projects/{project}/boqs', [BoqController::class, 'index'])->middleware('permission:boq.view')->name('projects.boqs.index');
        Route::get('projects/{project}/boqs/create', [BoqController::class, 'create'])->middleware('permission:boq.create')->name('projects.boqs.create');
        Route::post('projects/{project}/boqs', [BoqController::class, 'store'])->middleware('permission:boq.create')->name('projects.boqs.store');
        Route::get('projects/{project}/boqs/{boq}', [BoqController::class, 'show'])->middleware('permission:boq.view')->name('projects.boqs.show');
        Route::put('projects/{project}/boqs/{boq}', [BoqController::class, 'update'])->middleware('permission:boq.edit')->name('projects.boqs.update');
        Route::patch('projects/{project}/boqs/{boq}', [BoqController::class, 'update'])->middleware('permission:boq.edit');
        Route::delete('projects/{project}/boqs/{boq}', [BoqController::class, 'destroy'])->middleware('permission:boq.delete')->name('projects.boqs.destroy');

        Route::post('projects/{project}/boqs/{boq}/revisions', [BoqRevisionController::class, 'store'])
            ->middleware('permission:boq.edit')
            ->name('projects.boqs.revisions.store');
        Route::post('projects/{project}/boqs/{boq}/revisions/{revision}/finalize', [BoqRevisionController::class, 'finalize'])
            ->middleware('permission:boq.edit')
            ->name('projects.boqs.revisions.finalize');

        Route::get('projects/{project}/boqs/{boq}/template', [BoqImportController::class, 'template'])
            ->middleware('permission:boq.import')
            ->name('projects.boqs.template');
        Route::get('projects/{project}/boqs/{boq}/import', [BoqImportController::class, 'create'])
            ->middleware('permission:boq.import')
            ->name('projects.boqs.import');
        Route::post('projects/{project}/boqs/{boq}/import', [BoqImportController::class, 'preview'])
            ->middleware('permission:boq.import')
            ->name('projects.boqs.import.preview');
        Route::post('projects/{project}/boqs/{boq}/import/confirm', [BoqImportController::class, 'confirm'])
            ->middleware('permission:boq.import')
            ->name('projects.boqs.import.confirm');
        Route::delete('projects/{project}/boqs/{boq}/import', [BoqImportController::class, 'cancel'])
            ->middleware('permission:boq.import')
            ->name('projects.boqs.import.cancel');

        Route::get('projects/{project}/boqs/{boq}/items/{item}/budget', [BoqItemBudgetController::class, 'edit'])
            ->middleware('permission:boq.edit')
            ->name('projects.boqs.items.budget.edit');
        Route::put('projects/{project}/boqs/{boq}/items/{item}/budget', [BoqItemBudgetController::class, 'update'])
            ->middleware('permission:boq.edit')
            ->name('projects.boqs.items.budget.update');
        Route::delete('projects/{project}/boqs/{boq}/items/{item}/budgets/{budget}', [BoqItemBudgetController::class, 'destroy'])
            ->middleware('permission:boq.edit')
            ->name('projects.boqs.items.budgets.destroy');

        Route::get('projects/{project}/boqs/{boq}/items/{item}/procurement', [BoqItemProcurementController::class, 'show'])
            ->middleware('permission:boq.view')
            ->name('projects.boqs.items.procurement');

        Route::get('projects/{project}/boqs/{boq}/items/{item}/purchase-requests/create', [PurchaseRequestController::class, 'create'])
            ->middleware('permission:purchase_requests.create')
            ->name('projects.boqs.items.purchase-requests.create');
        Route::post('projects/{project}/boqs/{boq}/items/{item}/purchase-requests', [PurchaseRequestController::class, 'store'])
            ->middleware('permission:purchase_requests.create')
            ->name('projects.boqs.items.purchase-requests.store');

        Route::get('projects/{project}/purchase-requests', [PurchaseRequestController::class, 'index'])
            ->middleware('permission:purchase_requests.view')
            ->name('projects.purchase-requests.index');
        Route::get('projects/{project}/purchase-requests/create', [PurchaseRequestController::class, 'createMany'])
            ->middleware('permission:purchase_requests.create')
            ->name('projects.purchase-requests.create');
        Route::post('projects/{project}/purchase-requests', [PurchaseRequestController::class, 'storeMany'])
            ->middleware('permission:purchase_requests.create')
            ->name('projects.purchase-requests.store');
        Route::get('projects/{project}/purchase-requests/{purchase_request}/pdf/download', [PurchaseRequestController::class, 'downloadPdf'])
            ->middleware('permission:purchase_requests.print')
            ->name('projects.purchase-requests.pdf.download');
        Route::get('projects/{project}/purchase-requests/{purchase_request}/pdf', [PurchaseRequestController::class, 'pdf'])
            ->middleware('permission:purchase_requests.print')
            ->name('projects.purchase-requests.pdf');
        Route::get('projects/{project}/purchase-requests/{purchase_request}', [PurchaseRequestController::class, 'show'])
            ->middleware('permission:purchase_requests.view')
            ->name('projects.purchase-requests.show');
        Route::get('projects/{project}/purchase-requests/{purchase_request}/edit', [PurchaseRequestController::class, 'edit'])
            ->middleware('permission:purchase_requests.edit')
            ->name('projects.purchase-requests.edit');
        Route::put('projects/{project}/purchase-requests/{purchase_request}', [PurchaseRequestController::class, 'update'])
            ->middleware('permission:purchase_requests.edit')
            ->name('projects.purchase-requests.update');
        Route::put('projects/{project}/purchase-requests/{purchase_request}/lines', [PurchaseRequestController::class, 'updateMany'])
            ->middleware('permission:purchase_requests.edit')
            ->name('projects.purchase-requests.lines.update');
        Route::delete('projects/{project}/purchase-requests/{purchase_request}', [PurchaseRequestController::class, 'destroy'])
            ->middleware('permission:purchase_requests.delete')
            ->name('projects.purchase-requests.destroy');
        Route::post('projects/{project}/purchase-requests/{purchase_request}/submit', [PurchaseRequestController::class, 'submit'])
            ->middleware('permission:purchase_requests.submit')
            ->name('projects.purchase-requests.submit');
        Route::post('projects/{project}/purchase-requests/{purchase_request}/reopen', [PurchaseRequestController::class, 'reopen'])
            ->middleware('permission:purchase_requests.edit')
            ->name('projects.purchase-requests.reopen');
        Route::post('projects/{project}/purchase-requests/{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])
            ->middleware('permission:purchase_requests.approve')
            ->name('projects.purchase-requests.approve');
        Route::post('projects/{project}/purchase-requests/{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])
            ->middleware('permission:purchase_requests.reject')
            ->name('projects.purchase-requests.reject');

        Route::get('projects/{project}/purchase-orders', [PurchaseOrderController::class, 'index'])
            ->middleware('permission:purchase_orders.view')
            ->name('projects.purchase-orders.index');
        Route::get('projects/{project}/purchase-requests/{purchase_request}/purchase-orders/create', [PurchaseOrderController::class, 'create'])
            ->middleware('permission:purchase_orders.create')
            ->name('projects.purchase-requests.purchase-orders.create');
        Route::post('projects/{project}/purchase-requests/{purchase_request}/purchase-orders', [PurchaseOrderController::class, 'store'])
            ->middleware('permission:purchase_orders.create')
            ->name('projects.purchase-requests.purchase-orders.store');
        Route::get('projects/{project}/purchase-orders/{purchase_order}/pdf/download', [PurchaseOrderController::class, 'downloadPdf'])
            ->middleware('permission:purchase_orders.view')
            ->name('projects.purchase-orders.pdf.download');
        Route::get('projects/{project}/purchase-orders/{purchase_order}/pdf', [PurchaseOrderController::class, 'pdf'])
            ->middleware('permission:purchase_orders.view')
            ->name('projects.purchase-orders.pdf');
        Route::get('projects/{project}/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])
            ->middleware('permission:purchase_orders.view')
            ->name('projects.purchase-orders.show');
        Route::get('projects/{project}/purchase-orders/{purchase_order}/edit', [PurchaseOrderController::class, 'edit'])
            ->middleware('permission:purchase_orders.edit')
            ->name('projects.purchase-orders.edit');
        Route::put('projects/{project}/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'update'])
            ->middleware('permission:purchase_orders.edit')
            ->name('projects.purchase-orders.update');
        Route::delete('projects/{project}/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'destroy'])
            ->middleware('permission:purchase_orders.delete')
            ->name('projects.purchase-orders.destroy');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/submit', [PurchaseOrderController::class, 'submit'])
            ->middleware('permission:purchase_orders.submit')
            ->name('projects.purchase-orders.submit');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/reopen', [PurchaseOrderController::class, 'reopen'])
            ->middleware('permission:purchase_orders.edit')
            ->name('projects.purchase-orders.reopen');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])
            ->middleware('permission:purchase_orders.approve')
            ->name('projects.purchase-orders.approve');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/reject', [PurchaseOrderController::class, 'reject'])
            ->middleware('permission:purchase_orders.reject')
            ->name('projects.purchase-orders.reject');
        Route::get('projects/{project}/purchase-orders/{purchase_order}/rate-history', [PurchaseOrderController::class, 'rateHistory'])
            ->middleware('permission:purchase_orders.view_rate_history')
            ->name('projects.purchase-orders.rate-history');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/rate-amendments', [PurchaseOrderController::class, 'storeRateAmendment'])
            ->middleware('permission:purchase_orders.request_rate_amendment')
            ->name('projects.purchase-orders.rate-amendments.store');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/rate-amendments/{rate_amendment}/approve', [PurchaseOrderController::class, 'approveRateAmendment'])
            ->middleware('permission:purchase_orders.approve_rate_amendment')
            ->name('projects.purchase-orders.rate-amendments.approve');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/rate-amendments/{rate_amendment}/reject', [PurchaseOrderController::class, 'rejectRateAmendment'])
            ->middleware('permission:purchase_orders.reject_rate_amendment')
            ->name('projects.purchase-orders.rate-amendments.reject');

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

        Route::get('projects/{project}/store', [MaterialReceiptController::class, 'index'])->middleware('permission:store.view')->name('projects.store.index');
        Route::get('projects/{project}/store/create', [MaterialReceiptController::class, 'create'])->middleware('permission:store.create')->name('projects.store.create');
        Route::post('projects/{project}/store', [MaterialReceiptController::class, 'store'])->middleware('permission:store.create')->name('projects.store.store');
        Route::get('projects/{project}/store/{receipt}/pdf/download', [MaterialReceiptController::class, 'downloadPdf'])->middleware('permission:store.print')->name('projects.store.pdf.download');
        Route::get('projects/{project}/store/{receipt}/pdf', [MaterialReceiptController::class, 'pdf'])->middleware('permission:store.print')->name('projects.store.pdf');
        Route::get('projects/{project}/store/{receipt}', [MaterialReceiptController::class, 'show'])->middleware('permission:store.view')->name('projects.store.show');
        Route::get('projects/{project}/store/{receipt}/edit', [MaterialReceiptController::class, 'edit'])->middleware('permission:store.edit')->name('projects.store.edit');
        Route::put('projects/{project}/store/{receipt}', [MaterialReceiptController::class, 'update'])->middleware('permission:store.edit')->name('projects.store.update');
        Route::delete('projects/{project}/store/{receipt}', [MaterialReceiptController::class, 'destroy'])->middleware('permission:store.edit')->name('projects.store.destroy');
        Route::get('projects/{project}/store/{receipt}/images/{image}', [MaterialReceiptController::class, 'image'])->middleware('permission:store.view')->name('projects.store.images.show');

        Route::get('projects/{project}/inventory', [InventoryController::class, 'dashboard'])->middleware('permission:inventory.view')->name('projects.inventory.dashboard');
        Route::get('projects/{project}/inventory/stock', [InventoryController::class, 'stock'])->middleware('permission:inventory.view')->name('projects.inventory.stock');
        Route::get('projects/{project}/inventory/ledger', [InventoryController::class, 'ledger'])->middleware('permission:inventory.view')->name('projects.inventory.ledger');

        Route::get('projects/{project}/inventory/issues', [MaterialIssueController::class, 'index'])->middleware('permission:inventory.view')->name('projects.inventory.issues.index');
        Route::get('projects/{project}/inventory/issues/create', [MaterialIssueController::class, 'create'])->middleware('permission:inventory.create')->name('projects.inventory.issues.create');
        Route::post('projects/{project}/inventory/issues', [MaterialIssueController::class, 'store'])->middleware('permission:inventory.create')->name('projects.inventory.issues.store');
        Route::get('projects/{project}/inventory/issues/{material_issue}', [MaterialIssueController::class, 'show'])->middleware('permission:inventory.view')->name('projects.inventory.issues.show');
        Route::put('projects/{project}/inventory/issues/{material_issue}', [MaterialIssueController::class, 'update'])->middleware('permission:inventory.edit')->name('projects.inventory.issues.update');
        Route::post('projects/{project}/inventory/issues/{material_issue}/post', [MaterialIssueController::class, 'post'])->middleware('permission:inventory.post')->name('projects.inventory.issues.post');
        Route::post('projects/{project}/inventory/issues/{material_issue}/cancel', [MaterialIssueController::class, 'cancel'])->middleware('permission:inventory.post')->name('projects.inventory.issues.cancel');

        Route::get('projects/{project}/inventory/adjustments', [StockAdjustmentController::class, 'index'])->middleware('permission:inventory.view')->name('projects.inventory.adjustments.index');
        Route::get('projects/{project}/inventory/adjustments/create', [StockAdjustmentController::class, 'create'])->middleware('permission:inventory.adjust')->name('projects.inventory.adjustments.create');
        Route::post('projects/{project}/inventory/adjustments', [StockAdjustmentController::class, 'store'])->middleware('permission:inventory.adjust')->name('projects.inventory.adjustments.store');
        Route::get('projects/{project}/inventory/adjustments/{stock_adjustment}/attachment', [StockAdjustmentController::class, 'attachment'])->middleware('permission:inventory.view')->name('projects.inventory.adjustments.attachment');
        Route::get('projects/{project}/inventory/adjustments/{stock_adjustment}', [StockAdjustmentController::class, 'show'])->middleware('permission:inventory.view')->name('projects.inventory.adjustments.show');
        Route::post('projects/{project}/inventory/adjustments/{stock_adjustment}/post', [StockAdjustmentController::class, 'post'])->middleware('permission:inventory.adjust')->name('projects.inventory.adjustments.post');

        Route::get('projects/{project}/grns', [GoodsReceiptController::class, 'index'])->middleware('permission:grn.view')->name('projects.grns.index');
        Route::get('projects/{project}/purchase-orders/{purchase_order}/grns/create', [GoodsReceiptController::class, 'create'])->middleware('permission:grn.create')->name('projects.purchase-orders.grns.create');
        Route::post('projects/{project}/purchase-orders/{purchase_order}/grns', [GoodsReceiptController::class, 'store'])->middleware('permission:grn.create')->name('projects.purchase-orders.grns.store');
        Route::get('projects/{project}/grns/{receipt}/pdf/download', [GoodsReceiptController::class, 'downloadPdf'])->middleware('permission:grn.print')->name('projects.grns.pdf.download');
        Route::get('projects/{project}/grns/{receipt}/pdf', [GoodsReceiptController::class, 'pdf'])->middleware('permission:grn.print')->name('projects.grns.pdf');
        Route::get('projects/{project}/grns/{receipt}/images/{image}', [GoodsReceiptController::class, 'image'])->middleware('permission:grn.view')->name('projects.grns.images.show');
        Route::get('projects/{project}/grns/{receipt}', [GoodsReceiptController::class, 'show'])->middleware('permission:grn.view')->name('projects.grns.show');
        Route::get('projects/{project}/grns/{receipt}/edit', [GoodsReceiptController::class, 'edit'])->middleware('permission:grn.edit')->name('projects.grns.edit');
        Route::put('projects/{project}/grns/{receipt}', [GoodsReceiptController::class, 'update'])->middleware('permission:grn.edit')->name('projects.grns.update');
        Route::post('projects/{project}/grns/{receipt}/submit', [GoodsReceiptController::class, 'submit'])->middleware('permission:grn.submit')->name('projects.grns.submit');
        Route::post('projects/{project}/grns/{receipt}/post', [GoodsReceiptController::class, 'post'])->middleware('permission:grn.post')->name('projects.grns.post');
        Route::post('projects/{project}/grns/{receipt}/reject', [GoodsReceiptController::class, 'reject'])->middleware('permission:grn.reject')->name('projects.grns.reject');
        Route::post('projects/{project}/grns/{receipt}/cancel', [GoodsReceiptController::class, 'cancel'])->middleware('permission:grn.post')->name('projects.grns.cancel');

        Route::get('projects/{project}/inventory/transfers', [InternalDeliveryController::class, 'index'])->middleware('permission:idn.view')->name('projects.inventory.transfers.index');
        Route::get('projects/{project}/inventory/transfers/create', [InternalDeliveryController::class, 'create'])->middleware('permission:idn.create')->name('projects.inventory.transfers.create');

        Route::get('projects/{project}/purchase-invoices', [PurchaseInvoiceController::class, 'index'])->middleware('permission:invoices.view')->name('projects.purchase-invoices.index');
        Route::get('projects/{project}/purchase-invoices/create', [PurchaseInvoiceController::class, 'create'])->middleware('permission:invoices.create')->name('projects.purchase-invoices.create');
        Route::post('projects/{project}/purchase-invoices', [PurchaseInvoiceController::class, 'store'])->middleware('permission:invoices.create')->name('projects.purchase-invoices.store');
        Route::get('projects/{project}/purchase-invoices/{invoice}/pdf/download', [PurchaseInvoiceController::class, 'downloadPdf'])->middleware('permission:invoices.print')->name('projects.purchase-invoices.pdf.download');
        Route::get('projects/{project}/purchase-invoices/{invoice}/pdf', [PurchaseInvoiceController::class, 'pdf'])->middleware('permission:invoices.print')->name('projects.purchase-invoices.pdf');
        Route::get('projects/{project}/purchase-invoices/{invoice}', [PurchaseInvoiceController::class, 'show'])->middleware('permission:invoices.view')->name('projects.purchase-invoices.show');
        Route::put('projects/{project}/purchase-invoices/{invoice}', [PurchaseInvoiceController::class, 'update'])->middleware('permission:invoices.edit')->name('projects.purchase-invoices.update');
        Route::delete('projects/{project}/purchase-invoices/{invoice}', [PurchaseInvoiceController::class, 'destroy'])->middleware('permission:invoices.delete')->name('projects.purchase-invoices.destroy');
        Route::get('projects/{project}/purchase-invoices/{invoice}/attachment', [PurchaseInvoiceController::class, 'attachment'])->middleware('permission:invoices.view')->name('projects.purchase-invoices.attachment');
        Route::post('projects/{project}/purchase-invoices/{invoice}/advance', [PurchaseInvoiceController::class, 'applyAdvance'])->middleware('permission:payments.create')->name('projects.purchase-invoices.advance');
        Route::post('projects/{project}/purchase-invoices/{invoice}/submit', [PurchaseInvoiceController::class, 'submit'])->middleware('permission:invoices.submit')->name('projects.purchase-invoices.submit');
        Route::post('projects/{project}/purchase-invoices/{invoice}/approve', [PurchaseInvoiceController::class, 'approve'])->middleware('permission:invoices.approve')->name('projects.purchase-invoices.approve');
        Route::post('projects/{project}/purchase-invoices/{invoice}/reject', [PurchaseInvoiceController::class, 'reject'])->middleware('permission:invoices.reject')->name('projects.purchase-invoices.reject');

        Route::get('projects/{project}/client-invoices', [ClientInvoiceController::class, 'index'])->middleware('permission:client_invoices.view')->name('projects.client-invoices.index');
        Route::get('projects/{project}/client-invoices/create', [ClientInvoiceController::class, 'create'])->middleware('permission:client_invoices.create')->name('projects.client-invoices.create');
        Route::post('projects/{project}/client-invoices', [ClientInvoiceController::class, 'store'])->middleware('permission:client_invoices.create')->name('projects.client-invoices.store');
        Route::get('projects/{project}/client-invoices/{client_invoice}/pdf/download', [ClientInvoiceController::class, 'downloadPdf'])->middleware('permission:client_invoices.print')->name('projects.client-invoices.pdf.download');
        Route::get('projects/{project}/client-invoices/{client_invoice}/pdf', [ClientInvoiceController::class, 'pdf'])->middleware('permission:client_invoices.print')->name('projects.client-invoices.pdf');
        Route::get('projects/{project}/client-invoices/{client_invoice}/edit', [ClientInvoiceController::class, 'edit'])->middleware('permission:client_invoices.edit')->name('projects.client-invoices.edit');
        Route::get('projects/{project}/client-invoices/{client_invoice}', [ClientInvoiceController::class, 'show'])->middleware('permission:client_invoices.view')->name('projects.client-invoices.show');
        Route::put('projects/{project}/client-invoices/{client_invoice}', [ClientInvoiceController::class, 'update'])->middleware('permission:client_invoices.edit')->name('projects.client-invoices.update');
        Route::delete('projects/{project}/client-invoices/{client_invoice}', [ClientInvoiceController::class, 'destroy'])->middleware('permission:client_invoices.delete')->name('projects.client-invoices.destroy');
        Route::post('projects/{project}/client-invoices/{client_invoice}/submit', [ClientInvoiceController::class, 'submit'])->middleware('permission:client_invoices.submit')->name('projects.client-invoices.submit');
        Route::post('projects/{project}/client-invoices/{client_invoice}/approve', [ClientInvoiceController::class, 'approve'])->middleware('permission:client_invoices.approve')->name('projects.client-invoices.approve');
        Route::post('projects/{project}/client-invoices/{client_invoice}/reject', [ClientInvoiceController::class, 'reject'])->middleware('permission:client_invoices.reject')->name('projects.client-invoices.reject');
        Route::post('projects/{project}/client-invoices/{client_invoice}/payments', [ClientInvoiceController::class, 'storePayment'])->middleware('permission:client_invoices.record_payment')->name('projects.client-invoices.payments.store');

        Route::get('projects/{project}/debit-notes', [DebitNoteController::class, 'index'])->middleware('permission:debit_notes.view')->name('projects.debit-notes.index');
        Route::get('projects/{project}/debit-notes/create', [DebitNoteController::class, 'create'])->middleware('permission:debit_notes.create')->name('projects.debit-notes.create');
        Route::post('projects/{project}/debit-notes', [DebitNoteController::class, 'store'])->middleware('permission:debit_notes.create')->name('projects.debit-notes.store');
        Route::get('projects/{project}/debit-notes/{note}', [DebitNoteController::class, 'show'])->middleware('permission:debit_notes.view')->name('projects.debit-notes.show');
        Route::get('projects/{project}/debit-notes/{note}/edit', [DebitNoteController::class, 'edit'])->middleware('permission:debit_notes.edit')->name('projects.debit-notes.edit');
        Route::put('projects/{project}/debit-notes/{note}', [DebitNoteController::class, 'update'])->middleware('permission:debit_notes.edit')->name('projects.debit-notes.update');
        Route::delete('projects/{project}/debit-notes/{note}', [DebitNoteController::class, 'destroy'])->middleware('permission:debit_notes.edit')->name('projects.debit-notes.destroy');
        Route::post('projects/{project}/debit-notes/{note}/submit', [DebitNoteController::class, 'submit'])->middleware('permission:debit_notes.submit')->name('projects.debit-notes.submit');
        Route::post('projects/{project}/debit-notes/{note}/approve', [DebitNoteController::class, 'approve'])->middleware('permission:debit_notes.approve')->name('projects.debit-notes.approve');
        Route::post('projects/{project}/debit-notes/{note}/reject', [DebitNoteController::class, 'reject'])->middleware('permission:debit_notes.reject')->name('projects.debit-notes.reject');
        Route::get('projects/{project}/debit-notes/{note}/pdf/download', [DebitNoteController::class, 'downloadPdf'])->middleware('permission:debit_notes.print')->name('projects.debit-notes.pdf.download');
        Route::get('projects/{project}/debit-notes/{note}/pdf', [DebitNoteController::class, 'pdf'])->middleware('permission:debit_notes.print')->name('projects.debit-notes.pdf');

        Route::get('projects/{project}/credit-notes', [CreditNoteController::class, 'index'])->middleware('permission:credit_notes.view')->name('projects.credit-notes.index');
        Route::get('projects/{project}/credit-notes/create', [CreditNoteController::class, 'create'])->middleware('permission:credit_notes.create')->name('projects.credit-notes.create');
        Route::post('projects/{project}/credit-notes', [CreditNoteController::class, 'store'])->middleware('permission:credit_notes.create')->name('projects.credit-notes.store');
        Route::get('projects/{project}/credit-notes/{note}', [CreditNoteController::class, 'show'])->middleware('permission:credit_notes.view')->name('projects.credit-notes.show');
        Route::get('projects/{project}/credit-notes/{note}/edit', [CreditNoteController::class, 'edit'])->middleware('permission:credit_notes.edit')->name('projects.credit-notes.edit');
        Route::put('projects/{project}/credit-notes/{note}', [CreditNoteController::class, 'update'])->middleware('permission:credit_notes.edit')->name('projects.credit-notes.update');
        Route::delete('projects/{project}/credit-notes/{note}', [CreditNoteController::class, 'destroy'])->middleware('permission:credit_notes.edit')->name('projects.credit-notes.destroy');
        Route::post('projects/{project}/credit-notes/{note}/submit', [CreditNoteController::class, 'submit'])->middleware('permission:credit_notes.submit')->name('projects.credit-notes.submit');
        Route::post('projects/{project}/credit-notes/{note}/approve', [CreditNoteController::class, 'approve'])->middleware('permission:credit_notes.approve')->name('projects.credit-notes.approve');
        Route::post('projects/{project}/credit-notes/{note}/reject', [CreditNoteController::class, 'reject'])->middleware('permission:credit_notes.reject')->name('projects.credit-notes.reject');
        Route::get('projects/{project}/credit-notes/{note}/pdf/download', [CreditNoteController::class, 'downloadPdf'])->middleware('permission:credit_notes.print')->name('projects.credit-notes.pdf.download');
        Route::get('projects/{project}/credit-notes/{note}/pdf', [CreditNoteController::class, 'pdf'])->middleware('permission:credit_notes.print')->name('projects.credit-notes.pdf');

        Route::get('projects/{project}/expenses', [ExpenseController::class, 'index'])->middleware('permission:expenses.view')->name('projects.expenses.index');
        Route::get('projects/{project}/expenses/create', [ExpenseController::class, 'create'])->middleware('permission:expenses.create')->name('projects.expenses.create');
        Route::post('projects/{project}/expenses', [ExpenseController::class, 'store'])->middleware('permission:expenses.create')->name('projects.expenses.store');
        Route::get('projects/{project}/expenses/{expense}', [ExpenseController::class, 'show'])->middleware('permission:expenses.view')->name('projects.expenses.show');
        Route::get('projects/{project}/expenses/{expense}/pdf/download', [ExpenseController::class, 'downloadPdf'])->middleware('permission:expenses.print')->name('projects.expenses.pdf.download');
        Route::get('projects/{project}/expenses/{expense}/pdf', [ExpenseController::class, 'pdf'])->middleware('permission:expenses.print')->name('projects.expenses.pdf');
        Route::get('projects/{project}/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->middleware('permission:expenses.edit')->name('projects.expenses.edit');
        Route::put('projects/{project}/expenses/{expense}', [ExpenseController::class, 'update'])->middleware('permission:expenses.edit')->name('projects.expenses.update');
        Route::delete('projects/{project}/expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('permission:expenses.edit')->name('projects.expenses.destroy');
        Route::post('projects/{project}/expenses/{expense}/submit', [ExpenseController::class, 'submit'])->middleware('permission:expenses.submit')->name('projects.expenses.submit');
        Route::post('projects/{project}/expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('permission:expenses.approve')->name('projects.expenses.approve');
        Route::post('projects/{project}/expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware('permission:expenses.reject')->name('projects.expenses.reject');
    });

    Route::get('inventory/locations', [InventoryLocationController::class, 'index'])->middleware('permission:inventory.view')->name('inventory.locations.index');
    Route::post('inventory/locations', [InventoryLocationController::class, 'store'])->middleware('permission:inventory.create')->name('inventory.locations.store');
    Route::put('inventory/locations/{location}', [InventoryLocationController::class, 'update'])->middleware('permission:inventory.edit')->name('inventory.locations.update');
    Route::get('inventory/locations/{location}/stock', [InventoryLocationController::class, 'stock'])->name('inventory.locations.stock');

    Route::get('inventory/transfers', [InternalDeliveryController::class, 'index'])->middleware('permission:idn.view')->name('inventory.transfers.index');
    Route::get('inventory/transfers/create', [InternalDeliveryController::class, 'create'])->middleware('permission:idn.create')->name('inventory.transfers.create');
    Route::post('inventory/transfers', [InternalDeliveryController::class, 'store'])->middleware('permission:idn.create')->name('inventory.transfers.store');
    Route::get('inventory/transfers/{transfer}/pdf/download', [InternalDeliveryController::class, 'downloadPdf'])->middleware('permission:idn.print')->name('inventory.transfers.pdf.download');
    Route::get('inventory/transfers/{transfer}/pdf', [InternalDeliveryController::class, 'pdf'])->middleware('permission:idn.print')->name('inventory.transfers.pdf');
    Route::get('inventory/transfers/{transfer}/attachments/{attachment}', [InternalDeliveryController::class, 'attachment'])->middleware('permission:idn.view')->scopeBindings()->name('inventory.transfers.attachments.show');
    Route::get('inventory/transfers/{transfer}', [InternalDeliveryController::class, 'show'])->middleware('permission:idn.view')->name('inventory.transfers.show');
    Route::get('inventory/transfers/{transfer}/edit', [InternalDeliveryController::class, 'edit'])->middleware('permission:idn.edit')->name('inventory.transfers.edit');
    Route::put('inventory/transfers/{transfer}', [InternalDeliveryController::class, 'update'])->middleware('permission:idn.edit')->name('inventory.transfers.update');
    Route::post('inventory/transfers/{transfer}/submit', [InternalDeliveryController::class, 'submit'])->middleware('permission:idn.submit')->name('inventory.transfers.submit');
    Route::post('inventory/transfers/{transfer}/approve', [InternalDeliveryController::class, 'approve'])->middleware('permission:idn.approve')->name('inventory.transfers.approve');
    Route::post('inventory/transfers/{transfer}/reject', [InternalDeliveryController::class, 'reject'])->middleware('permission:idn.reject')->name('inventory.transfers.reject');
    Route::post('inventory/transfers/{transfer}/dispatch', [InternalDeliveryController::class, 'dispatch'])->middleware('permission:idn.dispatch')->name('inventory.transfers.dispatch');
    Route::post('inventory/transfers/{transfer}/receive', [InternalDeliveryController::class, 'receive'])->middleware('permission:idn.receive')->name('inventory.transfers.receive');
    Route::post('inventory/transfers/{transfer}/cancel', [InternalDeliveryController::class, 'cancel'])->middleware('permission:idn.approve')->name('inventory.transfers.cancel');

    Route::get('projects/{project}/inventory/materials', [MaterialController::class, 'index'])->middleware('permission:inventory.view')->name('projects.inventory.materials.index');
    Route::post('projects/{project}/inventory/materials', [MaterialController::class, 'store'])->middleware('permission:inventory.create')->name('projects.inventory.materials.store');
    Route::put('projects/{project}/inventory/materials/{material}', [MaterialController::class, 'update'])->middleware('permission:inventory.edit')->name('projects.inventory.materials.update');
    Route::delete('projects/{project}/inventory/materials/{material}', [MaterialController::class, 'destroy'])->middleware('permission:inventory.edit')->name('projects.inventory.materials.destroy');

    Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->middleware('permission:users.create')->name('users.create');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create')->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->middleware('permission:users.edit')->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit')->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.edit')->name('users.destroy');
    Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.edit')->name('users.status');
    Route::get('users/{user}/permissions', [UserController::class, 'permissions'])->middleware('permission:users.edit')->name('users.permissions.edit');
    Route::put('users/{user}/permissions', [UserController::class, 'updatePermissions'])->middleware('permission:users.edit')->name('users.permissions.update');

    Route::middleware('permission:roles.view')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });

    Route::middleware('permission:settings.view')->group(function () {
        Route::get('settings/company', [CompanySettingController::class, 'edit'])->name('settings.company.edit');
        Route::post('settings/company', [CompanySettingController::class, 'update'])->name('settings.company.update');
    });
});

require __DIR__.'/auth.php';
