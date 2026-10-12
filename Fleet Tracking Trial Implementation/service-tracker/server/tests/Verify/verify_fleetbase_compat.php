<?php
/**
 * Static compatibility verifier: parses the REAL Fleetbase sources (no stubs) and checks what the extension
 * writes / registers against them. No Laravel, no database.
 *
 *   php tests/Verify/verify_fleetbase_compat.php [refDir=/home/claude/ref]
 *
 * refDir must contain `fleetops` (fleetbase/fleetops v0.6.71) and `core-api` (fleetbase/core-api v1.6.x) checkouts.
 * Exit code 1 on any failure.
 */
$ref  = rtrim($argv[1] ?? '/home/claude/ref', '/');
$root = dirname(__DIR__, 2);
$fo   = "$ref/fleetops";
$core = "$ref/core-api";
$fail = 0;
$n    = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail, $n;
    $n++;
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' && !$ok ? "  -> $detail" : '') . "\n";
}
function stripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
function read(string $f): string
{
    return is_file($f) ? file_get_contents($f) : '';
}

/* ---------- final column set of a table, from real migrations (create + alter + dropColumn) ---------- */
function columnsOf(string $table, array $dirs): array
{
    $files = [];
    foreach ($dirs as $d) {
        foreach (glob("$d/*.php") as $f) {
            $files[basename($f)] = $f;
        }
    }
    ksort($files);
    $cols = [];
    $types = ['increments', 'id', 'uuid', 'string', 'char', 'text', 'mediumText', 'longText', 'integer', 'unsignedInteger', 'bigInteger', 'unsignedBigInteger', 'unsignedTinyInteger', 'boolean', 'decimal', 'double', 'float', 'date', 'dateTime', 'timestamp', 'json', 'point', 'foreignUuid', 'enum', 'tinyInteger'];
    foreach ($files as $f) {
        $src = file_get_contents($f);
        $dpos = strpos($src, 'function down(');
        if ($dpos !== false) {
            $src = substr($src, 0, $dpos);
        }
        // split on Schema::create / Schema::table blocks
        if (!preg_match_all("/Schema::(create|table)\('" . preg_quote($table, '/') . "',\s*function\s*\(Blueprint\s*\\\$(\w+)\)[^{]*\{(.*?)\n\s{8}\}\);/s", $src, $blocks, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($blocks as $b) {
            $body = $b[3];
            foreach ($types as $t) {
                if (preg_match_all('/\$' . $b[2] . '->' . $t . "\('(\w+)'/", $body, $mm)) {
                    foreach ($mm[1] as $c) {
                        $cols[$c] = true;
                    }
                }
            }
            if (strpos($body, '->timestamps(') !== false) {
                $cols['created_at'] = $cols['updated_at'] = true;
            }
            if (strpos($body, '->softDeletes(') !== false) {
                $cols['deleted_at'] = true;
            }
            if (preg_match_all('/dropColumn\((\[[^\]]*\]|\'\w+\')\)/', $body, $dm)) {
                foreach ($dm[1] as $list) {
                    preg_match_all("/'(\w+)'/", $list, $names);
                    foreach ($names[1] as $c) {
                        unset($cols[$c]);
                    }
                }
            }
        }
    }
    return array_keys($cols);
}

/* ---------- resolve `Controller@method` through the class chain ---------- */
function classIndex(string $srcRoot, string $ns): array
{
    $idx = [];
    $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $src = file_get_contents($f);
        if (!preg_match('/^namespace\s+([^;]+);/m', $src, $m) || !preg_match('/^(?:abstract\s+)?class\s+(\w+)(?:\s+extends\s+(\w+))?/m', $src, $c)) {
            continue;
        }
        preg_match_all('/^\s*(?:public\s+)?function\s+(\w+)/m', $src, $fm);
        $alias = [];
        if (preg_match_all('/^use\s+([^;]+?)\s+as\s+(\w+);/m', $src, $am, PREG_SET_ORDER)) {
            foreach ($am as $a) {
                $alias[$a[2]] = $a[1];
            }
        }
        $pub = [];
        foreach ($fm[0] as $i => $line) {
            if (!preg_match('/\b(protected|private)\b/', $line)) {
                $pub[] = $fm[1][$i];
            }
        }
        $idx[$m[1] . '\\' . $c[1]] = ['alias' => $alias, 'parent' => $c[2] ?? null, 'ns' => $m[1], 'methods' => $pub, 'src' => $src];
    }
    return $idx;
}
function hasMethod(array $idx, string $fqcn, string $method): bool
{
    while ($fqcn && isset($idx[$fqcn])) {
        if (in_array($method, $idx[$fqcn]['methods'], true)) {
            return true;
        }
        $p = $idx[$fqcn]['parent'];
        if (!$p) {
            return false;
        }
        // same-namespace or imported parent
        if (isset($idx[$fqcn]['alias'][$p])) {
            $fqcn = $idx[$fqcn]['alias'][$p];
        } elseif (preg_match('/^use\s+([^;]*\\\\' . preg_quote($p, '/') . ');/m', $idx[$fqcn]['src'], $u)) {
            $fqcn = $u[1];
        } else {
            $fqcn = $idx[$fqcn]['ns'] . '\\' . $p;
        }
    }
    return false;
}

echo "== 1. Core API contract (core-api/src)\n";
$expansion = read("$core/src/Expansions/Route.php");
$registrar = read("$core/src/Routing/RESTRegistrar.php");
check('fleetbaseRoutes signature is ($name, $registerFn, $options, $controller)', (bool) preg_match('/function \(string \$name, callable\|array\|null \$registerFn = null, \$options = \[\], \$controller = null\)/', $expansion));
check('REST registrar dispatches to *Record action names (not query/find/create)', strpos($registrar, "'queryRecord'") !== false && strpos($registrar, "'findRecord'") !== false && strpos($registrar, "'createRecord'") !== false);
$routes = stripComments(read("$root/src/routes.php"));
check('routes.php does not rely on fleetbaseRoutes() (our controllers lack *Record actions)', strpos($routes, 'fleetbaseRoutes(') === false);
$provider = read("$core/src/Providers/CoreServiceProvider.php");
check('CoreServiceProvider::registerObservers loops $this->observers (model => observer)', (bool) preg_match('/function registerObservers\(\): void\s*\{\s*foreach \(\$this->observers as \$model => \$observer\)/', $provider));
check("middleware groups 'fleetbase.api' and 'fleetbase.protected' exist", strpos($provider, "'fleetbase.protected' =>") !== false && strpos($provider, "'fleetbase.api' =>") !== false);
check("config key fleetbase.api.routing.internal_prefix exists", (bool) preg_match("/'internal_prefix'\s*=>\s*env\('INTERNAL_API_PREFIX', 'int'\)/", read("$core/config/fleetbase.php")));
$setting = read("$core/src/Models/Setting.php");
foreach (['configureCompany', 'lookupCompany', 'lookupForCompany', 'configure', 'lookup'] as $m) {
    check("Setting::$m exists", (bool) preg_match('/public static function ' . $m . '\(/', $setting));
}
check("Setting company key format 'company.{uuid}.{key}'", strpos($setting, "'company.' . session('company') . '.' . \$key") !== false);
$hpi = read("$core/src/Traits/HasPublicId.php");
check('HasPublicId builds "<publicIdType>_<10 random>" from $model->publicIdType', strpos($hpi, '$type . \'_\' . $hashid') !== false && strpos($hpi, 'generatePublicId($model->publicIdType)') !== false);
check('HasApiModelBehavior declares `public static $publicIdColumn` (instance redeclaration is a PHP fatal)', strpos(read("$core/src/Traits/HasApiModelBehavior.php"), 'public static $publicIdColumn') !== false);
$fileM = read("$core/src/Models/File.php");
preg_match("/class File.*?\\\$fillable = \[([^\]]*)\]/s", $fileM, $ff);
foreach (['company_uuid', 'uploader_uuid', 'subject_uuid', 'subject_type', 'disk', 'path', 'folder', 'original_filename', 'type', 'content_type', 'file_size', 'caption'] as $c) {
    check("File fillable has $c", strpos($ff[1] ?? '', "'$c'") !== false);
}
check('File::getUrlAttribute exists (appended `url`)', strpos($fileM, 'function getUrlAttribute') !== false);
$fileCols = columnsOf('files', ["$core/migrations"]);
foreach (['subject_uuid', 'subject_type', 'type', 'disk', 'path', 'folder', 'caption', 'file_size', 'content_type', 'original_filename', 'uploader_uuid', 'company_uuid'] as $c) {
    check("files table column $c (core migrations)", in_array($c, $fileCols, true));
}
check('Auth schema discovery: Utils::getAuthSchemaNamespaces looks in `<package>/server/src` or `<package>//src` + Auth/Schemas, needs `fleetbase-extension` keyword', strpos(read("$core/src/Support/Utils.php"), "findComposerPackagesWithKeyword('fleetbase-extension')") !== false && strpos(read("$core/src/Support/Utils.php"), "'Auth/Schemas'") !== false);

echo "== 2. Routes -> controller methods\n";
$idx = classIndex("$root/src/Http/Controllers", 'Jalal\\ServiceTracker\\Http\\Controllers');
preg_match_all("/'(\w+)Controller@(\w+)'|\\\$controller\('(\w+)'\)|'(\w+Controller)'/", $routes, $rm);
// walk the routes file group by group (Api\v1 vs Internal\v1)
$split = strpos($routes, '$rest = function');
$sections = ['Api\\v1' => substr($routes, 0, $split), 'Internal\\v1' => substr($routes, $split)];
foreach ($sections as $sub => $text) {
    preg_match_all("/'(\w+Controller)@(\w+)'/", $text, $am, PREG_SET_ORDER);
    foreach ($am as $a) {
        $fq = "Jalal\\ServiceTracker\\Http\\Controllers\\$sub\\$a[1]";
        check("route $sub\\$a[1]@$a[2]", isset($idx[$fq]) && hasMethod($idx, $fq, $a[2]), isset($idx[$fq]) ? 'method missing' : 'class missing');
    }
}
// internal `$rest($router, 'x', 'XController', closure with $controller('m'))`
preg_match_all("/\\\$rest\(\\\$router, '([\w-]+)', '(\w+Controller)'/", $routes, $rr, PREG_SET_ORDER);
foreach ($rr as $r) {
    $fq = "Jalal\\ServiceTracker\\Http\\Controllers\\Internal\\v1\\$r[2]";
    foreach (['query', 'create', 'bulkDelete', 'find', 'update', 'delete'] as $m) {
        check("rest($r[1]) -> $r[2]@$m", isset($idx[$fq]) && hasMethod($idx, $fq, $m));
    }
}
preg_match_all("/\\\$controller\('(\w+)'\)/", $routes, $cm);
foreach (array_unique($cm[1]) as $m) {
    check("visits extra route VisitController@$m", hasMethod($idx, 'Jalal\\ServiceTracker\\Http\\Controllers\\Internal\\v1\\VisitController', $m));
}

echo "== 3. Migrations\n";
$ourMig = glob("$root/migrations/*.php");
check('migrations live in server/migrations (path core scans for fleetbase-extension packages: <pkg>//migrations)', count($ourMig) === 7 && !is_dir("$root/src/migrations"));
$maxReal = '';
foreach (array_merge(glob("$fo/server/migrations/*.php"), glob("$core/migrations/*.php")) as $f) {
    $maxReal = max($maxReal, substr(basename($f), 0, 17));
}
$minOurs = min(array_map(fn ($f) => substr(basename($f), 0, 17), $ourMig));
check("our first migration ($minOurs) sorts after the newest FleetOps/core migration ($maxReal)", $minOurs > $maxReal);
$realTables = [];
foreach (array_merge(glob("$fo/server/migrations/*.php"), glob("$core/migrations/*.php")) as $f) {
    preg_match_all("/Schema::create\('(\w+)'/", read($f), $tm);
    foreach ($tm[1] as $t) {
        $realTables[$t] = true;
    }
}
foreach ($ourMig as $f) {
    $src = read($f);
    preg_match("/Schema::create\('(\w+)'/", $src, $t);
    check("table {$t[1]} does not collide with an existing FleetOps/core table", !isset($realTables[$t[1]]));
    check("{$t[1]} has uuid + public_id + company_uuid + softDeletes (Fleetbase convention)", strpos($src, "'uuid'") !== false && strpos($src, "'public_id'") !== false && strpos($src, "'company_uuid'") !== false && strpos($src, 'softDeletes') !== false);
    // index name length <= 64
    $long = [];
    foreach (explode("\n", $src) as $line) {
        if (preg_match("/->(?:unique|index)\(\[([^\]]+)\],\s*'(\w+)'/", $line, $mm) && strlen($mm[2]) > 64) {
            $long[] = $mm[2];
        } elseif (preg_match("/->(?:unique|index)\(\[([^\]]+)\]\)/", $line, $mm)) {
            $cs = array_map(fn ($x) => trim($x, " '"), explode(',', $mm[1]));
            $nm = strtolower($t[1] . '_' . implode('_', $cs) . (strpos($line, 'unique') !== false ? '_unique' : '_index'));
            if (strlen($nm) > 64) {
                $long[] = $nm;
            }
        } elseif (preg_match("/\\\$table->\w+\('(\w+)'[^;]*->(unique|index)\(\)/", $line, $mm)) {
            $nm = strtolower($t[1] . '_' . $mm[1] . '_' . $mm[2]);
            if (strlen($nm) > 64) {
                $long[] = $nm;
            }
        }
    }
    check("{$t[1]} index identifiers <= 64 chars", !$long, implode(',', $long));
    check("{$t[1]} has no foreign keys into FleetOps tables (soft references only)", strpos($src, 'foreign') === false && strpos($src, 'constrained') === false);
}

echo "== 4. Models\n";
foreach (glob("$root/src/Models/*.php") as $f) {
    $src = read($f);
    $cls = basename($f, '.php');
    if (!preg_match('/protected \$table = \'(\w+)\'/', $src, $tm)) {
        continue;
    }
    check("$cls: no instance \$publicIdColumn (conflicts with trait static)", strpos($src, '$publicIdColumn') === false);
    check("$cls: publicIdType set", (bool) preg_match('/protected \$publicIdType = \'\w+\'/', $src));
    $mig = glob("$root/migrations/*_create_{$tm[1]}_table.php");
    $cols = columnsOf($tm[1], [$root . '/migrations']);
    preg_match('/\$fillable = \[(.*?)\];/s', $src, $fm);
    preg_match_all("/'(\w+)'/", $fm[1] ?? '', $fcols);
    $missing = array_diff($fcols[1], $cols);
    check("$cls fillable columns all exist in {$tm[1]} migration", !$missing, implode(',', $missing));
}
foreach (['Fleetbase\\FleetOps\\Models\\Vehicle', 'Fleetbase\\FleetOps\\Models\\Vendor', 'Fleetbase\\FleetOps\\Models\\WorkOrder', 'Fleetbase\\FleetOps\\Models\\Issue', 'Fleetbase\\FleetOps\\Models\\Maintenance', 'Fleetbase\\FleetOps\\Models\\MaintenanceSchedule'] as $c) {
    check("FleetOps class exists: $c", is_file("$fo/server/src/Models/" . substr($c, strrpos($c, '\\') + 1) . '.php'));
}
check('Vehicle.plate_number / Vendor.name are real columns', in_array('plate_number', columnsOf('vehicles', ["$fo/server/migrations"]), true) && in_array('name', columnsOf('vendors', ["$fo/server/migrations"]), true));

echo "== 5. Close-hook writes vs FleetOps schema\n";
$obs = stripComments(read("$root/src/Observers/ServiceVisitObserver.php"));
// extract every `VisitMapper::filterColumns($table, [ ... ]);` block -> keys
preg_match_all('/filterColumns\(\$table,\s*\[(.*?)\n\s*\]\)/s', $obs, $blocks);
$targets = ['maintenances' => [], 'maintenance_schedules' => [], 'issues' => []];
// order in file: maintenance attrs, schedule $values, schedule create (second arg of +), issue attrs
$assign = [0 => 'maintenances', 1 => 'maintenance_schedules', 2 => 'maintenance_schedules', 3 => 'issues'];
foreach ($blocks[1] as $i => $body) {
    // top-level keys only (indent <= 20 spaces); nested `meta` arrays are deeper
    preg_match_all("/^ {12,20}'(\w+)'\s*=>/m", $body, $km);
    $targets[$assign[$i]] = array_merge($targets[$assign[$i]], $km[1]);
}
check('found 4 filterColumns blocks in observer', count($blocks[1]) === 4, (string) count($blocks[1]));
foreach ($targets as $table => $keys) {
    $cols = columnsOf($table, ["$fo/server/migrations"]);
    check("$table: schema parsed (" . count($cols) . ' columns)', count($cols) > 10);
    $bad = array_diff(array_unique($keys), $cols);
    check("$table: every key the observer writes is a real column", !$bad, implode(',', $bad));
    $modelFile = ['maintenances' => 'Maintenance', 'maintenance_schedules' => 'MaintenanceSchedule', 'issues' => 'Issue'][$table];
    preg_match('/\$fillable = \[(.*?)\];/s', read("$fo/server/src/Models/$modelFile.php"), $fm);
    preg_match_all("/'(\w+)'/", $fm[1] ?? '', $fl);
    $nf = array_diff(array_unique($keys), $fl[1]);
    check("$table: observer keys are fillable on $modelFile (forceFill used anyway)", !$nf, implode(',', $nf));
}
check('issues.location is NOT NULL spatial with no default -> observer supplies it', strpos(read("$fo/server/migrations/2023_04_27_053456_create_issues_table.php"), "\$table->point('location');") !== false && strpos($obs, "'location' => self::issueLocation(") !== false);
check('Issue FleetOps own code also supplies a Point (InspectionSubmission::failureLocation)', strpos(read("$fo/server/src/Models/InspectionSubmission.php"), 'function failureLocation(): Point') !== false);
check('maintainable_type/subject_type written as Vehicle FQCN (what Vehicle::maintenances() queries: static::class)', strpos(read("$fo/server/src/Models/Vehicle.php"), "->where('maintainable_type', static::class)") !== false && strpos($obs, 'const VEHICLE = \'Fleetbase\\\\FleetOps\\\\Models\\\\Vehicle\'') !== false && strpos($obs, 'getMorphClass') === false);
check('registerMorphMap aliases Vehicle to legacy `Fleetbase\\Models\\Vehicle` first (getMorphClass() would be wrong)', (bool) preg_match("/morphMap\(\[\s*'Fleetbase\\\\\\\\Models\\\\\\\\Vehicle'\s*=>/", read("$fo/server/src/Providers/FleetOpsServiceProvider.php")));
// enum literals (taken from the FleetOps UI option lists and FleetOps' own observers)
$mform = read("$fo/addon/components/maintenance/form.js");
preg_match("/statusOptions = \[([^\]]*)\]/", $mform, $so);
preg_match("/maintenanceTypeOptions = \[([^\]]*)\]/", $mform, $to);
preg_match("/priorityOptions = \[([^\]]*)\]/", $mform, $po);
$vals = fn ($s) => array_map(fn ($x) => trim($x, " '"), explode(',', $s));
check("Maintenance status 'completed' valid (UI + WorkOrderObserver)", in_array('completed', $vals($so[1]), true) && (bool) preg_match("/'status'\s*=>\s*'completed'/", read("$fo/server/src/Observers/WorkOrderObserver.php")) && strpos($obs, "'status' => 'completed'") !== false);
check("Maintenance type 'preventive'/'corrective' valid", in_array('preventive', $vals($to[1]), true) && in_array('corrective', $vals($to[1]), true) && strpos($obs, "'preventive' : 'corrective'") !== false);
check("Maintenance priority 'medium' valid", in_array('medium', $vals($po[1]), true) && strpos($obs, "'priority' => 'medium'") !== false);
$sform = read("$fo/addon/components/maintenance-schedule/form.js");
check("schedule interval_method 'distance' valid; km goes to interval_distance (interval_value/unit are DATE intervals)", strpos($sform, "value: 'distance'") !== false && strpos($obs, "'interval_method' => 'distance'") !== false && strpos($obs, "'interval_unit' => 'km'") === false && strpos(read("$fo/server/src/Models/MaintenanceSchedule.php"), "add(\$this->interval_value . ' ' . \$unit)") !== false);
check("schedule type 'preventive' (MaintenanceSchedule::createFromImport default)", strpos(read("$fo/server/src/Models/MaintenanceSchedule.php"), "['type', 'schedule_type'], 'preventive'") !== false);
check("Issue priority low|medium|high|critical (InspectionSubmission::highestFailureSeverity); status 'pending'", strpos(read("$fo/server/src/Models/InspectionSubmission.php"), "['critical', 'high', 'medium', 'low']") !== false && strpos($obs, "'status' => 'pending'") !== false);
check('odometer columns are UNSIGNED BIGINT -> observer casts to non-negative int', strpos(read("$fo/server/migrations/2025_08_28_054931_create_maintenances_table.php"), "unsignedBigInteger('odometer')") !== false && strpos($obs, 'max(0, (int) round((float) $visit->odometer))') !== false);
check('maintenances.work_order_uuid / issues.vehicle_uuid are real FKs (visit.work_order_uuid / vehicle_uuid must exist)', strpos(read("$fo/server/migrations/2025_08_28_054931_create_maintenances_table.php"), "foreignUuid('work_order_uuid')") !== false && strpos(read("$fo/server/migrations/2023_04_27_053459_add_foreign_keys_to_issues_table.php"), "['vehicle_uuid'])->references(['uuid'])->on('vehicles')") !== false);

echo "== 6. Permissions / composer\n";
$schema = read("$root/src/Auth/Schemas/ServiceTracker.php");
preg_match_all("/\['name' => '([\w-]+)'/", $schema, $res);
$resources = $res[1];
check('schema resources parsed', count($resources) === 11);
$existing = [];
foreach (glob("$fo/server/src/Auth/Schemas/*.php") as $f) {
    preg_match_all("/'name'\s*=>\s*'([\w-]+)'/", read($f), $x);
    $existing = array_merge($existing, $x[1]);
}
foreach (glob("$core/src/Auth/Schemas/*.php") as $f) {
    preg_match_all("/'name'\s*=>\s*'([\w-]+)'/", read($f), $x);
    $existing = array_merge($existing, $x[1]);
}
$coll = array_intersect($resources, $existing);
check('no resource name collides with an existing FleetOps/core permission resource (isResourceGuarded is global)', !$coll, implode(',', $coll));
preg_match_all("/'((?:\*|see|list|view|create|update|delete|export)) ((?!extension)[\w-]+)'/", $schema, $pm, PREG_SET_ORDER);
$bad = [];
foreach ($pm as $p) {
    if (!in_array($p[2], $resources, true)) {
        $bad[] = $p[0];
    }
}
check('every policy permission references a declared resource', !$bad, implode(',', $bad));
$cj = json_decode(read("$root/composer.json"), true);
check('composer keyword fleetbase-extension (required for schema/migration discovery)', in_array('fleetbase-extension', $cj['keywords'] ?? [], true));
$cc = json_decode(read("$core/composer.json"), true);
$fc = json_decode(read("$fo/composer.json"), true);
check('require fleetbase/core-api ^1.6 satisfied by core-api ' . $cc['version'], $cj['require']['fleetbase/core-api'] === '^1.6' && version_compare($cc['version'], '1.6.0', '>='));
check('require fleetbase/fleetops-api pinned to ' . $fc['version'] . ' (package name from its composer.json)', $cj['require']['fleetbase/fleetops-api'] === $fc['version'] && $fc['name'] === 'fleetbase/fleetops-api');
check('fleetops requires core-api ' . $fc['require']['fleetbase/core-api'] . ' (compatible with ^1.6)', $fc['require']['fleetbase/core-api'] === '>=1.6.65');
check('spatie/laravel-activitylog ^4.7 identical to core-api', ($cc['require']['spatie/laravel-activitylog'] ?? '') === $cj['require']['spatie/laravel-activitylog']);
check('maatwebsite/excel ^3.1 identical to core-api', ($cc['require']['maatwebsite/excel'] ?? '') === $cj['require']['maatwebsite/excel']);
check('barryvdh/laravel-dompdf range overlaps fleetops (' . $fc['require']['barryvdh/laravel-dompdf'] . ')', $cj['require']['barryvdh/laravel-dompdf'] === '^3.0' && $fc['require']['barryvdh/laravel-dompdf'] === '^3.1');
check('PHP constraint ^8.1 matches core-api', $cc['require']['php'] === $cj['require']['php']);

echo "\n$n checks, $fail failed\n";
exit($fail ? 1 : 0);
