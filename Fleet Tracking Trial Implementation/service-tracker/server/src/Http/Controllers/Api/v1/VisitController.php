<?php

namespace Jalal\ServiceTracker\Http\Controllers\Api\v1;

use Jalal\ServiceTracker\Http\Controllers\Internal\v1\VisitController as Base;

/** Driver-app (plain API) flavour of the console VisitController: same logic, `{data, meta}` envelopes and public ids. */
class VisitController extends Base
{
    protected bool $internal = false;
}
