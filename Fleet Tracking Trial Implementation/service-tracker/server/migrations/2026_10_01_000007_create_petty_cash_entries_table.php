<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('petty_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->date('entry_date')->nullable()->index();
            $table->uuid('vehicle_uuid')->nullable()->index();
            $table->string('plate')->nullable()->index();
            $table->uuid('service_visit_uuid')->nullable()->index();
            $table->string('category')->nullable()->index();
            $table->bigInteger('amount')->default(0); // minor units (cents)
            $table->char('currency', 3)->default('SAR');
            $table->string('paid_by')->nullable();
            $table->text('description')->nullable();
            $table->uuid('journal_uuid')->nullable()->index();
            $table->string('client_uuid', 64)->nullable();
            $table->string('legacy_ref')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
            $table->softDeletes();
            $table->unique(['company_uuid', 'client_uuid'], 'petty_cash_entries_client_uuid_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('petty_cash_entries');
    }
};
