<?php

namespace App\Support;

use App\Models\BoxingBooking;
use App\Models\MemberMembership;
use App\Models\RfidAttendance;
use App\Models\TrainerBooking;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;

class DashboardReportExporter
{
    public function buildReportData(): array
    {
        [$monthStart, $monthEnd] = $this->currentMonthDateRange();

        return [
            'Users' => User::query()
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->latest()
                ->get(),
            'Subscriptions' => MemberMembership::with(['member', 'plan'])
                ->whereBetween('start_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->latest('start_date')
                ->get(),
            'Trainer Bookings' => TrainerBooking::with(['member', 'trainer'])
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->latest('created_at')
                ->get(),
            'Boxing Bookings' => BoxingBooking::with(['member', 'trainer', 'boxingPackage'])
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->latest('created_at')
                ->get(),
            // Preserve the existing export section name for API compatibility.
            'Attendance Scans' => RfidAttendance::with('user')
                ->where(function ($query) use ($monthStart, $monthEnd) {
                    $query->whereBetween('check_in_time', [$monthStart, $monthEnd])
                        ->orWhereBetween('check_out_time', [$monthStart, $monthEnd]);
                })
                ->latest('attendance_date')
                ->get(),
            'Monthly Income Summary' => $this->buildMonthlyIncomeSummary(),
        ];
    }

    public function buildExcelDocument(array $reportData): string
    {
        $worksheets = collect($reportData)->map(function (Collection $rows, string $title) {
            $normalizedRows = $this->normalizeRows($rows);

            return $this->renderWorksheet($title, $normalizedRows);
        })->implode("\n");

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
    xmlns:o="urn:schemas-microsoft-com:office:office"
    xmlns:x="urn:schemas-microsoft-com:office:excel"
    xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
    xmlns:html="http://www.w3.org/TR/REC-html40">
    {$worksheets}
</Workbook>
XML;
    }

    public function buildJsonDocument(array $reportData): string
    {
        $normalizedData = collect($reportData)->map(function (Collection $rows) {
            return $rows->map(function ($row) {
                $data = is_array($row) ? $row : $row->toArray();

                return $this->normalizeJsonValue($data);
            })->all();
        })->all();

        return json_encode(
            $normalizedData,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    private function buildMonthlyIncomeSummary(int $months = 6): Collection
    {
        $startMonth = now()->startOfMonth()->subMonths($months - 1);

        $membershipRows = MemberMembership::query()
            ->selectRaw("DATE_FORMAT(start_date, '%Y-%m') as month_key")
            ->selectRaw('SUM(final_price) as total')
            ->whereDate('start_date', '>=', $startMonth->toDateString())
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $trainerRows = TrainerBooking::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key")
            ->selectRaw('SUM(final_price) as total')
            ->whereDate('created_at', '>=', $startMonth->toDateString())
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $boxingRows = BoxingBooking::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key")
            ->selectRaw('SUM(final_price) as total')
            ->whereDate('created_at', '>=', $startMonth->toDateString())
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        return collect(range(0, $months - 1))->map(function (int $monthOffset) use ($startMonth, $membershipRows, $trainerRows, $boxingRows) {
            $month = $startMonth->copy()->addMonths($monthOffset);
            $monthKey = $month->format('Y-m');
            $subscriptionTotal = (float) ($membershipRows[$monthKey] ?? 0);
            $trainerTotal = (float) ($trainerRows[$monthKey] ?? 0);
            $boxingTotal = (float) ($boxingRows[$monthKey] ?? 0);

            return [
                'month' => $month->format('M Y'),
                'subscriptions_total' => number_format($subscriptionTotal, 2, '.', ''),
                'trainer_bookings_total' => number_format($trainerTotal, 2, '.', ''),
                'boxing_bookings_total' => number_format($boxingTotal, 2, '.', ''),
                'overall_total' => number_format($subscriptionTotal + $trainerTotal + $boxingTotal, 2, '.', ''),
            ];
        });
    }

    private function currentMonthDateRange(): array
    {
        $start = now()->startOfMonth()->startOfDay();
        $end = now()->endOfMonth()->endOfDay();

        return [$start, $end];
    }

    private function normalizeRows(Collection $rows): array
    {
        return $rows->map(function ($row) {
            $data = is_array($row) ? $row : $row->toArray();

            return collect($data)->map(function ($value) {
                return $this->stringifyValue($value);
            })->all();
        })->all();
    }

    private function stringifyValue($value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) ($value ?? '');
    }

    private function normalizeJsonValue($value)
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn ($item) => $this->normalizeJsonValue($item))
                ->all();
        }

        if (is_bool($value)) {
            return $value;
        }

        return $value ?? '';
    }

    private function renderWorksheet(string $title, array $rows): string
    {
        $worksheetName = $this->sanitizeWorksheetName($title);

        if (count($rows) === 0) {
            $rowsXml = $this->renderWorksheetRow(['No data available.']);
        } else {
            $headers = array_keys($rows[0]);
            $rowsXml = $this->renderWorksheetRow($headers);

            foreach ($rows as $row) {
                $rowValues = array_map(
                    fn (string $header) => $row[$header] ?? '',
                    $headers
                );
                $rowsXml .= $this->renderWorksheetRow($rowValues);
            }
        }

        return <<<XML
        <Worksheet ss:Name="{$worksheetName}">
            <Table>
                {$rowsXml}
            </Table>
        </Worksheet>
        XML;
    }

    private function renderWorksheetRow(array $values): string
    {
        $cells = collect($values)->map(function ($value) {
            $escapedValue = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');

            return <<<XML
<Cell><Data ss:Type="String">{$escapedValue}</Data></Cell>
XML;
        })->implode('');

        return "<Row>{$cells}</Row>";
    }

    private function sanitizeWorksheetName(string $title): string
    {
        $cleaned = preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $title);
        $cleaned = trim((string) $cleaned);

        return mb_substr($cleaned === '' ? 'Sheet' : $cleaned, 0, 31);
    }
}
