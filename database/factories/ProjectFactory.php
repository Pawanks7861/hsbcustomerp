<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'project_name' => fake()->words(3, true),
            'project_code' => strtoupper(fake()->unique()->bothify('PRJ-###')),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
