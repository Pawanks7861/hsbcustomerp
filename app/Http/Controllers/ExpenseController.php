<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\Access;
use App\Services\DocumentNumber;
use App\Services\DocumentPdf;
use App\Support\DocumentStatus;
use App\Support\Listing;
use App\Support\Money;
use App\Support\StoredUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request, Access $access, ?Project $project = null): Response
    {
        abort_unless($access->allows($request->user(), 'expenses.view'), 403);

        $query = Expense::query()->with(['category', 'project', 'vendor']);
        if ($project) {
            $query->where('project_id', $project->id);
        } else {
            $visibleIds = $access->visibleProjectIds($request->user());
            if ($visibleIds !== null) {
                $query->where(function ($inner) use ($visibleIds): void {
                    $inner->whereNull('project_id')->orWhereIn('project_id', $visibleIds ?: [0]);
                });
            }
        }

        $categoryId = $request->integer('category_id') ?: null;
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;
        $projectId = $project?->id ?: ($request->integer('project_id') ?: null);
        $access->abortUnlessVisibleProjectId($request->user(), $projectId && ! $project ? $projectId : null);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
        if ($projectId && ! $project) {
            $query->where('project_id', $projectId);
        }
        if ($from) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('date', '<=', $to);
        }

        $filters = Listing::filters($request);
        $query->latest('date')->orderByDesc('id');
        Listing::applySearch($query, $filters['search'], [
            'number', 'date', 'payee', 'description', 'reference_number', 'total_amount', 'status', 'payment_status',
            'category.name', 'project.project_name', 'project.project_code', 'vendor.name',
        ]);

        return Inertia::render('Expenses/Index', [
            'project' => $project ? $this->projectCard($project) : null,
            'expenses' => Listing::paginate($query, $request)->through(fn (Expense $expense) => $this->listRow($expense)),
            'categories' => ExpenseCategory::query()->orderBy('name')->get(['id', 'name']),
            'projects' => $project ? [] : Project::query()->visibleTo($request->user())->orderBy('project_name')->get(['id', 'project_name']),
            'filters' => [
                ...$filters,
                'category_id' => $categoryId,
                'project_id' => $project ? null : $projectId,
                'from' => $from,
                'to' => $to,
            ],
            'canManageHeads' => $access->allows($request->user(), 'expenses.manage_categories'),
        ]);
    }

    public function create(Request $request, Access $access, ?Project $project = null): Response
    {
        abort_unless($access->allows($request->user(), 'expenses.create'), 403);

        return Inertia::render('Expenses/Form', [
            'project' => $project ? $this->projectCard($project) : null,
            'expense' => null,
            'categories' => $this->categories(),
            'projects' => Project::query()->visibleTo($request->user())->orderBy('project_name')->get(['id', 'project_name', 'project_code']),
            'vendors' => $this->vendorOptions(),
            'paymentModes' => VendorPayment::MODES,
        ]);
    }

    public function store(Request $request, Access $access, ?Project $project = null): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'expenses.create'), 403);
        $expense = $this->persist($request, $project);

        $route = $project
            ? route('projects.expenses.show', [$project, $expense])
            : route('expenses.show', $expense);

        return redirect($route)->with('success', 'Expense recorded.');
    }

    public function show(Request $request, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'expenses.view'), 403);
        $expense = $this->routeExpense($request);
        $project = $this->routeProject($request);
        $this->guardExpenseAccess($request->user(), $expense, $project, $access);

        $expense->load(['category', 'project', 'vendor', 'creator', 'approver']);

        return Inertia::render('Expenses/Show', [
            'project' => $project ? $this->projectCard($project) : ($expense->project ? $this->projectCard($expense->project) : null),
            'expense' => $this->detailPayload($expense),
            'abilities' => $this->abilities($request->user(), $expense, $access),
        ]);
    }

    public function edit(Request $request, Access $access, Expense $expense, ?Project $project = null): Response
    {
        abort_unless($access->allows($request->user(), 'expenses.edit'), 403);
        $this->guardExpenseAccess($request->user(), $expense, $project, $access);

        return Inertia::render('Expenses/Form', [
            'project' => $project ? $this->projectCard($project) : null,
            'expense' => $this->detailPayload($expense),
            'categories' => $this->categories($expense),
            'projects' => Project::query()->visibleTo($request->user())->orderBy('project_name')->get(['id', 'project_name', 'project_code']),
            'vendors' => $this->vendorOptions(),
            'paymentModes' => VendorPayment::MODES,
        ]);
    }

    public function update(Request $request, Access $access, Expense $expense, ?Project $project = null): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'expenses.edit'), 403);
        $this->guardExpenseAccess($request->user(), $expense, $project, $access);

        $this->persist($request, $project, $expense);

        $route = $project
            ? route('projects.expenses.show', [$project, $expense])
            : route('expenses.show', $expense);

        return redirect($route)->with('success', 'Expense updated.');
    }

    public function destroy(Request $request, Access $access, Expense $expense, ?Project $project = null): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'expenses.edit'), 403);
        $this->guardExpenseAccess($request->user(), $expense, $project, $access);

        if ($expense->status === DocumentStatus::APPROVED) {
            return back()->with('error', 'Approved expenses cannot be deleted.');
        }

        if ($expense->attachment_path) {
            Storage::disk('local')->delete($expense->attachment_path);
        }

        $expense->delete();

        return redirect($project ? route('projects.expenses.index', $project) : route('expenses.index'))
            ->with('success', 'Expense deleted.');
    }

    public function submit(Request $request, Access $access, Expense $expense, ?Project $project = null): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'expenses.submit'), 403);
        $this->guardExpenseAccess($request->user(), $expense, $project, $access);

        if (! in_array($expense->status, [DocumentStatus::DRAFT, DocumentStatus::REJECTED], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft or rejected expenses can be submitted.']);
        }

        $expense->update([
            'status' => DocumentStatus::SUBMITTED,
            'rejected_by' => null,
            'rejection_reason' => null,
        ]);

        return back()->with('success', 'Expense submitted for approval.');
    }

    public function approve(Request $request, Access $access, Expense $expense, ?Project $project = null): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'expenses.approve'), 403);
        $this->guardExpenseAccess($request->user(), $expense, $project, $access);

        if ($expense->status !== DocumentStatus::SUBMITTED) {
            throw ValidationException::withMessages(['status' => 'Only submitted expenses can be approved.']);
        }

        $expense->update([
            'status' => DocumentStatus::APPROVED,
            'approved_by' => $request->user()?->id,
            'rejected_by' => null,
            'rejection_reason' => null,
        ]);

        return back()->with('success', 'Expense approved.');
    }

    public function pdf(Request $request, Access $access, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf(
            $request,
            $access,
            $pdf,
            $this->routeExpense($request),
            $this->routeProject($request),
            false
        );
    }

    public function downloadPdf(Request $request, Access $access, DocumentPdf $pdf): HttpResponse
    {
        return $this->renderPdf(
            $request,
            $access,
            $pdf,
            $this->routeExpense($request),
            $this->routeProject($request),
            true
        );
    }

    private function routeExpense(Request $request): Expense
    {
        $expense = $request->route('expense');

        if ($expense instanceof Expense) {
            return $expense;
        }

        return Expense::query()->findOrFail($expense);
    }

    private function routeProject(Request $request): ?Project
    {
        $project = $request->route('project');

        if ($project instanceof Project) {
            return $project;
        }

        return $project ? Project::query()->find($project) : null;
    }

    private function renderPdf(Request $request, Access $access, DocumentPdf $pdf, Expense $expense, ?Project $project, bool $download): HttpResponse
    {
        abort_unless($access->allows($request->user(), 'expenses.print'), 403);
        $this->guardExpenseAccess($request->user(), $expense, $project, $access);
        $expense->load(['category', 'project', 'vendor', 'creator', 'approver']);

        $payload = [
            'title' => 'Expense Voucher',
            'number' => $expense->number,
            'status' => $expense->status,
            'expense' => $this->detailPayload($expense),
        ];

        return $download
            ? $pdf->download('pdf.expense', $payload, $expense->number)
            : $pdf->inline('pdf.expense', $payload, $expense->number);
    }

    public function reject(Request $request, Access $access, Expense $expense, ?Project $project = null): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'expenses.reject'), 403);
        $this->guardExpenseAccess($request->user(), $expense, $project, $access);

        if ($expense->status !== DocumentStatus::SUBMITTED) {
            throw ValidationException::withMessages(['status' => 'Only submitted expenses can be rejected.']);
        }

        $expense->update([
            'status' => DocumentStatus::REJECTED,
            'rejected_by' => $request->user()?->id,
            'rejection_reason' => $request->string('rejection_reason')->toString() ?: null,
            'approved_by' => null,
        ]);

        return back()->with('success', 'Expense rejected.');
    }

    private function persist(Request $request, ?Project $project, ?Expense $existing = null): Expense
    {
        if ($existing && ! in_array($existing->status, [DocumentStatus::DRAFT, DocumentStatus::REJECTED], true)) {
            throw ValidationException::withMessages(['status' => 'Approved or submitted expenses cannot be edited.']);
        }

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'description' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'payment_mode' => ['nullable', 'string', Rule::in(VendorPayment::MODES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'round_off' => ['nullable', 'numeric'],
            'payment_status' => ['required', Rule::in([Expense::PAYMENT_UNPAID, Expense::PAYMENT_PAID, Expense::PAYMENT_PARTIAL])],
            'notes' => ['nullable', 'string'],
            'attachment' => array_merge(['nullable'], StoredUpload::rules(StoredUpload::DOCUMENTS, 5120)),
        ]);

        $category = ExpenseCategory::query()->find((int) $validated['category_id']);
        if (! $category) {
            throw ValidationException::withMessages(['category_id' => 'Select a valid expense head.']);
        }
        if (! $category->is_active && (int) $existing?->category_id !== (int) $category->id) {
            throw ValidationException::withMessages(['category_id' => 'Select an active expense head.']);
        }

        $projectId = $project?->id ?? ($validated['project_id'] ?? null);
        if ($projectId) {
            app(Access::class)->abortUnlessVisibleProjectId($request->user(), (int) $projectId);
        }
        $amount = Money::scale((string) $validated['amount'], 2);
        $tax = Money::scale((string) ($validated['tax_amount'] ?? 0), 2);
        $round = Money::scale((string) ($validated['round_off'] ?? 0), 2);
        $total = Money::add(Money::add((string) $amount, (string) $tax), (string) $round);

        return DB::transaction(function () use ($request, $existing, $validated, $projectId, $amount, $tax, $round, $total) {
            $path = $existing?->attachment_path;

            if ($request->hasFile('attachment')) {
                if ($path) {
                    Storage::disk('local')->delete($path);
                }
                $path = StoredUpload::store($request->file('attachment'), 'expenses');
            }

            $payload = [
                'date' => $validated['date'],
                'project_id' => $projectId,
                'category_id' => $validated['category_id'],
                'description' => $validated['description'],
                'payee' => $validated['payee'] ?? null,
                'vendor_id' => $validated['vendor_id'] ?? null,
                'reference_number' => $validated['reference_number'] ?? null,
                'payment_mode' => $validated['payment_mode'] ?? null,
                'amount' => $amount,
                'tax_amount' => $tax,
                'round_off' => $round,
                'total_amount' => $total,
                'payment_status' => $validated['payment_status'],
                'notes' => $validated['notes'] ?? null,
                'attachment_path' => $path,
            ];

            if ($existing) {
                $existing->fill($payload)->save();

                return $existing->fresh();
            }

            return Expense::query()->create([
                ...$payload,
                'number' => DocumentNumber::next(Expense::class, 'number', 'EXP'),
                'status' => DocumentStatus::DRAFT,
                'created_by' => $request->user()?->id,
            ]);
        });
    }

    private function ensureExpense(Project $project, Expense $expense): void
    {
        abort_unless((int) $expense->project_id === (int) $project->id, 404);
    }

    private function guardExpenseAccess(?User $user, Expense $expense, ?Project $project, Access $access): void
    {
        abort_unless($user instanceof User, 403);

        if ($project) {
            $this->ensureExpense($project, $expense);
        }

        if ($expense->project_id) {
            $owned = $expense->project ?? Project::query()->find($expense->project_id);
            abort_unless($owned instanceof Project, 404);
            $access->abortUnlessCanAccessProject($user, $owned);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categories(?Expense $existing = null): array
    {
        return ExpenseCategory::query()
            ->where(function ($query) use ($existing) {
                $query->where('is_active', true);
                if ($existing?->category_id) {
                    $query->orWhereKey($existing->category_id);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (ExpenseCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'is_active' => $category->is_active,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vendorOptions(): array
    {
        return Vendor::query()->where('status', Vendor::ACTIVE)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Vendor $vendor) => ['id' => $vendor->id, 'name' => $vendor->name])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function projectCard(Project $project): array
    {
        return [
            'id' => $project->id,
            'project_name' => $project->project_name,
            'project_code' => $project->project_code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'number' => $expense->number,
            'date' => $expense->date?->toDateString(),
            'category' => $expense->category?->name,
            'project' => $expense->project?->project_name,
            'payee' => $expense->payee,
            'total_amount' => (string) $expense->total_amount,
            'payment_status' => $expense->payment_status,
            'status' => $expense->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'number' => $expense->number,
            'date' => $expense->date?->toDateString(),
            'project_id' => $expense->project_id,
            'project_name' => $expense->project?->project_name,
            'category_id' => $expense->category_id,
            'category_name' => $expense->category?->name,
            'description' => $expense->description,
            'payee' => $expense->payee,
            'vendor_id' => $expense->vendor_id,
            'vendor_name' => $expense->vendor?->name,
            'reference_number' => $expense->reference_number,
            'payment_mode' => $expense->payment_mode,
            'amount' => (string) $expense->amount,
            'tax_amount' => (string) $expense->tax_amount,
            'round_off' => (string) $expense->round_off,
            'total_amount' => (string) $expense->total_amount,
            'payment_status' => $expense->payment_status,
            'status' => $expense->status,
            'notes' => $expense->notes,
            'has_attachment' => (bool) $expense->attachment_path,
            'created_by' => $expense->creator?->name,
            'approved_by' => $expense->approver?->name,
            'rejection_reason' => $expense->rejection_reason,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function abilities($user, Expense $expense, Access $access): array
    {
        $editable = in_array($expense->status, [DocumentStatus::DRAFT, DocumentStatus::REJECTED], true);

        return [
            'edit' => $editable && $access->allows($user, 'expenses.edit'),
            'submit' => $editable && $access->allows($user, 'expenses.submit'),
            'approve' => $expense->status === DocumentStatus::SUBMITTED && $access->allows($user, 'expenses.approve'),
            'reject' => $expense->status === DocumentStatus::SUBMITTED && $access->allows($user, 'expenses.reject'),
            'print' => $access->allows($user, 'expenses.print'),
        ];
    }
}
