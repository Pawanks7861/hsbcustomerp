<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use App\Services\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request, Access $access): Response
    {
        abort_unless($access->allows($request->user(), 'expenses.manage_categories'), 403);

        return Inertia::render('Expenses/Categories', [
            'categories' => ExpenseCategory::query()->orderBy('name')->get()->map(fn (ExpenseCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'is_active' => $category->is_active,
            ]),
        ]);
    }

    public function store(Request $request, Access $access): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'expenses.manage_categories'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:expense_categories,name'],
        ]);

        ExpenseCategory::query()->create([
            'name' => $validated['name'],
            'is_active' => true,
        ]);

        return back()->with('success', 'Category created.');
    }

    public function update(Request $request, Access $access, ExpenseCategory $expenseCategory): RedirectResponse
    {
        abort_unless($access->allows($request->user(), 'expenses.manage_categories'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:expense_categories,name,'.$expenseCategory->id],
            'is_active' => ['required', 'boolean'],
        ]);

        $expenseCategory->update($validated);

        return back()->with('success', 'Category updated.');
    }
}
