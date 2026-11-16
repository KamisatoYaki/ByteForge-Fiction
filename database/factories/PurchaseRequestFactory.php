<?php

namespace Database\Factories;

use App\Enums\PurchaseRequestStatus;
use App\Models\Company;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    protected $model = PurchaseRequest::class;

    public function definition(): array
    {
        return [
            'request_number' => 'PR-'.now()->format('Y').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'status' => PurchaseRequestStatus::Draft,
            'requested_by' => User::factory(),
            'company_id' => null,
            'department_id' => null,
            'title' => $this->faker->sentence(3),
            'purpose' => $this->faker->paragraph(),
            'currency' => 'USD',
            'estimated_total' => 0,
            'submitted_at' => null,
            'notes' => null,
            'created_by' => fn (array $attrs) => $attrs['requested_by'],
        ];
    }

    public function submitted(): static
    {
        return $this->state([
            'status' => PurchaseRequestStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'status' => PurchaseRequestStatus::Approved,
            'submitted_at' => now()->subDay(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => PurchaseRequestStatus::Rejected,
            'submitted_at' => now()->subDay(),
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(['company_id' => $company->id]);
    }
}
