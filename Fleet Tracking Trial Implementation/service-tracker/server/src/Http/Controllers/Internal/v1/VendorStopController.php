<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Jalal\ServiceTracker\Http\Controllers\ChildCrudController;
use Jalal\ServiceTracker\Http\Requests\VendorStopRequest;
use Jalal\ServiceTracker\Http\Resources\VendorStopResource;
use Jalal\ServiceTracker\Models\VendorStop;
use Jalal\ServiceTracker\Services\VisitMapper;

class VendorStopController extends ChildCrudController
{
    protected string $model = VendorStop::class;
    protected string $resource = VendorStopResource::class;
    protected string $requestClass = VendorStopRequest::class;
    protected string $plural = 'vendor_stops';
    protected string $singular = 'vendor_stop';
        protected array $searchColumns = ['vendor_name', 'notes'];
    protected array $sortable = ['created_at', 'arrived_at', 'left_at'];
    protected array $dateTimeFields = ['arrived_at', 'approval_requested_at', 'approval_received_at', 'left_at'];

    /** Event name -> column. */
    protected const EVENTS = [
        'check-in' => 'arrived_at',
        'approval-requested' => 'approval_requested_at',
        'approval-received' => 'approval_received_at',
        'left' => 'left_at',
    ];

    protected function prepare(array $data, ?Model $existing, Request $request): array
    {
        $data = parent::prepare($data, $existing, $request);
        if (!empty($data['vendor_uuid']) && empty($data['vendor_name'])) {
            $data['vendor_name'] = $this->vendorName($data['vendor_uuid']);
        }
        return $data;
    }

    protected function saving(Model $model, bool $creating, Request $request): void
    {
        $this->assertSequence($model);
    }

    /** Timestamps on a stop must not run backwards. */
    protected function assertSequence(VendorStop $s): void
    {
        $order = ['arrived_at', 'approval_requested_at', 'approval_received_at', 'left_at'];
        $prev = null;
        foreach ($order as $col) {
            $t = $s->{$col} ? Carbon::parse($s->{$col}) : null;
            if ($t && $prev && $t->lt($prev[1])) {
                abort(422, "{$col} cannot be earlier than {$prev[0]}.");
            }
            if ($t) {
                $prev = [$col, $t];
            }
        }
    }

    public function checkIn(Request $request, string $id, string $stop): JsonResponse
    {
        return $this->event($request, $id, $stop, 'check-in');
    }

    public function approvalRequested(Request $request, string $id, string $stop): JsonResponse
    {
        return $this->event($request, $id, $stop, 'approval-requested');
    }

    public function approvalReceived(Request $request, string $id, string $stop): JsonResponse
    {
        return $this->event($request, $id, $stop, 'approval-received');
    }

    public function left(Request $request, string $id, string $stop): JsonResponse
    {
        return $this->event($request, $id, $stop, 'left');
    }

    /**
     * POST visits/{id}/stops/{stop}/{check-in|approval-requested|approval-received|left}
     * Body: at (ISO, optional, default now), client_uuid (optional). Idempotent: if the timestamp is
     * already set the existing value is kept (an offline retry never moves it) unless `overwrite=1`
     * is sent by a supervisor.
     */
    protected function event(Request $request, string $visitId, string $stop, string $event): JsonResponse
    {
        abort_unless(isset(self::EVENTS[$event]), 404);
        $col = self::EVENTS[$event];
        $this->nested($visitId);
        $v = $this->routeVisit($request);
        $this->authorizeVisit($v);
        $request->validate(['at' => 'nullable|date', 'client_uuid' => 'nullable|string|max:64', 'overwrite' => 'nullable|boolean']);

        $model = $this->findModel(VendorStop::class, $stop);
        abort_unless($model->service_visit_uuid === $v->uuid, 404, 'Stop does not belong to this visit.');

        $at = $request->filled('at') ? VisitMapper::parseDt($request->input('at')) : Carbon::now('UTC');
        $overwrite = $request->boolean('overwrite') && $this->isSupervisor();

        DB::transaction(function () use ($model, $col, $at, $overwrite) {
            $model->refresh();
            if ($model->{$col} && !$overwrite) {
                return;
            }
            $model->{$col} = $at;
            $this->assertSequence($model);
            $model->save();
        });

        return $this->respondOne($this->present($model->fresh()));
    }
}
