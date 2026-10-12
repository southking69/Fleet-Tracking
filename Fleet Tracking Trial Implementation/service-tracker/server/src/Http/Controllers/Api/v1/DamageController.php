<?php

namespace Jalal\ServiceTracker\Http\Controllers\Api\v1;

use Jalal\ServiceTracker\Http\Controllers\Internal\v1\DamageController as Base;

/** Driver-app (plain API) flavour of the console DamageController: same logic, `{data, meta}` envelopes and public ids. */
class DamageController extends Base
{
    protected bool $internal = false;
}
