<?php

namespace Jalal\ServiceTracker\Seeders;

use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Jalal\ServiceTracker\Models\ServiceCatalogItem;
use Jalal\ServiceTracker\Models\ServiceLane;

/**
 * Seeds lanes, the 145-row service catalog and default settings.
 * Idempotent: rows are matched per company by name; nothing is overwritten once present.
 *
 *   php artisan db:seed --class="Jalal\ServiceTracker\Seeders\ServiceTrackerSeeder"
 */
class ServiceTrackerSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Company::query()->get(['uuid']) as $company) {
            static::seedCompany($company->uuid);
        }
        (new PermissionSeeder())->run();
    }

    public static function catalogPath(): string
    {
        return dirname(__DIR__, 2) . '/data/catalog.json';
    }

    public static function seedCompany(string $companyUuid): void
    {
        $defaults = DefaultSettings::all();

        // lanes
        $laneUuids = [];
        foreach ($defaults['lanes'] as $i => $lane) {
            $row = ServiceLane::withoutGlobalScopes()->firstOrCreate(
                ['company_uuid' => $companyUuid, 'name' => $lane['name']],
                ['target_hours' => $lane['target'], 'sort' => $i + 1]
            );
            $laneUuids[$lane['name']] = $row->uuid;
        }

        // catalog
        $items = json_decode((string) file_get_contents(static::catalogPath()), true);
        if (!is_array($items)) {
            throw new \RuntimeException('Invalid catalog.json');
        }
        $vendors = static::vendorMap($companyUuid);
        foreach ($items as $item) {
            ServiceCatalogItem::withoutGlobalScopes()->firstOrCreate(
                ['company_uuid' => $companyUuid, 'name' => $item['service']],
                [
                    'category'              => $item['category'],
                    'lane_uuid'             => $laneUuids[$item['lane']] ?? null,
                    'suggested_vendor_uuid' => $vendors[Str::lower($item['vendor'])] ?? null,
                    'suggested_vendor_name' => $item['vendor'],
                    'interval_km'           => (int) $item['interval'],
                ]
            );
        }

        // settings (never overwrite an existing configuration)
        $key = 'company.' . $companyUuid . '.' . DefaultSettings::SETTINGS_KEY;
        if (!Setting::where('key', $key)->exists()) {
            Setting::configure($key, $defaults);
        }
    }

    /**
     * FleetOps vendors by lower-cased name for this company. Empty when none exist yet.
     *
     * @return array<string,string>
     */
    protected static function vendorMap(string $companyUuid): array
    {
        if (!Schema::hasTable('vendors')) {
            return [];
        }
        $map = [];
        foreach (DB::table('vendors')->where('company_uuid', $companyUuid)->whereNull('deleted_at')->get(['uuid', 'name']) as $v) {
            $map[Str::lower((string) $v->name)] = $v->uuid;
        }

        return $map;
    }

    /**
     * Link catalog rows to vendors created after seeding (matches suggested_vendor_name).
     */
    public static function relinkVendors(string $companyUuid): int
    {
        $n = 0;
        $vendors = static::vendorMap($companyUuid);
        ServiceCatalogItem::withoutGlobalScopes()->where('company_uuid', $companyUuid)->whereNull('suggested_vendor_uuid')->whereNotNull('suggested_vendor_name')
            ->each(function ($item) use ($vendors, &$n) {
                $uuid = $vendors[Str::lower($item->suggested_vendor_name)] ?? null;
                if ($uuid) {
                    $item->update(['suggested_vendor_uuid' => $uuid]);
                    $n++;
                }
            });

        return $n;
    }
}
