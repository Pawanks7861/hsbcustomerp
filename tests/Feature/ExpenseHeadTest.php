<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseHeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_head_is_required_and_inactive_heads_cannot_be_assigned(): void
    {
        $user = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $active = ExpenseCategory::query()->where('name', 'Labour')->firstOrFail();
        $inactive = ExpenseCategory::query()->create(['name' => 'Archived Head', 'is_active' => false]);

        $this->actingAs($user)->post(route('projects.expenses.store', $project), [
            'date' => '2026-10-15',
            'description' => 'Missing head',
            'amount' => 100,
            'payment_status' => 'unpaid',
        ])->assertSessionHasErrors('category_id');

        $this->actingAs($user)->post(route('projects.expenses.store', $project), [
            'date' => '2026-10-15',
            'category_id' => $inactive->id,
            'description' => 'Inactive head',
            'amount' => 100,
            'payment_status' => 'unpaid',
        ])->assertSessionHasErrors('category_id');

        $this->actingAs($user)->post(route('projects.expenses.store', $project), [
            'date' => '2026-10-15',
            'category_id' => $active->id,
            'description' => 'Masonry',
            'amount' => 250,
            'payment_status' => 'unpaid',
        ])->assertRedirect();

        $expense = Expense::query()->firstOrFail();
        $this->assertSame($active->id, $expense->category_id);

        $this->actingAs($user)
            ->get(route('projects.expenses.index', $project).'?category_id='.$active->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('expenses.data', 1));
    }

    public function test_historical_expense_with_inactive_head_remains_accessible(): void
    {
        $user = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $head = ExpenseCategory::query()->create(['name' => 'Old Head', 'is_active' => true]);

        $this->actingAs($user)->post(route('projects.expenses.store', $project), [
            'date' => '2026-10-15',
            'category_id' => $head->id,
            'description' => 'Historic',
            'amount' => 80,
            'payment_status' => 'unpaid',
        ])->assertRedirect();

        $head->update(['is_active' => false]);
        $expense = Expense::query()->firstOrFail();

        $this->actingAs($user)
            ->get(route('projects.expenses.show', [$project, $expense]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('expense.category_name', 'Old Head'));
    }
}
