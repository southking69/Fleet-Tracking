<?php

namespace Jalal\ServiceTracker\Exports;

use Carbon\CarbonInterface;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One worksheet from a column spec like the tracker's `{h, w, t, tm}`:
 *   h  header text        w  width (chars)
 *   t  dt | date | int | h1 | money   (cell type / number format)
 *   tm true when the column is a timing column (dropped when timing is hidden)
 * Rows are plain lists aligned with the columns; dt/date cells accept Carbon, DateTime or ISO strings,
 * rendered as real Excel dates in the company timezone.
 */
class ArraySheet implements FromArray, WithTitle, WithHeadings, WithColumnWidths, WithColumnFormatting, WithStyles
{
    public const FORMATS = [
        'dt' => 'dd-mmm-yy hh:mm',
        'date' => 'dd-mmm-yy',
        'int' => '#,##0',
        'h1' => '0.00',
        'money' => '#,##0.00',
    ];

    protected string $title;
    protected array $cols;
    protected array $rows;
    protected string $tz;
    protected bool $header;

    public function __construct(string $title, array $cols, array $rows, string $tz = 'UTC', bool $header = true)
    {
        $this->title = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title), 0, 31);
        $this->cols = array_values($cols);
        $this->rows = array_values($rows);
        $this->tz = $tz;
        $this->header = $header;
    }

    /** Drop timing columns (tracker "hideTiming"). */
    public function withoutTiming(): self
    {
        $keep = [];
        foreach ($this->cols as $i => $c) {
            if (empty($c['tm'])) {
                $keep[] = $i;
            }
        }
        $cols = array_map(fn ($i) => $this->cols[$i], $keep);
        $rows = array_map(fn ($r) => array_map(fn ($i) => $r[$i] ?? null, $keep), $this->rows);
        return new self($this->title, $cols, $rows, $this->tz, $this->header);
    }

    public function colspec(): array
    {
        return $this->cols;
    }

    public function rowsRaw(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function headings(): array
    {
        return array_map(fn ($c) => $c['h'] ?? '', $this->cols);
    }

    public function array(): array
    {
        return array_map(function ($row) {
            $out = [];
            foreach ($this->cols as $i => $c) {
                $out[] = $this->cell($row[$i] ?? null, $c['t'] ?? null);
            }
            return $out;
        }, $this->rows);
    }

    protected function cell($v, ?string $type)
    {
        if ($v === null || $v === '') {
            return $v === '' ? '' : null;
        }
        if ($type === 'dt' || $type === 'date') {
            try {
                $c = $v instanceof CarbonInterface ? \Carbon\Carbon::instance($v) : \Carbon\Carbon::parse($v, 'UTC');
                $wall = $type === 'date' && !($v instanceof CarbonInterface) && is_string($v) && strlen($v) <= 10 ? $c : $c->copy()->setTimezone($this->tz);
                $serial = Date::dateTimeToExcel(new \DateTime($wall->format('Y-m-d H:i:s')));
                return $type === 'date' ? floor($serial) : $serial;
            } catch (\Throwable $e) {
                return (string) $v;
            }
        }
        if (in_array($type, ['int', 'h1', 'money'], true) && is_numeric($v)) {
            return $type === 'int' ? (int) round((float) $v) : (float) $v;
        }
        return $v;
    }

    public function columnWidths(): array
    {
        $w = [];
        foreach ($this->cols as $i => $c) {
            $w[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1)] = (float) ($c['w'] ?? 14);
        }
        return $w;
    }

    public function columnFormats(): array
    {
        $f = [];
        foreach ($this->cols as $i => $c) {
            if (!empty($c['t']) && isset(self::FORMATS[$c['t']])) {
                $f[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1)] = self::FORMATS[$c['t']];
            }
        }
        return $f;
    }

    public function styles(Worksheet $sheet)
    {
        if ($this->header) {
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            $sheet->getStyle('1:1')->getAlignment()->setWrapText(true);
            $sheet->freezePane('A2');
        }
        return [];
    }
}
