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
        Schema::create('vendor_stops', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 191)->nullable()->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->uuid('company_uuid')->nullable()->index();
            $table->uuid('service_visit_uuid')->nullable()->index();
            $table->uuid('vendor_uuid')->nullable()->index();
            $table->string('vendor_name')->nullable();
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('approval_requested_at')->nullable();
            $table->dateTime('approval_received_at')->nullable();
            $table->dateTime('left_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('client_uuid', 64)->nullable();
            $table->string('legacy_ref')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
            $table->softDeletes();
            $table->unique(['company_uuid', 'client_uuid'], 'vendor_stops_client_uuid_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_stops');
    }
};
