<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_can_be_created(): void
    {
        $user = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $category = ExpenseCategory::query()->where('name', 'Labour')->firstOrFail();

        $this->actingAs($user)->post(route('projects.expenses.store', $project), [
            'date' => '2026-10-15',
            'category_id' => $category->id,
            'description' => 'Masonry labour',
            'payee' => 'Local contractor',
            'amount' => 12000,
            'tax_amount' => 0,
            'round_off' => 0,
            'payment_status' => 'paid',
            'payment_mode' => 'cash',
        ])->assertRedirect();

        $expense = Expense::query()->firstOrFail();
        $this->assertSame($project->id, $expense->project_id);
        $this->assertSame('12000.00', (string) $expense->total_amount);
        $this->assertStringStartsWith('EXP-', $expense->number);

        $this->actingAs($user)
            ->get(route('projects.expenses.pdf', [$project, $expense]))
            ->assertOk();
    }
}
