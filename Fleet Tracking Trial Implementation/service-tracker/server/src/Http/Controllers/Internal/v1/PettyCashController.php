<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jalal\ServiceTracker\Http\Requests\PettyCashRequest;
use Jalal\ServiceTracker\Http\Resources\PettyCashEntryResource;
use Jalal\ServiceTracker\Http\Controllers\CrudController;
use Jalal\ServiceTracker\Models\PettyCashEntry;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Services\VisitMapper;

class PettyCashController extends CrudController
{
    protected string $model = PettyCashEntry::class;
    protected string $resource = PettyCashEntryResource::class;
    protected string $requestClass = PettyCashRequest::class;
    protected string $plural = 'petty_cash_entries';
    protected string $singular = 'petty_cash_entry';
    protected array $with = ['files'];
    protected array $searchColumns = ['plate', 'category', 'description', 'paid_by'];
    protected array $filterColumns = ['vehicle_uuid' => 'vehicle_uuid', 'category' => 'category', 'paid_by' => 'paid_by'];
    protected array $sortable = ['created_at', 'entry_date', 'amount', 'category'];

    protected function scope($query, Request $request)
    {
        if ($request->filled('plate')) {
            $this->wherePlateLike($query, 'plate', $request->input('plate'));
        }
        if ($request->filled('service_visit_uuid')) {
            $visit = $this->findModel(ServiceVisit::class, (string) $request->input('service_visit_uuid'));
            $this->authorizeVisit($visit);
            $query->where('service_visit_uuid', $visit->uuid);
        }
        if ($request->filled('from')) {
            $query->whereDate('entry_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('entry_date', '<=', $request->input('to'));
        }
        if ($this->isTechnician()) {
            // no creator column on petty_cash_entries: a technician sees entries on their own visits plus unlinked ones
            $query->where(fn ($w) => $w->whereNull('service_visit_uuid')
                ->orWhereIn('service_visit_uuid', ServiceVisit::where('technician_uuid', optional($this->user())->uuid)->select('uuid')));
        }
        return $query;
    }

    protected function prepare(array $data, ?Model $existing, Request $request): array
    {
        $data = parent::prepare($data, $existing, $request);
        if (isset($data['amount_major'])) {
            $data['amount'] = $data['amount'] ?? (int) round(((float) $data['amount_major']) * 100);
        }
        unset($data['amount_major']);
        if (!$existing || array_key_exists('amount', $data)) {
            $data['amount'] = (int) ($data['amount'] ?? 0); // minor units; a blank amount is stored as 0
        }
        if (isset($data['plate'])) {
            $data['plate'] = VisitMapper::cleanPlate($data['plate']);
        }
        if (!empty($data['service_visit_uuid'])) {
            $visit = $this->findModel(ServiceVisit::class, (string) $data['service_visit_uuid']);
            $this->authorizeVisit($visit);
            $data['service_visit_uuid'] = $visit->uuid;
            $data['plate'] = $data['plate'] ?? $visit->plate;
            $data['vehicle_uuid'] = $data['vehicle_uuid'] ?? $visit->vehicle_uuid;
        }
        if (!$existing) {
            $data['currency'] = strtoupper($data['currency'] ?? VisitMapper::settings()['currency'] ?? 'SAR');
        }
        return $data;
    }

    protected function canDelete(Model $model): void
    {
        $this->requireSupervisor();
    }

    /**
     * Adds period totals so the console can show a footer without a second call:
     * `meta.summary` = {total (minor units), count, cars, by_category{name: minor units}} over the whole filtered set
     * (not just the page), plus `total_amount` / `total_amount_major`.
     */
    public function query(Request $request): JsonResponse
    {
        $response = parent::query($request);
        if (!$this->isListResponse($response)) {
            return $response;
        }
        $base = PettyCashEntry::where('company_uuid', $this->companyUuid());
        $base = $this->scope($base, $request);
        foreach ($this->filterColumns as $param => $col) {
            $param = is_int($param) ? $col : $param;
            if ($request->filled($param)) {
                $base->where($col, $request->input($param));
            }
        }
        $rows = $base->get(['plate', 'category', 'amount']);
        $total = (int) $rows->sum('amount');
        $byCategory = [];
        foreach ($rows as $r) {
            $k = $r->category ?: '(no category)';
            $byCategory[$k] = ($byCategory[$k] ?? 0) + (int) $r->amount;
        }
        arsort($byCategory);
        $payload = $response->getData(true);
        $payload['meta']['total_amount'] = $total;
        $payload['meta']['total_amount_major'] = round($total / 100, 2);
        $payload['meta']['summary'] = [
            'total' => $total,
            'count' => $rows->count(),
            'cars' => $rows->map(fn ($r) => VisitMapper::plateKey($r->plate))->filter()->unique()->count(),
            'by_category' => (object) $byCategory,
        ];
        return response()->json($payload);
    }

    protected function isListResponse(JsonResponse $response): bool
    {
        return isset($response->getData(true)['meta']);
    }
}
