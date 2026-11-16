<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ByteForge Purchase Request workflow tables.
 *
 * Separate from orders/order_items (acquisition ledger). No FK constraints
 * (Snipe-IT convention). request_number uniqueness + sequence table support
 * concurrent PR-YYYY-NNNN generation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_value')->default(0);
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->string('request_number');
            $table->string('status')->default('draft');
            $table->integer('requested_by');
            $table->integer('company_id')->nullable();
            $table->integer('department_id')->nullable();
            $table->string('title');
            $table->text('purpose')->nullable();
            $table->string('currency', 10)->nullable();
            $table->decimal('estimated_total', 20, 4)->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('request_number');
            $table->index('status');
            $table->index('requested_by');
            $table->index('company_id');
            $table->index('department_id');
            $table->index('submitted_at');
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('purchase_request_id');
            $table->string('description');
            $table->integer('quantity')->default(1);
            $table->decimal('estimated_unit_cost', 20, 4)->default(0);
            $table->decimal('estimated_line_total', 20, 4)->default(0);
            $table->integer('category_id')->nullable();
            $table->text('specifications')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('purchase_request_id');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('purchase_request_number_sequences');
    }
};
