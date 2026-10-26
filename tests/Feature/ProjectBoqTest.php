<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\BoqItemBudget;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectBoqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_project_crud(): void
    {
        $this->post('/projects', [
            'project_name' => 'Chamber Works',
            'project_code' => 'CW-01',
            'description' => 'Masonry chambers',
        ])->assertRedirect();

        $project = Project::query()->where('project_code', 'CW-01')->firstOrFail();

        $this->get('/projects')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Index')
                ->where('projects.data.0.project_code', 'CW-01')
                ->where('projects.data.0.project_name', 'Chamber Works')
                ->where('projects.data.0.boq_total', '0.00')
                ->where('projects.data.0.budget_total', '0.00')
                ->where('projects.per_page', 30)
            );

        $this->put("/projects/{$project->id}", [
            'project_name' => 'Chamber Works Revised',
            'project_code' => 'CW-01',
            'description' => null,
        ])->assertRedirect('/projects');

        $this->assertSame('Chamber Works Revised', $project->fresh()->project_name);

        $this->post('/projects', [
            'project_name' => 'Duplicate',
            'project_code' => 'CW-01',
        ])->assertSessionHasErrors('project_code');

        $this->post('/projects', [
            'project_code' => 'CW-02',
        ])->assertSessionHasErrors('project_name');

        $this->delete("/projects/{$project->id}")->assertRedirect('/projects');
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_boq_amounts_gst_grand_total_and_parent_items_are_not_double_counted(): void
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create([
            'project_id' => $project->id,
            'title' => 'Main BOQ',
            'gst_percentage' => 0,
        ]);

        $this->put(route('projects.boqs.update', [$project, $boq]), [
            'title' => 'Main BOQ',
            'gst_percentage' => 18,
            'items' => [
                $this->row('a', '1', 'Excavation in all kinds of soil', '130', '275'),
                $this->row('b', '3', 'Construction of Masonry Chamber', null, null),
                $this->row('c', '3.1', '600x600 depth up to 1.5m', '10', '100', 'b'),
                $this->row('d', '3.2', '450x450 depth up to 1.2m', '2.5', '80.5', 'b'),
                $this->row('e', '4', 'Parent with its own rate that must be excluded', '1', '999'),
                $this->row('f', '4.1', 'Actual child work', '1', '10', 'e'),
            ],
        ])->assertSessionDoesntHaveErrors()->assertRedirect();

        $boq->refresh();
        $items = $boq->items()->get()->keyBy('item_number');

        $this->assertSame('35750.00', (string) $items['1']->amount);
        $this->assertNull($items['3']->amount);
        $this->assertSame('1000.00', (string) $items['3.1']->amount);
        $this->assertSame('201.25', (string) $items['3.2']->amount);
        $this->assertSame('999.00', (string) $items['4']->amount);
        $this->assertSame($items['3']->id, $items['3.1']->parent_id);
        $this->assertSame($items['3']->id, $items['3.2']->parent_id);

        $this->assertSame('36961.25', $boq->subtotal());
        $this->assertSame('6653.03', $boq->gstAmount());
        $this->assertSame('43614.28', $boq->grandTotal());

        $this->get(route('projects.boqs.show', [$project, $boq]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Boqs/Show')
                ->where('boq.subtotal', '36961.25')
                ->where('boq.gst_amount', '6653.03')
                ->where('boq.grand_total', '43614.28')
            );
    }

    public function test_rounding_and_validation_for_boq_items(): void
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id, 'gst_percentage' => 0]);

        $this->put(route('projects.boqs.update', [$project, $boq]), [
            'title' => 'Rates',
            'gst_percentage' => 0,
            'items' => [
                $this->row('a', '1', 'Rounded line', '10.5', '10.25'),
            ],
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('107.63', (string) $boq->items()->first()->amount);

        $this->put(route('projects.boqs.update', [$project, $boq]), [
            'title' => 'Rates',
            'gst_percentage' => -1,
            'items' => [
                $this->row('a', '1', 'Rounded line', '10.5', '10.25', null, $boq->items()->first()->id),
            ],
        ])->assertSessionHasErrors('gst_percentage');

        $this->put(route('projects.boqs.update', [$project, $boq]), [
            'title' => 'Rates',
            'gst_percentage' => 0,
            'items' => [
                $this->row('a', '1', 'Rounded line', '-1', '10'),
            ],
        ])->assertSessionHasErrors('items.0.quantity');
    }

    public function test_budget_entries_and_project_totals_span_every_boq(): void
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create([
            'project_id' => $project->id,
            'gst_percentage' => 18,
        ]);

        $this->put(route('projects.boqs.update', [$project, $boq]), [
            'title' => 'Civil',
            'gst_percentage' => 18,
            'items' => [
                $this->row('a', '1', 'Excavation', '130', '275'),
                $this->row('b', '3', 'Masonry chamber', null, null),
                $this->row('c', '3.1', '600 chamber', '10', '100', 'b'),
            ],
        ])->assertSessionDoesntHaveErrors();

        $excavation = $boq->items()->where('item_number', '1')->firstOrFail();
        $header = $boq->items()->where('item_number', '3')->firstOrFail();
        $child = $boq->items()->where('item_number', '3.1')->firstOrFail();

        $this->put(route('projects.boqs.items.budget.update', [$project, $boq, $excavation]), [
            'entries' => [
                ['name' => 'Material', 'amount' => 20000],
                ['name' => 'Labour', 'amount' => 8000],
                ['name' => 'Equipment', 'amount' => 2500],
                ['name' => 'Miscellaneous', 'amount' => 1500],
                ['name' => 'Transport', 'amount' => 2000],
            ],
        ])->assertSessionDoesntHaveErrors()->assertRedirect(route('projects.boqs.show', [$project, $boq]));

        $this->assertSame('34000.00', $excavation->fresh()->budgetTotal());

        $this->put(route('projects.boqs.items.budget.update', [$project, $boq, $header]), [
            'entries' => $this->defaults(50),
        ])->assertSessionDoesntHaveErrors();

        $this->put(route('projects.boqs.items.budget.update', [$project, $boq, $child]), [
            'entries' => [
                ['name' => 'Material', 'amount' => 100],
                ['name' => 'Labour', 'amount' => 0],
                ['name' => 'Equipment', 'amount' => 0],
                ['name' => 'Miscellaneous', 'amount' => 0],
            ],
        ])->assertSessionDoesntHaveErrors();

        $transport = $excavation->budgets()->where('name', 'Transport')->firstOrFail();
        $material = $excavation->budgets()->where('name', 'Material')->firstOrFail();

        $this->delete(route('projects.boqs.items.budgets.destroy', [$project, $boq, $excavation, $material]))
            ->assertSessionHasErrors('budget');
        $this->assertDatabaseHas('boq_item_budgets', ['id' => $material->id]);

        $this->delete(route('projects.boqs.items.budgets.destroy', [$project, $boq, $excavation, $transport]))
            ->assertSessionDoesntHaveErrors();
        $this->assertDatabaseMissing('boq_item_budgets', ['id' => $transport->id]);
        $this->assertSame('32000.00', $excavation->fresh()->budgetTotal());

        $second = Boq::factory()->create([
            'project_id' => $project->id,
            'title' => 'Second',
            'gst_percentage' => 0,
        ]);
        $secondItem = BoqItem::factory()->create([
            'boq_id' => $second->id,
            'item_number' => '1',
            'quantity' => 2,
            'rate' => 50,
        ]);
        $this->assertSame('100.00', (string) $secondItem->amount);
        $this->assertSame('100.00', $second->fresh()->grandTotal());

        $boq->refresh();
        $projectBoqTotal = bcadd($boq->grandTotal(), $second->grandTotal(), 2);
        $projectBudgetTotal = bcadd($boq->budgetTotal(), $second->budgetTotal(), 2);

        $this->assertSame('32150.00', $projectBudgetTotal);

        $this->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Dashboard')
                ->where('project.boq_total', $projectBoqTotal)
                ->where('project.budget_total', '32150.00')
                ->has('boqs', 2)
                ->where('boqs.0.items.0.description', $excavation->description)
            );

        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('projects.0.boq_total', $projectBoqTotal)
                ->where('projects.0.budget_total', '32150.00')
            );

        $this->put(route('projects.boqs.items.budget.update', [$project, $boq, $excavation]), [
            'entries' => [
                ['name' => 'Labour', 'amount' => 1],
            ],
        ])->assertSessionHasErrors('entries');
    }

    public function test_projects_are_isolated_and_deletes_cascade(): void
    {
        $projectA = Project::factory()->create(['project_code' => 'A-1', 'project_name' => 'Project A']);
        $projectB = Project::factory()->create(['project_code' => 'B-1', 'project_name' => 'Project B']);

        $boqA = Boq::factory()->create(['project_id' => $projectA->id, 'title' => 'Only A']);
        $boqB = Boq::factory()->create(['project_id' => $projectB->id, 'title' => 'Only B', 'gst_percentage' => 0]);
        $itemA = BoqItem::factory()->create(['boq_id' => $boqA->id, 'quantity' => 3, 'rate' => 10]);
        $childA = BoqItem::factory()->create([
            'boq_id' => $boqA->id,
            'parent_id' => $itemA->id,
            'item_number' => '1.1',
            'quantity' => 1,
            'rate' => 5,
        ]);
        $budgetA = BoqItemBudget::factory()->create([
            'boq_item_id' => $itemA->id,
            'name' => 'Material',
            'amount' => 25,
        ]);
        BoqItem::factory()->create(['boq_id' => $boqB->id, 'quantity' => 8, 'rate' => 8]);

        $this->get(route('projects.boqs.show', [$projectB, $boqA]))->assertNotFound();
        $this->get(route('projects.boqs.items.budget.edit', [$projectB, $boqA, $itemA]))->assertNotFound();
        $this->put(route('projects.boqs.items.budget.update', [$projectA, $boqB, $itemA]), [
            'entries' => $this->defaults(1),
        ])->assertNotFound();

        $this->get(route('projects.boqs.index', $projectA))
            ->assertInertia(fn (Assert $page) => $page
                ->has('boqs.data', 1)
                ->where('boqs.data.0.title', 'Only A')
            );

        $this->get(route('projects.show', $projectB))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.boq_total', '64.00')
                ->where('project.budget_total', '0.00')
            );

        $this->get(route('projects.show', $projectA))
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.budget_total', '25.00')
            );

        $itemA->delete();
        $this->assertDatabaseMissing('boq_items', ['id' => $childA->id]);
        $this->assertDatabaseMissing('boq_item_budgets', ['id' => $budgetA->id]);

        $replacement = BoqItem::factory()->create(['boq_id' => $boqA->id]);
        $replacementBudget = BoqItemBudget::factory()->create(['boq_item_id' => $replacement->id]);

        $this->delete(route('projects.destroy', $projectA))->assertRedirect();

        $this->assertDatabaseMissing('projects', ['id' => $projectA->id]);
        $this->assertDatabaseMissing('boqs', ['id' => $boqA->id]);
        $this->assertDatabaseMissing('boq_items', ['id' => $replacement->id]);
        $this->assertDatabaseMissing('boq_item_budgets', ['id' => $replacementBudget->id]);
        $this->assertDatabaseHas('projects', ['id' => $projectB->id]);
        $this->assertDatabaseHas('boqs', ['id' => $boqB->id]);
    }

    public function test_removing_a_boq_item_on_save_deletes_it(): void
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id]);
        $keep = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => '1',
            'description' => 'Keep',
            'quantity' => 1,
            'rate' => 20,
        ]);
        $remove = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => '2',
            'description' => 'Remove',
            'quantity' => 1,
            'rate' => 20,
            'sort_order' => 1,
        ]);

        $this->put(route('projects.boqs.update', [$project, $boq]), [
            'title' => $boq->title,
            'gst_percentage' => 0,
            'items' => [
                $this->row('a', '1', 'Keep', '2', '20', null, $keep->id),
            ],
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseMissing('boq_items', ['id' => $remove->id]);
        $this->assertSame('40.00', (string) $keep->fresh()->amount);
    }

    private function row(
        string $key,
        string $number,
        string $description,
        ?string $quantity,
        ?string $rate,
        ?string $parentKey = null,
        ?int $id = null,
    ): array {
        return [
            'id' => $id,
            'client_key' => $key,
            'parent_key' => $parentKey,
            'item_number' => $number,
            'description' => $description,
            'unit' => $quantity === null ? null : 'Cum',
            'quantity' => $quantity,
            'rate' => $rate,
        ];
    }

    private function defaults(float|int|string $material): array
    {
        return [
            ['name' => 'Material', 'amount' => $material],
            ['name' => 'Labour', 'amount' => 0],
            ['name' => 'Equipment', 'amount' => 0],
            ['name' => 'Miscellaneous', 'amount' => 0],
        ];
    }
}
