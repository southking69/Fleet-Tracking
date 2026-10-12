<?php

namespace Jalal\ServiceTracker\Http\Controllers\Api\v1;

use Jalal\ServiceTracker\Http\Controllers\Internal\v1\VehicleController as Base;

/** Driver-app (plain API) flavour of the console VehicleController: same logic, `{data, meta}` envelopes and public ids. */
class VehicleController extends Base
{
    protected bool $internal = false;
}
