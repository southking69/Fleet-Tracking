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
        Schema::create('service_visits', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('work_order_uuid')->nullable()->index();
            $table->uuid('vehicle_uuid')->nullable()->index();
            $table->string('plate')->nullable()->index();
            $table->string('make_model')->nullable();
            $table->uuid('technician_uuid')->nullable()->index();
            $table->decimal('odometer', 12, 2)->nullable();
            $table->dateTime('arrived_at_lsa')->nullable();
            $table->dateTime('picked_up_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->boolean('pickup_inspected')->default(false);
            $table->boolean('return_inspected')->default(false);
            $table->string('lane')->nullable();
            $table->decimal('target_hours', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('not_started')->index();
            $table->string('client_uuid', 64)->nullable();
            $table->string('legacy_ref')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
            $table->softDeletes();
            $table->index(['company_uuid', 'status']);
            $table->index(['company_uuid', 'arrived_at_lsa']);
            $table->unique(['company_uuid', 'client_uuid'], 'service_visits_client_uuid_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_visits');
    }
};
