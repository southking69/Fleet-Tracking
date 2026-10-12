<?php
// Creates (idempotently) two throw-away companies, one owner user each and one LIVE API credential each, and prints
//   SMOKE_COMPANY_A_ID= / SMOKE_API_KEY_A= / SMOKE_USER_A_EMAIL= / SMOKE_USER_A_PASSWORD=   (and the same for B)
// for ops/smoke/smoke.sh. Modelled on fleetbase/fleetbase scripts/ci/mint-api-key.php.
//
// This file is NOT run directly: ops/smoke/bootstrap.sh drops this first line, base64-encodes the rest and evals it
// inside `php artisan tinker --execute=...` (tinker/PsySH rejects a file that starts with a PHP open tag).
// It uses fully-qualified class names for the same reason. ONLY run it against a throw-away stack.
//
// Never run against production: it creates users with known passwords.
try {
    $out = [];
    foreach (['A', 'B'] as $tag) {
        $name = 'Smoke Co ' . $tag;
        $email = 'smoke-' . strtolower($tag) . '@service-tracker.test';
        $company = \Fleetbase\Models\Company::firstOrCreate(['name' => $name]);

        $user = \Fleetbase\Models\User::where('email', $email)->first();
        $password = null;
        if (!$user) {
            $password = 'Smoke-' . bin2hex(random_bytes(8)) . '!';
            $user = new \Fleetbase\Models\User();
            $user->name = 'Smoke User ' . $tag;
            $user->email = $email;
            $user->company_uuid = $company->uuid;
            $user->type = 'user';
            $user->status = 'active';
            $user->password = $password;
            if (\Illuminate\Support\Facades\Schema::hasColumn($user->getTable(), 'email_verified_at')) {
                $user->email_verified_at = now();
            }
            $user->save();
        } else {
            // known password on every run so the caller can log in
            $password = 'Smoke-' . bin2hex(random_bytes(8)) . '!';
            $user->password = $password;
            $user->save();
        }
        if (empty($company->owner_uuid)) {
            $company->setOwner($user, true);
            $company->save();
        }

        $credential = \Fleetbase\Models\ApiCredential::firstOrCreate(
            ['company_uuid' => $company->uuid, 'name' => 'Service Tracker smoke key'],
            ['user_uuid' => $user->uuid, 'test_mode' => false]
        );

        // lanes / catalog / settings are seeded by the Company::created hook of the extension; make sure for companies that existed before
        try {
            \Jalal\ServiceTracker\Seeders\ServiceTrackerSeeder::seedCompany($company->uuid);
        } catch (\Throwable $e) {
            echo 'SMOKE_WARN_SEED_' . $tag . '=' . str_replace(["\n", "\r"], ' ', $e->getMessage()) . PHP_EOL;
        }

        $out[] = 'SMOKE_COMPANY_' . $tag . '_ID=' . $company->public_id;
        $out[] = 'SMOKE_API_KEY_' . $tag . '=' . $credential->fresh()->key;
        $out[] = 'SMOKE_USER_' . $tag . '_EMAIL=' . $email;
        $out[] = 'SMOKE_USER_' . $tag . '_PASSWORD=' . $password;
    }
    echo PHP_EOL . implode(PHP_EOL, $out) . PHP_EOL;
} catch (\Throwable $e) {
    echo 'SMOKE_BOOTSTRAP_ERROR=' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
