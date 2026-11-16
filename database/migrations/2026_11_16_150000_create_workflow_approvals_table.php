<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ByteForge thin approval-history store.
 *
 * Append-only decision rows linked polymorphically to future business
 * entities (purchase requests, transfers, disposals, etc.). The parent
 * entity owns its lifecycle/status; this table records who decided what
 * and when. No company_id — FMCS access is inherited from the parent
 * via Approvable::approvalCompanyable().
 *
 * No FK constraints (Snipe-IT convention). No multi-stage routing,
 * return-for-revision, or parallel voting columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_approvals', function (Blueprint $table) {
            $table->increments('id');
            // Morph pair without FK constraints. unsignedBigInteger matches
            // Laravel morphs() and future bigIncrements parent keys.
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');
            $table->string('decision');
            $table->integer('decided_by');
            $table->timestamp('decided_at');
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id']);
            $table->index('decided_by');
            $table->index('decision');
            $table->index('decided_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_approvals');
    }
};
