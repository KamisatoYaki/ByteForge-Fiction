<?php

namespace Database\Factories;

use App\Enums\ApprovalDecision;
use App\Models\User;
use App\Models\WorkflowApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowApproval>
 */
class WorkflowApprovalFactory extends Factory
{
    protected $model = WorkflowApproval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'decision' => ApprovalDecision::Approved,
            'decided_by' => User::factory(),
            'decided_at' => now(),
            'comments' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(['decision' => ApprovalDecision::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(['decision' => ApprovalDecision::Rejected]);
    }
}
