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
        Schema::create('damage_records', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('service_visit_uuid')->nullable()->index();
            $table->uuid('vehicle_uuid')->nullable()->index();
            $table->string('stage', 32)->nullable()->index();
            $table->string('location')->nullable();
            $table->string('damage_type')->nullable();
            $table->string('severity', 32)->nullable();
            $table->string('condition', 32)->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->uuid('recorded_by_uuid')->nullable()->index();
            $table->string('client_notified', 8)->nullable();
            $table->uuid('issue_uuid')->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('client_uuid', 64)->nullable();
            $table->string('legacy_ref')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
            $table->softDeletes();
            $table->unique(['company_uuid', 'client_uuid'], 'damage_records_client_uuid_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('damage_records');
    }
};
