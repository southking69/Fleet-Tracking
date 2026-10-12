<?php

namespace Jalal\ServiceTracker\Auth\Schemas;

/**
 * Service Tracker permission schema.
 *
 * Discovered by Fleetbase\Support\Utils::getAuthSchemaNamespaces() (package must carry the
 * `fleetbase-extension` composer keyword; class must live in `<psr-4 root>/Auth/Schemas`) and
 * materialised by `php artisan fleetbase:create-permissions` (core-api CreatePermissions).
 *
 * Permission names are `{name} {action} {resource}` (3 segments). For each resource the command creates
 * `{name} * {resource}` plus one permission per action in [create, update, delete, view, list, see]
 * + the resource's own `actions`, and the schema-level `{name} see extension` / `{name} *` permissions.
 * Policy / role `permissions` entries may omit the service prefix (`'* service-visit'`, `'list service-visit'`).
 */
class ServiceTracker
{
    /** The permission schema name (service). */
    public string $name = 'service-tracker';

    /** Used by CreatePermissions to name the generated `{PolicyName}FullAccess` / `{PolicyName}ReadOnly` policies. */
    public string $policyName = 'ServiceTracker';

    /** Guards these permissions apply to. */
    public array $guards = ['sanctum'];

    /** The permission schema resources. */
    public array $resources = [
        ['name' => 'service-visit', 'actions' => ['export']],
        ['name' => 'vendor-stop', 'actions' => []],
        ['name' => 'service-job', 'actions' => []],
        ['name' => 'service-catalog', 'actions' => []],
        ['name' => 'service-lane', 'actions' => []],
        ['name' => 'damage-record', 'actions' => []],
        ['name' => 'petty-cash', 'actions' => ['export']],
        ['name' => 'tracker-dashboard', 'actions' => []],
        ['name' => 'visit-report', 'actions' => ['export']],
        ['name' => 'vehicle-due', 'actions' => []],
        ['name' => 'tracker-settings', 'actions' => []],
    ];

    /**
     * Policies. The controllers additionally enforce "technician sees/edits only own visits",
     * which cannot be expressed as a permission name.
     */
    public array $policies = [
        [
            'name'        => 'ServiceTrackerSupervisor',
            'description' => 'Service Tracker supervisor: full access to visits, catalog, lanes, reports, exports and settings.',
            'permissions' => [
                'see extension',
                '* service-visit',
                '* vendor-stop',
                '* service-job',
                '* service-catalog',
                '* service-lane',
                '* damage-record',
                '* petty-cash',
                '* tracker-dashboard',
                '* visit-report',
                '* vehicle-due',
                '* tracker-settings',
            ],
        ],
        [
            'name'        => 'ServiceTrackerTechnician',
            'description' => 'Service Tracker technician: create/update visits, stops and jobs, record damage and petty cash. No delete, no settings.',
            'permissions' => [
                'see extension',
                'see service-visit', 'list service-visit', 'view service-visit', 'create service-visit', 'update service-visit',
                'see vendor-stop', 'list vendor-stop', 'view vendor-stop', 'create vendor-stop', 'update vendor-stop',
                'see service-job', 'list service-job', 'view service-job', 'create service-job', 'update service-job',
                'see damage-record', 'list damage-record', 'view damage-record', 'create damage-record',
                'see petty-cash', 'list petty-cash', 'view petty-cash', 'create petty-cash',
                'see service-catalog', 'list service-catalog', 'view service-catalog',
                'see service-lane', 'list service-lane', 'view service-lane',
                'see tracker-dashboard', 'list tracker-dashboard', 'view tracker-dashboard',
                'see visit-report', 'list visit-report', 'view visit-report',
                'see vehicle-due', 'list vehicle-due', 'view vehicle-due',
            ],
        ],
    ];

    /**
     * Roles. Names are what the controllers test with `hasRole()` (ServiceTrackerController: 'Supervisor' / 'Technician').
     */
    public array $roles = [
        [
            'name'        => 'Supervisor',
            'description' => 'Service Tracker supervisor.',
            'policies'    => ['ServiceTrackerSupervisor'],
        ],
        [
            'name'        => 'Technician',
            'description' => 'Service Tracker technician.',
            'policies'    => ['ServiceTrackerTechnician'],
        ],
    ];
}
