<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employee>
 */
class EmployeeFactory extends Factory
{
    private const POSITIONS = [
        'Staff',
        'Officer',
        'Clerk',
        'Specialist',
        'Analyst',
        'Coordinator',
        'Assistant',
        'Representative',
    ];

    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'first_name'     => fake()->firstName(),
            'last_name'      => fake()->lastName(),
            'birthdate'      => fake()->dateTimeBetween('-50 years', '-18 years')->format('Y-m-d'),
            'position'       => fake()->randomElement(self::POSITIONS),
            'status'         => fake()->randomElement(Employee::STATUSES),
        ];
    }

    public function forDepartment(Department $department): static
    {
        return $this->state(fn (array $attributes) => [
            'department_id' => $department->id,
        ]);
    }
}
