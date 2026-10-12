<?php

namespace Jalal\ServiceTracker\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST uploads (multipart). Canonical fields: `subject` + `subject_uuid`.
 * The clients send equivalent aliases, normalised here before validation:
 *
 *  - Navigator (driver app): `subject_type` = visit|damage|petty_cash, `subject_id` (public id / uuid of the parent),
 *    `stage` = pickup|return (visit photos), `angle`, `parent_client_uuid` (parent created offline), `client_uuid`, `photos[0]`.
 *  - Ember console: `subject_type` = service_visit|damage_record|petty_cash_entry, `subject_uuid`,
 *    `collection` = pickup_photos|return_photos|photos|receipts, `photos[]`.
 */
class UploadRequest extends ServiceTrackerRequest
{
    public const SUBJECTS = ['visit_pickup', 'visit_return', 'damage', 'petty_cash', 'stop'];

    protected function prepareForValidation(): void
    {
        if (!$this->filled('subject')) {
            $subject = self::resolveSubject(
                (string) $this->input('subject_type'),
                (string) $this->input('stage'),
                (string) $this->input('collection')
            );
            if ($subject) {
                $this->merge(['subject' => $subject]);
            }
        }
        if (!$this->filled('subject_uuid') && $this->filled('subject_id')) {
            $this->merge(['subject_uuid' => (string) $this->input('subject_id')]);
        }
        if (!$this->filled('caption') && $this->filled('angle')) {
            $this->merge(['caption' => (string) $this->input('angle')]);
        }
    }

    /** Map the clients' (subject_type, stage, collection) triple onto one of self::SUBJECTS. */
    public static function resolveSubject(string $type, string $stage = '', string $collection = ''): ?string
    {
        $norm = fn (string $v) => str_replace('-', '_', strtolower(trim($v)));
        $type = $norm($type);
        $stage = $norm($stage);
        $collection = $norm($collection);
        if (in_array($type, self::SUBJECTS, true)) {
            return $type;
        }
        switch ($type) {
            case 'visit':
            case 'service_visit':
                if ($stage === 'return' || $collection === 'return_photos') {
                    return 'visit_return';
                }
                if ($stage === 'pickup' || $collection === 'pickup_photos') {
                    return 'visit_pickup';
                }
                return null;
            case 'damage':
            case 'damage_record':
                return 'damage';
            case 'petty_cash':
            case 'petty_cash_entry':
                return 'petty_cash';
            case 'stop':
            case 'vendor_stop':
                return 'stop';
        }
        return null;
    }

    public function rules(): array
    {
        return $this->common() + [
            'subject' => ['required', Rule::in(self::SUBJECTS)],
            'subject_uuid' => ['required_without:parent_client_uuid', 'nullable', 'string', 'max:64'],
            'parent_client_uuid' => ['nullable', 'string', 'max:64'],
            'photos' => ['required_without:photo', 'array', 'max:12'],
            'photos.*' => ['file', 'image', 'max:8192'],
            'photo' => ['required_without:photos', 'file', 'image', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }
}
