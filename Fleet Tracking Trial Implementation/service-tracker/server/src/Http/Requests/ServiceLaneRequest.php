<?php

namespace Jalal\ServiceTracker\Http\Requests;

class ServiceLaneRequest extends ServiceTrackerRequest
{
    public function rules(): array
    {
        return $this->common() + [
            'name' => [$this->req(), 'string', 'max:80'],
            'target_hours' => [$this->req(), 'numeric', 'min:0', 'max:9999'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
