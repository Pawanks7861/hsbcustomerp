<?php

namespace Database\Factories;

use App\Models\Boq;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Boq>
 */
class BoqFactory extends Factory
{
    protected $model = Boq::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => 'BOQ',
            'gst_percentage' => 18,
        ];
    }
}
