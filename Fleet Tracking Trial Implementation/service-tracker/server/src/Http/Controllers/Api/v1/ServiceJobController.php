<?php

namespace Jalal\ServiceTracker\Http\Controllers\Api\v1;

use Jalal\ServiceTracker\Http\Controllers\Internal\v1\ServiceJobController as Base;

/** Driver-app (plain API) flavour of the console ServiceJobController: same logic, `{data, meta}` envelopes and public ids. */
class ServiceJobController extends Base
{
    protected bool $internal = false;
}
