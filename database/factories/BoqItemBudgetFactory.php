<?php

namespace Database\Factories;

use App\Models\BoqItem;
use App\Models\BoqItemBudget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqItemBudget>
 */
class BoqItemBudgetFactory extends Factory
{
    protected $model = BoqItemBudget::class;

    public function definition(): array
    {
        return [
            'boq_item_id' => BoqItem::factory(),
            'name' => 'Material',
            'amount' => 0,
            'sort_order' => 0,
        ];
    }
}
