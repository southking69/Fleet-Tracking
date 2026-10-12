<?php

namespace Jalal\ServiceTracker\Seeders;

/**
 * Default Service Tracker settings and dropdown lists.
 * Mirrors defaultSettings() in the reference app (src/Index.html).
 */
class DefaultSettings
{
    public const SETTINGS_KEY = 'service-tracker.settings';

    public static function all(): array
    {
        return [
            'company'         => 'Jalal Holding',
            'client'          => '',
            'lanes'           => [['name' => 'Express', 'target' => 12], ['name' => 'Standard', 'target' => 24], ['name' => 'Heavy', 'target' => 48]],
            'vendors'         => ['Local Mechanic', 'Petromin', 'Tyre Vendor', 'Other Specialist'],
            'staff'           => ['Supervisor', 'Technician 1', 'Technician 2'],
            'categories'      => ['Routine Maintenance', 'Tyres & Wheels', 'Brakes', 'Engine & Cooling', 'Transmission & Drivetrain', 'Suspension & Steering', 'Electrical & Electronics', 'A/C & Heating', 'Body, Glass & Interior', 'Inspection & Compliance', 'Recovery & Roadside', 'Other'],
            'damageLocations' => ['Front bumper', 'Rear bumper', 'Hood', 'Roof', 'Trunk', 'Windshield', 'Rear window', 'Left front door', 'Left rear door', 'Right front door', 'Right rear door', 'Left mirror', 'Right mirror', 'Wheel / Rim', 'Tyre', 'Interior', 'Other'],
            'expenseCats'     => ['Fuel', 'Spare parts', 'Oil / fluids top-up', 'Towing / transport', 'Car wash', 'Parking / tolls', 'Tools / consumables', 'Other'],
            'currency'        => 'SAR',
            'damageTypes'     => ['Scratch', 'Dent', 'Crack', 'Broken part', 'Missing item', 'Stain / interior damage', 'Other'],
            'severities'      => ['Minor', 'Moderate', 'Major'],
            'stages'          => ['Pickup inspection', 'Return inspection', 'Vendor reported'],
            'svcStatuses'     => ['Pending', 'In progress', 'Done'],
            'reportOpts'      => ['turnaround' => true, 'vendorTimes' => true, 'photos' => true, 'overdue' => true, 'condPhotos' => false, 'petty' => true],
            'excelTiming'     => true,
            'excelPetty'      => false,
            'excelVehicles'   => true,
        ];
    }
}
