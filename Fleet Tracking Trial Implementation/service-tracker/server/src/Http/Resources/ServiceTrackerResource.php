<?php

namespace Jalal\ServiceTracker\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

abstract class ServiceTrackerResource extends JsonResource
{
    /** @var array<string,?array{public_id:?string,plate:?string}> visit uuid => ref (per request) */
    protected static array $visitRefs = [];
    /** @var array<string,?string> user uuid => display name (per request) */
    protected static array $userNames = [];

    public static function flushRefs(): void
    {
        self::$visitRefs = [];
        self::$userNames = [];
    }

    /** Batch-load the visit public ids / plates / user names the given rows refer to (one query each). */
    public static function primeRefs(iterable $models): void
    {
        $visits = [];
        $users = [];
        foreach ($models as $m) {
            $v = $m->service_visit_uuid ?? null;
            if ($v && !array_key_exists($v, self::$visitRefs)) {
                $visits[$v] = true;
            }
            $u = $m->recorded_by_uuid ?? null;
            if ($u && !array_key_exists($u, self::$userNames)) {
                $users[$u] = true;
            }
        }
        if ($visits) {
            foreach (array_keys($visits) as $k) {
                self::$visitRefs[$k] = null;
            }
            foreach (\Jalal\ServiceTracker\Models\ServiceVisit::withTrashed()->whereIn('uuid', array_keys($visits))->get(['uuid', 'public_id', 'plate']) as $v) {
                self::$visitRefs[$v->uuid] = ['public_id' => $v->public_id, 'plate' => $v->plate];
            }
        }
        if ($users) {
            foreach (array_keys($users) as $k) {
                self::$userNames[$k] = null;
            }
            $class = '\\Fleetbase\\Models\\User';
            if (class_exists($class)) {
                try {
                    foreach ($class::whereIn('uuid', array_keys($users))->get(['uuid', 'name']) as $u) {
                        self::$userNames[$u->uuid] = $u->name;
                    }
                } catch (\Throwable $e) {
                    // names are display-only
                }
            }
        }
    }

    /** Public id + plate of the visit a child row belongs to. */
    protected function visitRef(): array
    {
        $uuid = $this->resource->service_visit_uuid ?? null;
        if (!$uuid) {
            return ['public_id' => null, 'plate' => null];
        }
        if (!array_key_exists($uuid, self::$visitRefs)) {
            self::primeRefs([$this->resource]);
        }
        return self::$visitRefs[$uuid] ?? ['public_id' => null, 'plate' => null];
    }

    protected function userName(?string $uuid): ?string
    {
        if (!$uuid) {
            return null;
        }
        if (!array_key_exists($uuid, self::$userNames)) {
            self::primeRefs([$this->resource]);
        }
        return self::$userNames[$uuid] ?? null;
    }

    /** Console (Ember) requests are served from /int/v1, the driver app from /v1. */
    protected function internal($request = null): bool
    {
        $request = $request ?: request();
        return str_contains(ltrim($request->path(), '/'), 'int/v1');
    }

    /** `id` is the uuid for the console, the public id for the API (Fleetbase convention). */
    protected function ident($request): array
    {
        return [
            'id' => $this->internal($request) ? $this->uuid : $this->public_id,
            'uuid' => $this->when($this->internal($request), $this->uuid),
            'public_id' => $this->public_id,
        ];
    }

    protected function iso($value): ?string
    {
        return $value ? \Carbon\Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    /** @return array<int,array> */
    public static function photo($file): array
    {
        return [
            'uuid' => $file->uuid,
            'public_id' => $file->public_id ?? null,
            'url' => $file->url ?? null,
            'original_filename' => $file->original_filename ?? null,
            'content_type' => $file->content_type ?? null,
            'file_size' => $file->file_size ?? null,
            'type' => $file->type ?? null,
            'caption' => $file->caption ?? null,
            'created_at' => $file->created_at ? \Carbon\Carbon::parse($file->created_at)->utc()->format('Y-m-d\TH:i:s\Z') : null,
        ];
    }

    /** Photos of a loaded `files` relation, optionally only those of one type. */
    protected function photos(?string $type = null): array
    {
        if (!$this->relationLoaded('files')) {
            return [];
        }
        return $this->files->filter(fn ($f) => $type === null || $f->type === $type)->map(fn ($f) => self::photo($f))->values()->all();
    }

    protected function ref($relation, string $nameAttr = 'name'): ?array
    {
        $m = $this->resource->relationLoaded($relation) ? $this->resource->{$relation} : null;
        return $m ? ['uuid' => $m->uuid, 'public_id' => $m->public_id ?? null, 'name' => $m->{$nameAttr} ?? null] : null;
    }
}
