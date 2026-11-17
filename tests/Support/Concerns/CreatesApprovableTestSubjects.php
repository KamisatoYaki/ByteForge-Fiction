<?php

namespace Tests\Support\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\Models\ApprovableTestSubject;

/**
 * Creates a disposable table for ApprovableTestSubject.
 *
 * MySQL implicitly commits DDL. Creating this table inside a
 * LazilyRefreshDatabase test transaction desyncs Laravel's transaction
 * counter and breaks nested SAVEPOINT rollback. The table is therefore
 * created in refreshTestDatabase() after migrate and before
 * beginDatabaseTransaction().
 */
trait CreatesApprovableTestSubjects
{
    /**
     * Refresh the test database, ensuring the disposable Approvable
     * fixture table exists before the per-test transaction starts.
     */
    protected function refreshTestDatabase()
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->migrateDatabases();

            $this->app[Kernel::class]->setArtisan(null);

            $this->updateLocalCacheOfInMemoryDatabases();

            RefreshDatabaseState::$migrated = true;
        }

        $this->ensureApprovableTestSubjectTable();

        $this->beginDatabaseTransaction();
    }

    /**
     * Create approvable_test_subjects when missing, only outside a transaction.
     */
    protected function ensureApprovableTestSubjectTable(): void
    {
        if (Schema::hasTable('approvable_test_subjects')) {
            return;
        }

        if (DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'approvable_test_subjects is missing while a database transaction is active. '
                .'Schema::create() would implicitly commit on MySQL and corrupt LazilyRefreshDatabase '
                .'savepoints. Create the table after migrations and before beginDatabaseTransaction().'
            );
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
