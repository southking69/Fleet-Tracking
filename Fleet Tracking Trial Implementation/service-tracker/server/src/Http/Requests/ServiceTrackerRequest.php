<?php

namespace Jalal\ServiceTracker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for all write requests. Role checks (Supervisor / Technician) are enforced in the
 * controllers; here we only validate shape. `client_uuid` is the offline idempotency key.
 */
abstract class ServiceTrackerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** true on POST (all required rules apply), false on PUT/PATCH (partial update). */
    protected function creating(): bool
    {
        return $this->isMethod('POST');
    }

    /** "required" when creating, "sometimes" when updating. */
    protected function req(): string
    {
        return $this->creating() ? 'required' : 'sometimes';
    }

    /**
     * Ember Data's RESTSerializer posts `{service_visit: {...attrs}}` (Fleetbase's own controllers read
     * `$request->input(<singular>)` too). Accept the wrapped form as well as the flat form the driver app sends.
     */
    protected function prepareForValidation(): void
    {
        foreach (['service_visit', 'vendor_stop', 'service_job', 'damage_record', 'petty_cash_entry', 'service_catalog_item', 'service_lane', 'settings'] as $root) {
            foreach ([$root, \Illuminate\Support\Str::camel($root)] as $key) {
                $inner = $this->input($key);
                if (is_array($inner) && !array_is_list($inner)) {
                    $this->merge($inner);
                    return;
                }
            }
        }
    }

    protected function common(): array
    {
        return ['client_uuid' => ['nullable', 'string', 'max:64']];
    }
}
