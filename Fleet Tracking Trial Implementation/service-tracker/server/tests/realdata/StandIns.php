<?php

declare(strict_types=1);

namespace {
    // Minimal stand-ins so the REAL Reports\VehicleIndex / Reports\TrackerStats / Services\VisitMapper can run without Laravel.
    if (!function_exists('collect')) {
        function collect(iterable $items = []): \Jalal\ServiceTracker\Tests\RealData\MiniCollection
        {
            return new \Jalal\ServiceTracker\Tests\RealData\MiniCollection(is_array($items) ? $items : iterator_to_array($items));
        }
    }
}

namespace Carbon {
    if (!class_exists(Carbon::class, false)) {
        class Carbon
        {
            public function __construct(private \DateTimeImmutable $d)
            {
            }

            public static function parse(string $s, ?string $tz = null): self
            {
                return new self(new \DateTimeImmutable($s, new \DateTimeZone($tz ?: 'UTC')));
            }

            public function toDateString(): string
            {
                return $this->d->format('Y-m-d');
            }
        }
    }
}

namespace Jalal\ServiceTracker\Tests\RealData {
    use Jalal\ServiceTracker\Services\AppsScriptMapper;

    final class MiniCollection implements \IteratorAggregate, \Countable
    {
        public function __construct(private array $items)
        {
        }

        public function sortBy(callable $f): self
        {
            $keyed = [];
            $i = 0;
            foreach ($this->items as $it) {
                $keyed[] = [$f($it), $i++, $it];
            }
            usort($keyed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            return new self(array_map(fn ($k) => $k[2], $keyed));
        }

        public function values(): self
        {
            return new self(array_values($this->items));
        }

        public function contains(string $key, mixed $value): bool
        {
            foreach ($this->items as $it) {
                if ((is_array($it) ? ($it[$key] ?? null) : ($it->$key ?? null)) === $value) {
                    return true;
                }
            }

            return false;
        }

        public function where(string $key, mixed $value): self
        {
            return new self(array_values(array_filter($this->items, fn ($it) => (is_array($it) ? ($it[$key] ?? null) : ($it->$key ?? null)) === $value)));
        }

        public function count(): int
        {
            return count($this->items);
        }

        public function getIterator(): \Traversable
        {
            return new \ArrayIterator($this->items);
        }
    }

    /** Attribute bag with the bits of Eloquent the report code touches. */
    class StubModel
    {
        /** @var array<string,mixed> */
        public array $a = [];
        /** @var array<string,bool> */
        public array $loaded = [];

        public function __construct(array $attrs = [])
        {
            $this->a = $attrs;
        }

        public function __get(string $k): mixed
        {
            return $this->a[$k] ?? null;
        }

        public function __isset(string $k): bool
        {
            return isset($this->a[$k]);
        }

        public function relationLoaded(string $r): bool
        {
            return $this->loaded[$r] ?? false;
        }
    }

    final class StubVisit extends StubModel
    {
    }

    final class StubStop extends StubModel
    {
    }

    final class StubJob extends StubModel
    {
    }

    final class StubDamage extends StubModel
    {
    }

    final class StubPetty extends StubModel
    {
    }

    final class StandIns
    {
        public static function install(): void
        {
            foreach (['ServiceVisit' => StubVisit::class, 'VendorStop' => StubStop::class, 'ServiceJob' => StubJob::class] as $model => $stub) {
                $fq = 'Jalal\\ServiceTracker\\Models\\' . $model;
                if (!class_exists($fq, false)) {
                    class_alias($stub, $fq);
                }
            }
        }

        /**
         * Mapped rows -> model-like objects, as the console would load them after an import (relations loaded,
         * created_at ascending, photo File rows present for every photo id).
         *
         * @return array{0:list<StubVisit>,1:list<StubPetty>}
         */
        public static function models(array $m): array
        {
            $db = AppsScriptMapper::dbRows($m);
            $files = [];
            foreach ($m['photos'] as $p) {
                foreach ($p['ids'] as $pid) {
                    $files[$p['subject'] . ':' . $p['id']][] = new StubModel(['type' => $p['type'], 'original_filename' => $pid . '.jpg']);
                }
            }
            $stops = [];
            foreach ($db['stops'] as $s) {
                $stops[$s['visit_legacy']][] = new StubStop($s);
            }
            $jobs = [];
            foreach ($db['jobs'] as $j) {
                $jobs[$j['visit_legacy']][] = new StubJob($j);
            }
            $dmg = [];
            foreach ($m['damages'] as $d) {
                $dmg[$d['visit_legacy']][] = new StubDamage($d);
            }
            $visits = [];
            foreach ($db['visits'] as $id => $v) {
                $o = new StubVisit($v + ['created_at' => (int) ($v['created_ms'] ?? 0), 'vehicle_uuid' => null]);
                $o->a['stops'] = new MiniCollection($stops[$id] ?? []);
                $o->a['jobs'] = new MiniCollection($jobs[$id] ?? []);
                $o->a['damages'] = new MiniCollection($dmg[$id] ?? []);
                $o->a['files'] = new MiniCollection($files['visits:' . $id] ?? []);
                $o->loaded = ['stops' => true, 'jobs' => true, 'damages' => true, 'files' => true];
                $visits[] = $o;
            }
            $petty = array_map(fn ($e) => new StubPetty($e), array_values($m['petty']));

            return [$visits, $petty];
        }
    }
}
