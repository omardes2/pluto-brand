<?php

namespace Database\Factories\Hr;

use App\Modules\Hr\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->numerify('05########'),
            'job_title' => fake()->randomElement(['كاشير', 'موظف مبيعات', 'محاسب', 'مشرف']),
            'monthly_salary' => fake()->randomElement([1500, 2000, 2500, 3000]),
            'hire_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
