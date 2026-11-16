<?php

namespace App\Models\Traits;

use App\Models\WorkflowApproval;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Opt-in polymorphic approval-history relation for Approvable entities.
 */
trait HasWorkflowApprovals
{
    /**
     * @return MorphMany<WorkflowApproval, $this>
     */
    public function workflowApprovals(): MorphMany
    {
        return $this->morphMany(WorkflowApproval::class, 'approvable');
    }
}
