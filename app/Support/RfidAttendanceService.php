<?php

namespace App\Support;

use App\Models\RfidAttendance;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RfidAttendanceService
{
    public function recordsQuery(array $filters = [])
    {
        $query = RfidAttendance::query()
            ->with('user')
            ->orderByDesc('attendance_date')
            ->orderByDesc('check_in_time');

        if (!empty($filters['username'])) {
            $query->whereHas('user', function ($builder) use ($filters) {
                $builder->where('name', 'like', '%' . $filters['username'] . '%');
            });
        }

        if (!empty($filters['start_date'])) {
            $query->whereDate('attendance_date', '>=', Carbon::parse($filters['start_date'])->toDateString());
        }

        if (!empty($filters['end_date'])) {
            $query->whereDate('attendance_date', '<=', Carbon::parse($filters['end_date'])->toDateString());
        }

        return $query;
    }

    public function activeUsers(CarbonInterface $day): Collection
    {
        return RfidAttendance::query()
            ->with('user')
            ->whereDate('attendance_date', $day->toDateString())
            ->whereNotNull('check_in_time')
            ->whereNull('check_out_time')
            ->orderByDesc('check_in_time')
            ->get();
    }

    public function countByActionForDay(CarbonInterface $day, string $action): int
    {
        return match ($action) {
            'check_in' => RfidAttendance::query()
                ->whereDate('attendance_date', $day->toDateString())
                ->whereNotNull('check_in_time')
                ->count(),
            'check_out' => RfidAttendance::query()
                ->whereDate('attendance_date', $day->toDateString())
                ->whereNotNull('check_out_time')
                ->count(),
            default => 0,
        };
    }

    public function countByActionForRange(CarbonInterface $start, CarbonInterface $end, string $action): int
    {
        return match ($action) {
            'check_in' => RfidAttendance::query()
                ->whereBetween('check_in_time', [$start, $end])
                ->count(),
            'check_out' => RfidAttendance::query()
                ->whereNotNull('check_out_time')
                ->whereBetween('check_out_time', [$start, $end])
                ->count(),
            default => 0,
        };
    }

    public function latestUserStatus(User $user): ?array
    {
        $attendance = RfidAttendance::query()
            ->where('user_id', $user->id)
            ->orderByDesc('attendance_date')
            ->orderByDesc('check_in_time')
            ->first();

        if (!$attendance) {
            return null;
        }

        $latestTimestamp = $attendance->check_out_time ?? $attendance->check_in_time;
        $latestAction = $attendance->check_out_time ? 'check_out' : 'check_in';

        return [
            'action' => $latestAction,
            'timestamp' => $latestTimestamp?->toIso8601String(),
            'attendance_date' => $attendance->attendance_date?->toDateString(),
            'source' => $attendance->source,
        ];
    }

    public function recentUserActivity(User $user, int $limit = 10): Collection
    {
        return RfidAttendance::query()
            ->where('user_id', $user->id)
            ->orderByDesc('attendance_date')
            ->orderByDesc('check_in_time')
            ->take($limit)
            ->get()
            ->flatMap(fn (RfidAttendance $attendance) => $this->expandAttendanceEvents($attendance))
            ->sortByDesc('timestamp')
            ->take($limit)
            ->values();
    }

    public function expandAttendanceEvents(RfidAttendance $attendance): Collection
    {
        $events = collect();

        if ($attendance->check_in_time) {
            $events->push([
                'action' => 'check_in',
                'timestamp' => $attendance->check_in_time->toIso8601String(),
                'attendance_date' => $attendance->attendance_date?->toDateString(),
                'source' => $attendance->source,
            ]);
        }

        if ($attendance->check_out_time) {
            $events->push([
                'action' => 'check_out',
                'timestamp' => $attendance->check_out_time->toIso8601String(),
                'attendance_date' => $attendance->attendance_date?->toDateString(),
                'source' => $attendance->source,
            ]);
        }

        return $events;
    }
}
