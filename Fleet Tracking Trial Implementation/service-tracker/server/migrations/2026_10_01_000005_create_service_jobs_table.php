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
        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('service_visit_uuid')->nullable()->index();
            $table->uuid('catalog_item_uuid')->nullable()->index();
            $table->string('service_name');
            $table->uuid('vendor_uuid')->nullable()->index();
            $table->string('vendor_name')->nullable();
            $table->boolean('approval_needed')->default(false);
            $table->string('status', 32)->default('Pending')->index();
            $table->text('parts_replaced')->nullable();
            $table->unsignedInteger('next_due_override_km')->nullable();
            $table->date('next_due_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('client_uuid', 64)->nullable();
            $table->string('legacy_ref')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
            $table->softDeletes();
            $table->unique(['company_uuid', 'client_uuid'], 'service_jobs_client_uuid_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_jobs');
    }
};
