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
    private const DESIGNATIONS = [
        'Administrative Aide I',
        'Administrative Aide III',
        'Administrative Officer I',
        'Administrative Officer III',
        'Administrative Officer V',
        'Clerk III',
        'Engineer II',
        'Accountant I',
        'Records Officer I',
        'Planning Officer II',
    ];

    private const STATUSES = [
        'CO-TERMINOUS',
        'ELECTIVE',
        'CASUAL',
        'REGULAR',
    ];

    private static int $sequence = 0;

    public function definition(): array
    {
        self::$sequence++;
        $controlNo = str_pad((string) self::$sequence, 6, '0', STR_PAD_LEFT);
        $department = Department::inRandomOrder()->first();

        return [
            'control_no'  => $controlNo,
            'surname'     => fake()->lastName(),
            'firstname'   => fake()->firstName(),
            'middlename'  => fake()->optional(0.7)->lastName(),
            'office'      => $department?->name ?? 'General Services',
            'status'      => fake()->randomElement(self::STATUSES),
            'designation' => fake()->randomElement(self::DESIGNATIONS),
            'rate_mon'    => fake()->randomFloat(2, 10000, 80000),
        ];
    }

    /**
     * Assign the employee to a specific department (by office name).
     */
    public function forDepartment(Department $department): static
    {
        return $this->state(fn (array $attributes) => [
            'office' => $department->name,
        ]);
    }
}
