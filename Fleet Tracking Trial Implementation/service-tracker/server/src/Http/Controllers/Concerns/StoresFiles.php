<?php

namespace Jalal\ServiceTracker\Http\Controllers\Concerns;

use Fleetbase\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Jalal\ServiceTracker\Services\VisitMapper;

/**
 * Stores uploaded images as core Fleetbase File rows attached to a subject model.
 * Images are stored exactly as received (the client already resizes to ~1000px JPEG q0.72).
 */
trait StoresFiles
{
    protected function storeUpload(UploadedFile $upload, Model $subject, string $type, ?string $caption = null, ?string $idempotencyKey = null): File
    {
        $company = $this->companyUuid();
        $cacheKey = $idempotencyKey ? 'service-tracker:upload:' . $company . ':' . sha1($idempotencyKey) : null;
        if ($cacheKey && ($existing = Cache::get($cacheKey)) && ($file = File::where('uuid', $existing)->first())) {
            return $file;
        }

        $disk = config('filesystems.default', 'local');
        $ext = strtolower($upload->guessExtension() ?: $upload->getClientOriginalExtension() ?: 'jpg');
        $folder = 'uploads/' . $company . '/service-tracker/' . Str::after($type, ':') . '/' . date('Y/m');
        $path = trim($folder, '/') . '/' . Str::uuid() . '.' . $ext;
        $stored = Storage::disk($disk)->putFileAs(dirname($path), $upload, basename($path));

        $attrs = VisitMapper::filterColumns('files', [
            'company_uuid' => $company,
            'uploader_uuid' => optional($this->user())->uuid,
            'disk' => $disk,
            'path' => $stored ?: $path,
            'folder' => dirname($path),
            'original_filename' => $upload->getClientOriginalName(),
            'content_type' => $upload->getMimeType() ?: 'image/jpeg',
            'file_size' => $upload->getSize(),
            'type' => $type,
            'caption' => $caption,
            'subject_uuid' => $subject->uuid,
            'subject_type' => $subject->getMorphClass(),
        ]);

        $file = new File();
        $file->forceFill($attrs)->save();

        if ($cacheKey) {
            Cache::put($cacheKey, $file->uuid, now()->addDays(30));
        }
        return $file;
    }

    /** Delete the stored object and the File row. */
    protected function destroyFile(File $file): void
    {
        try {
            if ($file->path) {
                Storage::disk($file->disk ?: config('filesystems.default', 'local'))->delete($file->path);
            }
        } catch (\Throwable $e) {
            // object already gone: the row still goes
        }
        $file->delete();
    }
}
