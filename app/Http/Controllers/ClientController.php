<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Services\Access;
use App\Services\ClientLedgerService;
use App\Support\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'clients.view'), 403);

        $filters = Listing::filters($request);
        $query = Client::query()->orderBy('name')->orderBy('id');
        Listing::applySearch($query, $filters['search'], ['client_code', 'name', 'phone', 'email', 'gst_number', 'status']);

        return Inertia::render('Clients/Index', [
            'clients' => Listing::paginate($query, $request)->through(fn (Client $client) => [
                'id' => $client->id,
                'client_code' => $client->client_code,
                'name' => $client->name,
                'phone' => $client->phone,
                'email' => $client->email,
                'gst_number' => $client->gst_number,
                'status' => $client->status,
            ]),
            'filters' => $filters,
            'canCreate' => $access->allows($request->user(), 'clients.create'),
        ]);
    }

    public function create(Request $request, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'clients.create'), 403);

        return Inertia::render('Clients/Form', ['client' => null]);
    }

    public function store(ClientRequest $request, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'clients.create'), 403);

        $client = DB::transaction(function () use ($request) {
            return Client::query()->create([
                ...$request->validated(),
                'client_code' => $this->nextCode(),
                'status' => Client::ACTIVE,
            ]);
        });

        return redirect()->route('clients.show', $client)->with('success', 'Client created.');
    }

    public function show(Request $request, Client $client, Access $access, ClientLedgerService $ledger): Response
    {
        abort_unless($access->allows($request->user(), 'clients.view'), 403);

        $projectIds = $access->visibleProjectIds($request->user());
        $summary = $ledger->summary($client, $projectIds);
        $filters = Listing::filters($request);
        $invoices = $client->invoices()
            ->with('project:id,project_name,project_code')
            ->when($projectIds !== null, fn ($query) => $query->whereIn('project_id', $projectIds ?: [0]))
            ->latest('invoice_date')
            ->orderByDesc('id');
        Listing::applySearch($invoices, $filters['search'], [
            'invoice_number', 'invoice_date', 'grand_total', 'approval_status', 'payment_status', 'project.project_code', 'project.project_name',
        ]);

        return Inertia::render('Clients/Show', [
            'client' => $this->payload($client),
            'summary' => $summary,
            'filters' => $filters,
            'invoices' => Listing::paginate($invoices, $request)->through(fn ($invoice) => [
                'id' => $invoice->id,
                'project_id' => $invoice->project_id,
                'project_code' => $invoice->project?->project_code,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'grand_total' => (string) $invoice->grand_total,
                'approval_status' => $invoice->approval_status,
                'payment_status' => $invoice->payment_status,
            ]),
            'abilities' => [
                'edit' => $access->allows($request->user(), 'clients.edit'),
                'ledger' => $access->allows($request->user(), 'clients.ledger'),
            ],
        ]);
    }

    public function edit(Request $request, Client $client, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'clients.edit'), 403);

        return Inertia::render('Clients/Form', ['client' => $this->payload($client)]);
    }

    public function update(ClientRequest $request, Client $client, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'clients.edit'), 403);

        $client->update($request->validated());

        return redirect()->route('clients.show', $client)->with('success', 'Client updated.');
    }

    public function ledger(Request $request, Client $client, Access $access, ClientLedgerService $ledger): Response
    {
        abort_unless($access->allows($request->user(), 'clients.ledger'), 403);

        $scope = $access->projectScope($request->user(), $request->integer('project_id') ?: null);
        $statement = $ledger->statement($client, $scope['project_id'], $scope['project_ids']);

        return Inertia::render('Clients/Ledger', [
            'client' => $this->payload($client),
            'entries' => Listing::paginateRows($statement['entries'], $request, ['date', 'type', 'reference', 'project_code', 'particulars', 'debit', 'credit', 'balance']),
            'totals' => $statement['totals'],
            'filters' => [...Listing::filters($request), 'project_id' => $scope['project_id']],
        ]);
    }

    private function nextCode(): string
    {
        $latest = Client::query()->lockForUpdate()->orderByDesc('id')->value('client_code');
        $sequence = 1;
        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return sprintf('CLI-%03d', $sequence);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Client $client): array
    {
        return [
            'id' => $client->id,
            'client_code' => $client->client_code,
            'name' => $client->name,
            'contact_person' => $client->contact_person,
            'phone' => $client->phone,
            'email' => $client->email,
            'address' => $client->address,
            'city' => $client->city,
            'state' => $client->state,
            'pincode' => $client->pincode,
            'gst_number' => $client->gst_number,
            'pan_number' => $client->pan_number,
            'payment_terms' => $client->payment_terms,
            'notes' => $client->notes,
            'status' => $client->status,
        ];
    }
}
