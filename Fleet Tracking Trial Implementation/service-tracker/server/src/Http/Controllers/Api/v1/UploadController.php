<?php

namespace Jalal\ServiceTracker\Http\Controllers\Api\v1;

use Jalal\ServiceTracker\Http\Controllers\Internal\v1\UploadController as Base;

/** Driver-app (plain API) flavour of the console UploadController: same logic, `{data, meta}` envelopes and public ids. */
class UploadController extends Base
{
    protected bool $internal = false;
}
