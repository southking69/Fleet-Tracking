<?php

namespace Jalal\ServiceTracker\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Permissions, policies and roles are defined by `Jalal\ServiceTracker\Auth\Schemas\ServiceTracker`, the way every
 * Fleetbase extension does it, and materialised by core-api's `fleetbase:create-permissions` (idempotent
 * updateOrCreate; also regenerates Directives for every installed schema). This seeder only triggers it.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('fleetbase:create-permissions');
    }
}
