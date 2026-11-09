<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RfidAttendanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly RfidAttendanceService $rfidAttendanceService,
    ) {
    }

    public function index()
    {
        $totalMembers = User::query()
            ->where('role', 'user')
            ->count();

        return view('pages.attendance', [
            'totalMembers' => $totalMembers,
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $records = $this->rfidAttendanceService
            ->recordsQuery($validated)
            ->get();

        $workingDays = $records
            ->groupBy('user_id')
            ->map(function ($userRecords) {
                $user = $userRecords->first()?->user;

                if (!$user || $user->role !== 'trainer') {
                    return '-';
                }

                return $userRecords
                    ->filter(fn ($record) => $record->check_in_time && $record->check_out_time)
                    ->count();
            });

        $payload = $records->flatMap(function ($record) use ($workingDays) {
            $base = [
                'username' => $record->user?->name ?? 'Unknown',
                'role' => $record->user?->role ?? 'user',
                'total_check_in_days' => $workingDays[$record->user_id] ?? '-',
                'attendance_date' => $record->attendance_date?->toDateString(),
                'source' => $record->source,
            ];

            $events = [];

            if ($record->check_in_time) {
                $events[] = $base + [
                    'action' => 'check_in',
                    'timestamp' => $record->check_in_time->toIso8601String(),
                ];
            }

            if ($record->check_out_time) {
                $events[] = $base + [
                    'action' => 'check_out',
                    'timestamp' => $record->check_out_time->toIso8601String(),
                ];
            }

            return $events;
        })->sortByDesc('timestamp')->values();

        return response()->json([
            'records' => $payload,
        ]);
    }

    public function checkedIn(): JsonResponse
    {
        $today = Carbon::today();

        $activeUsers = $this->rfidAttendanceService
            ->activeUsers($today)
            ->map(function ($attendance) {
                return [
                    'username' => $attendance->user?->name ?? 'Unknown',
                    'role' => $attendance->user?->role ?? 'user',
                    'last_scan' => $attendance->check_in_time?->toIso8601String(),
                    'status' => 'Active',
                    'source' => $attendance->source,
                ];
            })
            ->values();

        $totalMembers = User::query()
            ->where('role', 'user')
            ->count();

        return response()->json([
            'total_members' => $totalMembers,
            'active_count' => $activeUsers->count(),
            'active_users' => $activeUsers,
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        return $this->deprecatedResponse('Manual attendance has been retired. Use RFID card scans for all attendance writes.');
    }

    public function scanFromQr(Request $request)
    {
        $message = 'QR attendance has been retired. RFID is the only supported attendance method.';

        if ($request->wantsJson()) {
            return $this->deprecatedResponse($message);
        }

        return response()->view('pages.attendance-scan', [
            'status' => 'error',
            'message' => $message,
            'action' => null,
            'timestamp' => null,
        ], 410);
    }

    public function refreshQr(): JsonResponse
    {
        return $this->deprecatedResponse('QR attendance has been retired. RFID is the only supported attendance method.');
    }

    private function deprecatedResponse(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], 410);
    }
}
