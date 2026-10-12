<?php

namespace Jalal\ServiceTracker\Providers;

use Fleetbase\Models\Company;
use Fleetbase\Providers\CoreServiceProvider;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Observers\ServiceVisitObserver;
use Jalal\ServiceTracker\Seeders\ServiceTrackerSeeder;

if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('ServiceTracker cannot be loaded without `fleetbase/core-api` installed!');
}

class ServiceTrackerServiceProvider extends CoreServiceProvider
{
    /**
     * Model observers. CoreServiceProvider::registerObservers() (core-api 1.6.68) loops over `$this->observers`
     * as `$model => $observer` and calls `$model::observe($observer)` when the model class exists. Core's own
     * observers are registered by CoreServiceProvider itself (its own instance), so overriding the property here
     * is the same pattern FleetOpsServiceProvider uses.
     *
     * @var array
     */
    public $observers = [
        ServiceVisit::class => ServiceVisitObserver::class,
    ];

    public function register()
    {
        $this->app->register(CoreServiceProvider::class);
    }

    public function boot()
    {
        $this->registerObservers();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'service-tracker');
        $this->commands([\Jalal\ServiceTracker\Console\Commands\ImportAppsScriptData::class]);
        $this->registerSeedHooks();
    }

    /**
     * Seed lanes/catalog/settings for every newly created company, so a fresh install needs no manual step.
     * Existing companies are seeded with ServiceTrackerSeeder (see README "Install").
     */
    protected function registerSeedHooks(): void
    {
        if (!class_exists(Company::class)) {
            return;
        }
        Company::created(function (Company $company) {
            try {
                ServiceTrackerSeeder::seedCompany($company->uuid);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
