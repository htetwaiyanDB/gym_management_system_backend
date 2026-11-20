<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Point;
use App\Models\RfidAttendance;
use App\Models\User;
use App\Support\ActivePlanReminderService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class RfidAttendanceController extends Controller
{
    public function __construct(
        private readonly ActivePlanReminderService $activePlanReminderService,
    ) {}

    public function registerCard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => [
                'required',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['user', 'trainer'])),
            ],
            'card_id' => ['required', 'string'],
        ]);

        $user = User::query()->findOrFail($validated['user_id']);

        $existingOwner = User::query()
            ->where('card_id', $validated['card_id'])
            ->where('id', '!=', $user->id)
            ->exists();

        if ($existingOwner) {
            return response()->json([
                'message' => 'This card is already assigned to another user.',
            ], 422);
        }

        if ($user->card_id && $user->card_id !== $validated['card_id']) {
            return response()->json([
                'message' => 'Selected user already has a card assigned.',
            ], 422);
        }

        if ($user->card_id !== $validated['card_id']) {
            $user->forceFill([
                'card_id' => $validated['card_id'],
            ])->save();
        }

        return response()->json([
            'message' => 'Card registered successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'card_id' => $user->card_id,
            ],
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'card_id' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('card_id', $validated['card_id'])
            ->first();

        if (! $user) {
            return response()->json([
                'message' => 'Card not registered.',
            ], 404);
        }

        $now = Carbon::now();
        $today = $now->toDateString();

        $result = DB::transaction(function () use ($user, $now, $today) {
            $attendance = RfidAttendance::query()
                ->where('user_id', $user->id)
                ->whereDate('attendance_date', $today)
                ->lockForUpdate()
                ->first();

            if (! $attendance) {
                $attendance = RfidAttendance::create([
                    'user_id' => $user->id,
                    'attendance_date' => $today,
                    'check_in_time' => $now,
                    'source' => 'rfid',
                ]);

                return [
                    'action' => 'check_in',
                    'attendance' => $attendance,
                ];
            }

            if (! $attendance->check_out_time) {
                if (
                    $attendance->check_in_time
                    && $attendance->check_in_time->diffInMilliseconds($now) < 2000
                ) {
                    return [
                        'action' => 'duplicate_scan',
                        'attendance' => $attendance,
                    ];
                }

                $attendance->forceFill([
                    'check_out_time' => $now,
                ])->save();

                $point = Point::firstOrCreate(
                    ['user_id' => $user->id],
                    ['point' => 0],
                );

                $point->increment('point', 50);

                return [
                    'action' => 'check_out',
                    'attendance' => $attendance,
                ];
            }

            return [
                'action' => 'already_checked_out',
                'attendance' => $attendance,
            ];
        });

        if ($result['action'] === 'already_checked_out') {
            return response()->json([
                'message' => 'Attendance already completed for today.',
                'user' => [
                    'id' => $user->id,
                    'member_id' => $user->user_id,
                    'name' => $user->name,
                    'role' => $user->role,
                    'card_id' => $user->card_id,
                ],
                'attendance' => [
                    'action' => $result['action'],
                    'check_in_time' => $result['attendance']->check_in_time?->toIso8601String(),
                    'check_out_time' => $result['attendance']->check_out_time?->toIso8601String(),
                    'attendance_date' => $result['attendance']->attendance_date?->toDateString(),
                    'source' => $result['attendance']->source,
                ],
            ]);
        }

        if ($result['action'] === 'duplicate_scan') {
            return response()->json([
                'message' => 'Duplicate scan ignored.',
                'user' => [
                    'id' => $user->id,
                    'member_id' => $user->user_id,
                    'name' => $user->name,
                    'role' => $user->role,
                    'card_id' => $user->card_id,
                ],
                'attendance' => [
                    'action' => $result['action'],
                    'check_in_time' => $result['attendance']->check_in_time?->toIso8601String(),
                    'check_out_time' => $result['attendance']->check_out_time?->toIso8601String(),
                    'attendance_date' => $result['attendance']->attendance_date?->toDateString(),
                    'source' => $result['attendance']->source,
                ],
            ]);
        }

        $response = [
            'message' => $result['action'] === 'check_in'
                ? 'Check-in recorded successfully.'
                : 'Check-out recorded successfully.',
            'user' => [
                'id' => $user->id,
                'member_id' => $user->user_id,
                'name' => $user->name,
                'role' => $user->role,
                'card_id' => $user->card_id,
            ],
            'attendance' => [
                'action' => $result['action'],
                'check_in_time' => $result['attendance']->check_in_time?->toIso8601String(),
                'check_out_time' => $result['attendance']->check_out_time?->toIso8601String(),
                'attendance_date' => $result['attendance']->attendance_date?->toDateString(),
                'source' => $result['attendance']->source,
            ],
        ];

        if ($result['action'] === 'check_in') {
            try {
                $response = [
                    ...$response,
                    ...$this->activePlanReminderService->forUser($user, $now),
                ];
            } catch (Throwable $exception) {
                Log::warning('Unable to load active plan reminders after RFID check-in.', [
                    'user_id' => $user->id,
                    'attendance_id' => $result['attendance']->id,
                    'exception' => $exception,
                ]);

                $response['subscriptions'] = [];
                $response['trainer_bookings'] = [];
                $response['boxing_bookings'] = [];
            }
        }

        return response()->json($response);
    }
}
