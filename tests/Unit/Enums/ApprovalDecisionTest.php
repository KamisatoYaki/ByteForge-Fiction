<?php

namespace Tests\Unit\Enums;

use App\Enums\ApprovalDecision;
use Tests\TestCase;

class ApprovalDecisionTest extends TestCase
{
    public function test_only_approved_and_rejected_cases_exist(): void
    {
        $cases = array_map(
            static fn (ApprovalDecision $case) => $case->value,
            ApprovalDecision::cases()
        );

        $this->assertSame(['approved', 'rejected'], $cases);
    }

    public function test_labels_resolve_from_translations(): void
    {
        $this->assertSame(trans('general.approved'), ApprovalDecision::Approved->label());
        $this->assertSame(trans('general.rejected'), ApprovalDecision::Rejected->label());
    }
}
