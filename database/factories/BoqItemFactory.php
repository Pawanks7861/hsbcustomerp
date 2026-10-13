<?php

namespace Database\Factories;

use App\Models\Boq;
use App\Models\BoqItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoqItem>
 */
class BoqItemFactory extends Factory
{
    protected $model = BoqItem::class;

    public function definition(): array
    {
        return [
            'boq_id' => Boq::factory(),
            'parent_id' => null,
            'sort_order' => 0,
            'item_number' => '1',
            'description' => 'Excavation',
            'unit' => 'Cum',
            'quantity' => 1,
            'rate' => 100,
        ];
    }
}
