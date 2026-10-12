<?php

namespace Jalal\ServiceTracker\Http\Controllers\Internal\v1;

use Fleetbase\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jalal\ServiceTracker\Http\Controllers\Concerns\StoresFiles;
use Jalal\ServiceTracker\Http\Controllers\ServiceTrackerController;
use Jalal\ServiceTracker\Http\Requests\UploadRequest;
use Jalal\ServiceTracker\Http\Resources\FileResource;
use Jalal\ServiceTracker\Http\Resources\ServiceTrackerResource;
use Jalal\ServiceTracker\Models\DamageRecord;
use Jalal\ServiceTracker\Models\PettyCashEntry;
use Jalal\ServiceTracker\Models\ServiceVisit;
use Jalal\ServiceTracker\Models\VendorStop;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * POST uploads (multipart: subject + subject_uuid (or the aliases documented on UploadRequest), photos[] | photo, client_uuid).
 * Idempotent: each file is keyed by `client_uuid` + its index, so an offline retry returns the
 * files already stored instead of duplicating them.
 */
class UploadController extends ServiceTrackerController
{
    use StoresFiles;

    protected const SUBJECTS = [
        'visit_pickup' => [ServiceVisit::class, VisitMapper::PHOTO_PICKUP],
        'visit_return' => [ServiceVisit::class, VisitMapper::PHOTO_RETURN],
        'damage' => [DamageRecord::class, VisitMapper::PHOTO_DAMAGE],
        'petty_cash' => [PettyCashEntry::class, VisitMapper::PHOTO_RECEIPT],
        'stop' => [VendorStop::class, VisitMapper::PHOTO_STOP],
    ];

    public function query(Request $request): JsonResponse
    {
        $subjectKey = $request->input('subject') ?: UploadRequest::resolveSubject((string) $request->input('subject_type'), (string) $request->input('stage'), (string) $request->input('collection'));
        $subjectUuid = $request->input('subject_uuid') ?: $request->input('subject_id');
        $request->merge(['subject' => $subjectKey, 'subject_uuid' => $subjectUuid]);
        $request->validate(['subject' => 'required|in:' . implode(',', array_keys(self::SUBJECTS)), 'subject_uuid' => 'required|string']);
        [$class, $type] = self::SUBJECTS[$subjectKey];
        $subject = $this->findModel($class, $subjectUuid);
        $this->authorizeSubject($subject);
        $files = File::where('subject_uuid', $subject->uuid)->where('type', $type)->orderBy('created_at')->get();
        return $this->respondList($files, FileResource::class, 'files');
    }

    public function create(Request $request): JsonResponse
    {
        $data = app(UploadRequest::class)->validated();
        [$class, $type] = self::SUBJECTS[$data['subject']];
        if (!empty($data['subject_uuid'])) {
            $subject = $this->findModel($class, $data['subject_uuid']);
        } else {
            // parent created offline in the same session: resolve it by the client_uuid it was created with
            $subject = $this->byClientUuid($class, $data['parent_client_uuid'] ?? null);
            abort_unless($subject, 422, 'The record this photo belongs to has not been received yet.');
        }
        $this->authorizeSubject($subject);

        $uploads = $request->file('photos') ?: array_filter([$request->file('photo')]);
        $key = $data['client_uuid'] ?? null;
        $out = [];
        foreach (array_values($uploads) as $i => $upload) {
            $file = $this->storeUpload($upload, $subject, $type, $data['caption'] ?? null, $key ? $key . '#' . $i . '#' . $subject->uuid : null);
            $out[] = ServiceTrackerResource::photo($file);
        }
        return response()->json([$this->internal ? 'files' : 'data' => $out, 'subject' => $data['subject'], 'subject_uuid' => $subject->uuid], 201);
    }

    public function delete(Request $request, string $id): JsonResponse
    {
        $file = File::where('company_uuid', $this->companyUuid())->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))->first();
        abort_unless($file && str_starts_with((string) $file->type, 'service-tracker:'), 404);
        abort_unless($this->isSupervisor() || $file->uploader_uuid === optional($this->user())->uuid, 403, 'Only a supervisor or the uploader can remove a photo.');
        $this->destroyFile($file);
        return response()->json(['status' => 'OK', 'deleted' => true]);
    }

    /** A technician may attach files only to records on their own visits. */
    protected function authorizeSubject($subject): void
    {
        if ($this->isSupervisor()) {
            return;
        }
        $visit = $subject instanceof ServiceVisit ? $subject : ($subject->service_visit_uuid ?? null ? ServiceVisit::where('uuid', $subject->service_visit_uuid)->first() : null);
        if ($visit) {
            $this->authorizeVisit($visit);
        }
    }
}
