<?php

namespace Jalal\ServiceTracker\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Validates data/catalog.json without booting Laravel.
 */
class CatalogSeedTest extends TestCase
{
    private const LANES = ['Express', 'Standard', 'Heavy'];
    private const VENDORS = ['Local Mechanic', 'Petromin', 'Tyre Vendor', 'Other Specialist'];
    private const CATEGORIES = ['Routine Maintenance', 'Tyres & Wheels', 'Brakes', 'Engine & Cooling', 'Transmission & Drivetrain', 'Suspension & Steering', 'Electrical & Electronics', 'A/C & Heating', 'Body, Glass & Interior', 'Inspection & Compliance', 'Recovery & Roadside', 'Other'];

    private function rows(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/data/catalog.json');
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($rows);

        return $rows;
    }

    public function testHas145Rows(): void
    {
        $this->assertCount(145, $this->rows());
    }

    public function testEveryRowIsWellFormed(): void
    {
        foreach ($this->rows() as $i => $r) {
            $this->assertNotSame('', trim((string) ($r['service'] ?? '')), "row $i service");
            $this->assertContains($r['lane'] ?? null, self::LANES, "row $i lane");
            $this->assertContains($r['category'] ?? null, self::CATEGORIES, "row $i category");
            $this->assertContains($r['vendor'] ?? null, self::VENDORS, "row $i vendor");
            $this->assertIsInt($r['interval'] ?? null, "row $i interval");
            $this->assertGreaterThanOrEqual(0, $r['interval'], "row $i interval");
        }
    }

    public function testServiceNamesAreUniqueAndAllCategoriesUsed(): void
    {
        $rows = $this->rows();
        $names = array_column($rows, 'service');
        $this->assertCount(count($names), array_unique($names));
        $this->assertEqualsCanonicalizing(self::CATEGORIES, array_values(array_unique(array_column($rows, 'category'))));
    }
}
