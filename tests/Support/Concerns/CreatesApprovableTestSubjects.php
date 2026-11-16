<?php

namespace Tests\Support\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Models\ApprovableTestSubject;

/**
 * Creates a disposable table for ApprovableTestSubject.
 *
 * Uses Schema::create once per process when missing. On MySQL this is DDL
 * and can force LazilyRefreshDatabase to remigrate afterward — acceptable
 * for this isolated foundation suite; prefer delete() over truncate().
 */
trait CreatesApprovableTestSubjects
{
    protected function ensureApprovableTestSubjectTable(): void
    {
        if (Schema::hasTable('approvable_test_subjects')) {
            return;
        }

        Schema::create('approvable_test_subjects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('status')->default('draft');
            $table->unsignedInteger('requested_by')->nullable();
            $table->unsignedInteger('company_id')->nullable();
            $table->string('approval_ability')->default('purchase_requests.approve');
            $table->boolean('fail_status_transition')->default(false);
            $table->timestamps();
        });
    }

    protected function makeApprovableTestSubject(array $attributes = []): ApprovableTestSubject
    {
        $this->ensureApprovableTestSubjectTable();

        return ApprovableTestSubject::query()->create(array_merge([
            'status' => 'submitted',
            'requested_by' => null,
            'company_id' => null,
            'approval_ability' => 'purchase_requests.approve',
            'fail_status_transition' => false,
        ], $attributes));
    }
}
