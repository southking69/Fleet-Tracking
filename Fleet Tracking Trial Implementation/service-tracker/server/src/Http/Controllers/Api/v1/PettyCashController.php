<?php

namespace Jalal\ServiceTracker\Http\Controllers\Api\v1;

use Jalal\ServiceTracker\Http\Controllers\Internal\v1\PettyCashController as Base;

/** Driver-app (plain API) flavour of the console PettyCashController: same logic, `{data, meta}` envelopes and public ids. */
class PettyCashController extends Base
{
    protected bool $internal = false;
}
