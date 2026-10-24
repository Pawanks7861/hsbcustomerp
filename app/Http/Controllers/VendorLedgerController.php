<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Vendor;
use App\Services\Access;
use App\Services\VendorLedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorLedgerController extends Controller
{
    public function reconciliationIndex(Request $request, VendorLedgerService $ledger, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'vendors.ledger'), 403);

        $vendorId = $request->integer('vendor_id') ?: null;
        $scope = $access->projectScope($request->user(), $request->integer('project_id') ?: null);
        $projectId = $scope['project_id'];
        $projectIds = $scope['project_ids'];
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        $vendors = Vendor::query()
            ->when($vendorId, fn ($query) => $query->whereKey($vendorId))
            ->orderBy('name')
            ->get();

        return Inertia::render('Vendors/Reconciliation', [
            'vendor' => null,
            'filters' => [
                'vendor_id' => $vendorId,
                'project_id' => $projectId,
                'from' => $from,
                'to' => $to,
            ],
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name', 'vendor_code']),
            'projects' => Project::query()->visibleTo($request->user())->orderBy('project_name')->get(['id', 'project_name', 'project_code']),
            'reconciliation' => null,
            'rows' => $vendors->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'vendor_code' => $vendor->vendor_code,
                ...$ledger->reconcile($vendor, $projectId, $from, $to, $projectIds),
            ])->values(),
        ]);
    }

    public function reconciliationShow(Request $request, Vendor $vendor, VendorLedgerService $ledger, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'vendors.ledger'), 403);

        $scope = $access->projectScope($request->user(), $request->integer('project_id') ?: null);
        $projectId = $scope['project_id'];
        $projectIds = $scope['project_ids'];
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        return Inertia::render('Vendors/Reconciliation', [
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'vendor_code' => $vendor->vendor_code,
            ],
            'filters' => [
                'vendor_id' => $vendor->id,
                'project_id' => $projectId,
                'from' => $from,
                'to' => $to,
            ],
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name', 'vendor_code']),
            'projects' => Project::query()->visibleTo($request->user())->orderBy('project_name')->get(['id', 'project_name', 'project_code']),
            'reconciliation' => $ledger->reconcile($vendor, $projectId, $from, $to, $projectIds),
            'rows' => [],
        ]);
    }

    public function show(Request $request, Vendor $vendor, VendorLedgerService $ledger, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'vendors.ledger'), 403);

        $scope = $access->projectScope($request->user(), $request->integer('project_id') ?: null);
        $projectId = $scope['project_id'];
        $projectIds = $scope['project_ids'];
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        $statement = $ledger->statement($vendor, $projectId, $from, $to, $projectIds);

        return Inertia::render('Vendors/Ledger', [
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'vendor_code' => $vendor->vendor_code,
            ],
            'filters' => [
                'project_id' => $projectId,
                'from' => $from,
                'to' => $to,
            ],
            'projects' => Project::query()->visibleTo($request->user())->orderBy('project_name')->get(['id', 'project_name', 'project_code']),
            'opening' => $statement['opening'],
            'closing' => $statement['closing'],
            'total_debit' => $statement['total_debit'],
            'total_credit' => $statement['total_credit'],
            'entries' => $statement['entries'],
        ]);
    }
}
