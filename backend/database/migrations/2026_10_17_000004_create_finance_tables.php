<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Iuran, pembayaran, alokasi, dan ledger kas. Semua nilai uang BIGINT rupiah.
 * Saldo dihitung dari finance_transactions; koreksi lewat transaksi 'reversal'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dues_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained('administrative_units');
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->bigInteger('default_amount');
            $table->string('frequency', 20);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampsTz();

            $table->index('unit_id');
        });
        DB::statement('ALTER TABLE dues_types ADD CONSTRAINT dues_types_default_amount_check CHECK (default_amount >= 0)');

        Schema::create('dues_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dues_type_id')->constrained('dues_types');
            $table->string('period_code', 30);
            $table->date('starts_on');
            $table->date('due_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 20);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['dues_type_id', 'period_code']);
        });

        Schema::create('dues_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dues_period_id')->constrained('dues_periods');
            $table->foreignUuid('household_id')->constrained('households');
            $table->bigInteger('amount_due');
            // Cache dari payment_allocations terverifikasi; wajib direkonsiliasi (PRD §9.1).
            $table->bigInteger('amount_paid')->default(0);
            $table->string('status', 20)->default('unpaid');
            $table->date('due_on');
            $table->timestampsTz();

            $table->unique(['dues_period_id', 'household_id']);
            $table->index(['household_id', 'status']);
        });
        DB::statement('ALTER TABLE dues_assessments ADD CONSTRAINT dues_assessments_amount_due_check CHECK (amount_due >= 0)');
        DB::statement('ALTER TABLE dues_assessments ADD CONSTRAINT dues_assessments_amount_paid_check CHECK (amount_paid >= 0)');
        DB::statement("ALTER TABLE dues_assessments ADD CONSTRAINT dues_assessments_status_check CHECK (status IN ('unpaid','partial','paid','waived','overdue'))");

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payer_user_id')->nullable()->constrained('users');
            $table->foreignUuid('household_id')->nullable()->constrained('households');
            $table->bigInteger('amount');
            $table->string('payment_method', 30);
            $table->string('status', 20)->default('submitted');
            $table->timestampTz('paid_at')->nullable();
            $table->string('reference_code', 100)->nullable();
            $table->foreignUuid('proof_file_id')->nullable()->constrained('file_objects');
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->timestampTz('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestampsTz();

            $table->index(['household_id', 'status']);
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('submitted','verified','rejected','refunded'))");

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments');
            $table->foreignUuid('dues_assessment_id')->constrained('dues_assessments');
            $table->bigInteger('amount');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['payment_id', 'dues_assessment_id']);
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_check CHECK (amount > 0)');

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained('administrative_units');
            $table->string('name', 100);
            $table->string('kind', 20);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['unit_id', 'kind', 'name']);
        });
        DB::statement("ALTER TABLE finance_categories ADD CONSTRAINT finance_categories_kind_check CHECK (kind IN ('income','expense'))");

        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('unit_id')->constrained('administrative_units');
            $table->foreignUuid('category_id')->constrained('finance_categories');
            $table->string('kind', 20);
            $table->bigInteger('amount');
            $table->timestampTz('occurred_at');
            $table->text('description');
            $table->string('reference_type', 50)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->foreignUuid('receipt_file_id')->nullable()->constrained('file_objects');
            $table->foreignUuid('created_by')->constrained('users');
            $table->uuid('reversed_transaction_id')->nullable();
            $table->timestampsTz();

            $table->index(['unit_id', 'occurred_at']);
            // Satu transaksi hanya bisa dibalik sekali.
            $table->unique('reversed_transaction_id');
        });
        DB::statement('ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_reversed_fk FOREIGN KEY (reversed_transaction_id) REFERENCES finance_transactions(id)');
        DB::statement('ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_kind_check CHECK (kind IN ('income','expense','reversal'))");
        DB::statement("ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_reversal_check CHECK ((kind = 'reversal') = (reversed_transaction_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('dues_assessments');
        Schema::dropIfExists('dues_periods');
        Schema::dropIfExists('dues_types');
    }
};
