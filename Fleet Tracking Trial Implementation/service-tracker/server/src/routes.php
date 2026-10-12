<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Service Tracker routes
|--------------------------------------------------------------------------
| Console (internal):  /int/v1/service-tracker/...
| Driver / external:   /v1/service-tracker/...
*/
Route::namespace('Jalal\ServiceTracker\Http\Controllers')
    ->group(function ($router) {
        /*
         * Driver / technician API (API credentials or driver tokens)
         */
        $router->prefix('v1/service-tracker')
            ->namespace('Api\v1')
            ->middleware('fleetbase.api')
            ->group(function ($router) {
                $router->get('visits', 'VisitController@query');
                $router->post('visits', 'VisitController@create');
                $router->get('visits/{id}', 'VisitController@find');
                $router->match(['put', 'patch'], 'visits/{id}', 'VisitController@update');
                $router->delete('visits/{id}', 'VisitController@delete');

                $router->post('visits/{id}/stops', 'VendorStopController@create');
                $router->post('visits/{id}/stops/{stop}/check-in', 'VendorStopController@checkIn');
                $router->post('visits/{id}/stops/{stop}/approval-requested', 'VendorStopController@approvalRequested');
                $router->post('visits/{id}/stops/{stop}/approval-received', 'VendorStopController@approvalReceived');
                $router->post('visits/{id}/stops/{stop}/left', 'VendorStopController@left');
                $router->post('visits/{id}/jobs', 'ServiceJobController@create');

                $router->get('vendor-stops', 'VendorStopController@query');
                $router->get('vendor-stops/{id}', 'VendorStopController@find');
                $router->match(['put', 'patch'], 'vendor-stops/{id}', 'VendorStopController@update');

                $router->get('service-jobs', 'ServiceJobController@query');
                $router->post('service-jobs', 'ServiceJobController@create');
                $router->get('service-jobs/{id}', 'ServiceJobController@find');
                $router->match(['put', 'patch'], 'service-jobs/{id}', 'ServiceJobController@update');

                $router->get('damage-records', 'DamageController@query');
                $router->post('damage-records', 'DamageController@create');
                $router->get('damage-records/{id}', 'DamageController@find');
                $router->match(['put', 'patch'], 'damage-records/{id}', 'DamageController@update');

                $router->get('petty-cash', 'PettyCashController@query');
                $router->post('petty-cash', 'PettyCashController@create');
                $router->get('petty-cash/{id}', 'PettyCashController@find');
                $router->match(['put', 'patch'], 'petty-cash/{id}', 'PettyCashController@update');

                $router->get('vehicles/due', 'VehicleController@due');
                $router->get('lookups', 'LookupController@index');
                $router->get('settings', 'LookupController@index');
                $router->post('uploads', 'UploadController@create');
                $router->delete('uploads/{id}', 'UploadController@delete');
            });

        /*
         * Console (internal) API.
         *
         * NOT `fleetbaseRoutes()`: that macro (core-api Expansions/Route.php) always dispatches to the
         * FleetbaseController action names queryRecord/findRecord/createRecord/updateRecord/deleteRecord
         * (core-api Routing/RESTRegistrar.php) and ignores `['controller' => ...]` in $options (the controller
         * is its 4th argument, defaulting to Str::studly(singular($name)).'Controller', i.e. `DamageRecordController`
         * for `damage-records`). Our controllers do not extend FleetbaseController and expose query/find/create/
         * update/delete/bulkDelete, so the same URL shapes are registered here explicitly:
         *   GET /x, GET /x/{id}, POST /x, PUT|PATCH /x/{id}, DELETE /x/{id}, DELETE /x/bulk-delete
         */
        $rest = function ($router, string $name, string $controller, ?\Closure $extra = null) {
            $router->prefix($name)->group(function ($router) use ($controller, $extra) {
                if ($extra) {
                    $extra($router, fn (string $method) => $controller . '@' . $method);
                }
                $router->get('/', $controller . '@query');
                $router->post('/', $controller . '@create');
                $router->delete('bulk-delete', $controller . '@bulkDelete');
                $router->get('{id}', $controller . '@find');
                $router->match(['put', 'patch'], '{id}', $controller . '@update');
                $router->delete('{id}', $controller . '@delete');
            });
        };

        $router->prefix(config('fleetbase.api.routing.internal_prefix', 'int'))
            ->group(function ($router) use ($rest) {
                $router->prefix('v1/service-tracker')
                    ->namespace('Internal\v1')
                    ->middleware('fleetbase.protected')
                    ->group(function ($router) use ($rest) {
                        $rest($router, 'visits', 'VisitController', function ($router, $controller) {
                            $router->get('{id}/stops', $controller('stops'));
                            $router->post('{id}/stops', $controller('createStop'));
                            $router->get('{id}/jobs', $controller('jobs'));
                            $router->post('{id}/jobs', $controller('createJob'));
                            $router->get('{id}/calc', $controller('calc'));
                        });
                        $rest($router, 'vendor-stops', 'VendorStopController');
                        $rest($router, 'service-jobs', 'ServiceJobController');
                        $rest($router, 'catalog', 'CatalogController');
                        $rest($router, 'lanes', 'LaneController');
                        $rest($router, 'damage-records', 'DamageController');
                        $rest($router, 'petty-cash', 'PettyCashController');

                        $router->get('settings', 'SettingsController@show');
                        $router->put('settings', 'SettingsController@update');
                        $router->get('uploads', 'UploadController@query');
                        $router->post('uploads', 'UploadController@create');
                        $router->delete('uploads/{id}', 'UploadController@delete');
                        $router->get('vehicles/due', 'VehicleController@due');
                        $router->get('dashboard', 'DashboardController@index');
                        $router->get('reports/visit/{id}.pdf', 'ReportController@visitPdf');
                        $router->get('exports/{type}.xlsx', 'ReportController@export')->where('type', 'full|period');
                    });
            });
    });
