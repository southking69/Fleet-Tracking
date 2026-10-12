<?php

namespace Jalal\ServiceTracker\Http\Controllers\Api\v1;

use Jalal\ServiceTracker\Http\Controllers\Internal\v1\VendorStopController as Base;

/** Driver-app (plain API) flavour of the console VendorStopController: same logic, `{data, meta}` envelopes and public ids. */
class VendorStopController extends Base
{
    protected bool $internal = false;
}
