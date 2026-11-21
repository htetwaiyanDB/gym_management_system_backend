<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoxingBooking;
use App\Models\BoxingPackage;
use App\Models\User;
use App\Support\FixedMonthPeriod;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BoxingBookingController extends Controller
{
    public function index()
    {
        $bookings = BoxingBooking::query()
            ->with(['member', 'trainer', 'boxingPackage'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (BoxingBooking $booking) {
                return [
                    'id' => $booking->id,
                    'member_id' => $booking->member_id,
                    'member_name' => $booking->member?->name ?? 'Unknown',
                    'member_phone' => $booking->member_phone,
                    'trainer_id' => $booking->trainer_id,
                    'trainer_name' => $booking->trainer?->name ?? 'Unknown',
                    'trainer_phone' => $booking->trainer_phone,
                    'boxing_package_id' => $booking->boxing_package_id,
                    'boxing_package' => $booking->boxingPackage
                        ? [
                            'id' => $booking->boxingPackage->id,
                            'name' => $booking->boxingPackage->name,
                            'package_type' => $booking->boxingPackage->package_type,
                            'sessions_count' => $booking->boxingPackage->sessions_count,
                            'duration_months' => $booking->boxingPackage->duration_months,
                            'price' => (float) $booking->boxingPackage->price,
                        ]
                        : null,
                    'sessions_count' => $booking->sessions_count,
                    'price_per_session' => $booking->price_per_session,
                    'sessions_remaining' => $booking->sessions_remaining,
                    'sessions_start_date' => optional($booking->sessions_start_date)->toIso8601String(),
                    'sessions_end_date' => optional($booking->sessions_end_date)->toIso8601String(),
                    'month_start_date' => optional($booking->month_start_date)->toIso8601String(),
                    'month_end_date' => optional($booking->month_end_date)->toIso8601String(),
                    'hold_start_date' => optional($booking->hold_start_date)->toIso8601String(),
                    'hold_end_date' => optional($booking->hold_end_date)->toIso8601String(),
                    'total_hold_days' => $booking->total_hold_days,
                    'total_price' => $booking->total_price,
                    'discount_percentage' => $booking->discount_percentage,
                    'final_price' => $booking->final_price,
                    'status' => $booking->status,
                    'paid_status' => $booking->paid_status,
                    'paid_at' => optional($booking->paid_at)->toIso8601String(),
                    'notes' => $booking->notes,
                ];
            });

        return response()->json([
            'bookings' => $bookings,
        ]);
    }

    public function options()
    {
        $members = User::query()
            ->where('role', 'user')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone']);

        $trainers = User::query()
            ->where('role', 'trainer')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone']);

        $boxingPackages = BoxingPackage::query()
            ->orderBy('package_type')
            ->orderBy('sessions_count')
            ->orderBy('duration_months')
            ->get();

        return response()->json([
            'members' => $members,
            'trainers' => $trainers,
            'boxing_packages' => $boxingPackages,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'member_id' => [
                'required',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'user')),
            ],
            'trainer_id' => [
                'required',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'trainer')),
            ],
            'boxing_package_id' => ['required', Rule::exists('boxing_packages', 'id')],
            'sessions_count' => ['nullable', 'integer', 'min:1'],
            'price_per_session' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'status' => ['nullable', 'string'],
            'paid_status' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $package = BoxingPackage::findOrFail($validated['boxing_package_id']);
        $isMonthBased = (int) ($package->duration_months ?? 0) > 0;
        $startDate = Carbon::parse($validated['start_date']);
        $endDate = $isMonthBased
            ? FixedMonthPeriod::endDate($startDate, (int) $package->duration_months)
            : Carbon::parse($validated['end_date'] ?? throw ValidationException::withMessages([
                'end_date' => ['The end date field is required for session-based bookings.'],
            ]));
        $requestedSessions = $validated['sessions_count'] ?? null;
        $sessionsCount = $package->sessions_count
            ?? ($isMonthBased && $package->duration_months ? $package->duration_months : null)
            ?? $requestedSessions
            ?? 0;
        $sessionsCount = (int) $sessionsCount;

        if ($sessionsCount < 1) {
            return response()->json([
                'message' => 'Sessions count must be at least 1.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ((float) $package->price <= 0) {
            return response()->json([
                'message' => 'Package price must be greater than 0.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $pricePerSession = round((float) ($package->price / $sessionsCount), 2);
        $totalPrice = (float) $package->price;
        $discountPercentage = (float) ($validated['discount_percentage'] ?? 0);
        $discountPercentage = max(0, min($discountPercentage, 100));
        $finalPrice = max(0, $totalPrice - (($totalPrice * $discountPercentage) / 100));

        $status = $validated['status'] ?? 'confirmed';
        $paidStatus = $validated['paid_status'] ?? 'unpaid';

        $payload = [
            'member_id' => $validated['member_id'],
            'trainer_id' => $validated['trainer_id'],
            'boxing_package_id' => $package->id,
            'sessions_count' => $sessionsCount,
            'sessions_start_date' => $isMonthBased ? null : $startDate,
            'sessions_end_date' => $isMonthBased ? null : $endDate,
            'month_start_date' => $isMonthBased ? $startDate : null,
            'month_end_date' => $isMonthBased ? $endDate : null,
            'hold_start_date' => null,
            'hold_end_date' => null,
            'total_hold_days' => 0,
            'price_per_session' => $pricePerSession,
            'sessions_remaining' => $sessionsCount,
            'total_price' => $totalPrice,
            'discount_percentage' => $discountPercentage,
            'final_price' => $finalPrice,
            'status' => $status,
            'paid_status' => $paidStatus,
            'paid_at' => $paidStatus === 'paid' ? now() : null,
            'notes' => $validated['notes'] ?? null,
        ];

        try {
            $booking = BoxingBooking::create($payload);
        } catch (QueryException $exception) {
            return response()->json([
                'message' => 'Unable to create boxing booking. Verify database schema and data.',
                'error' => config('app.debug') ? $exception->getMessage() : null,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Boxing booking created successfully.',
            'booking_id' => $booking->id,
        ], Response::HTTP_CREATED);
    }

    public function updateSessions(Request $request, BoxingBooking $booking)
    {
        if (! $booking->isSessionBased()) {
            return response()->json([
                'message' => 'Sessions can only be adjusted for session-based bookings.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validate([
            'sessions_remaining' => ['nullable', 'integer', 'min:0', 'required_without:adjustment'],
            'adjustment' => ['nullable', 'integer', 'required_without:sessions_remaining'],
        ]);

        $newRemaining = $booking->sessions_remaining;

        if (array_key_exists('sessions_remaining', $validated)) {
            $newRemaining = (int) $validated['sessions_remaining'];
        }

        if (array_key_exists('adjustment', $validated)) {
            $newRemaining = $booking->sessions_remaining + (int) $validated['adjustment'];
        }

        if ($newRemaining < 0) {
            return response()->json([
                'message' => 'Remaining sessions cannot be negative.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $booking->update([
            'sessions_remaining' => $newRemaining,
        ]);

        return response()->json([
            'message' => 'Remaining sessions updated.',
            'sessions_remaining' => $booking->sessions_remaining,
        ]);
    }

    public function markPaid(BoxingBooking $booking)
    {
        if ($booking->paid_status !== 'paid') {
            $booking->update([
                'paid_status' => 'paid',
                'paid_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Booking marked as paid.',
        ]);
    }

    public function markActive(BoxingBooking $booking)
    {
         if ($booking->isMonthBased() && $booking->status === 'on-hold' && $booking->hold_start_date) {
            $resumeDate = Carbon::now();
            $holdStartedAt = Carbon::parse($booking->hold_start_date);
            $holdDays = max(0, $holdStartedAt->diffInDays($resumeDate));

            $booking->update([
                'month_end_date' => $booking->month_end_date
                    ? Carbon::parse($booking->month_end_date)->addDays($holdDays)
                    : null,
                'hold_end_date' => $resumeDate,
                'total_hold_days' => (int) $booking->total_hold_days + $holdDays,
                'hold_start_date' => null,
                'status' => 'active',
            ]);
        } elseif ($booking->status !== 'active') {
            $booking->update([
                'status' => 'active',
            ]);
        }

        return response()->json([
            'message' => 'Booking marked as active.',
        ]);
    }

    public function markHold(BoxingBooking $booking)
    {
        if ($booking->status !== 'on-hold') {
            $payload = [
                'status' => 'on-hold',
              ];

            if ($booking->isMonthBased() && ! $booking->hold_start_date) {
                $payload['hold_start_date'] = Carbon::now();
                $payload['hold_end_date'] = null;
            }

            $booking->update($payload);
        }

        return response()->json([
            'message' => 'Booking marked as hold.',
        ]);
    }

    public function hold(BoxingBooking $booking)
    {
        if (! $booking->isMonthBased()) {
            return response()->json([
                'message' => 'Hold is only available for month-based bookings.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($booking->status === 'completed') {
            return response()->json([
                'message' => 'Completed bookings cannot be placed on hold.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($booking->status === 'on-hold' || $booking->hold_start_date) {
            return response()->json([
                'message' => 'Booking is already on hold.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $today = Carbon::now();

        if ($booking->month_end_date && $booking->month_end_date->lt($today)) {
            return response()->json([
                'message' => 'Completed bookings cannot be placed on hold.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $booking->update([
            'status' => 'on-hold',
            'hold_start_date' => $today,
        ]);

        return response()->json([
            'message' => 'Booking placed on hold.',
        ]);
    }

    public function resume(BoxingBooking $booking)
    {
        if (! $booking->isMonthBased()) {
            return response()->json([
                'message' => 'Resume is only available for month-based bookings.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($booking->status !== 'on-hold' || ! $booking->hold_start_date) {
            return response()->json([
                'message' => 'Booking is not on hold.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $resumeDate = Carbon::now();
        $holdStartedAt = Carbon::parse($booking->hold_start_date);
        $holdDays = max(0, $holdStartedAt->diffInDays($resumeDate));

        $booking->month_end_date = $booking->month_end_date
            ? Carbon::parse($booking->month_end_date)->addDays($holdDays)
            : null;

        $booking->hold_end_date = $resumeDate;
        $booking->total_hold_days = (int) $booking->total_hold_days + $holdDays;
        $booking->hold_start_date = null;
        $booking->status = 'active';
        $booking->save();

        return response()->json([
            'message' => 'Booking resumed.',
            'total_hold_days' => $booking->total_hold_days,
            'month_end_date' => optional($booking->month_end_date)->toIso8601String(),
        ]);
    }



    public function extend(Request $request, BoxingBooking $booking)
    {
        $validated = $request->validate([
            'extension_days' => ['nullable', 'integer', 'min:1', 'required_without:new_end_date'],
            'new_end_date' => ['nullable', 'date', 'required_without:extension_days'],
        ]);

        $endDateColumn = $booking->isMonthBased() ? 'month_end_date' : 'sessions_end_date';
        $currentEndDate = $booking->{$endDateColumn}
            ? Carbon::parse($booking->{$endDateColumn})
            : Carbon::today();

        if (array_key_exists('new_end_date', $validated) && $validated['new_end_date'] !== null) {
            $updatedEndDate = Carbon::parse($validated['new_end_date']);

            if ($updatedEndDate->lte($currentEndDate)) {
                return response()->json([
                    'message' => 'New end date must be after the current end date.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            $updatedEndDate = $currentEndDate->copy()->addDays((int) $validated['extension_days']);
        }

        $booking->update([
            $endDateColumn => $updatedEndDate,
        ]);

        return response()->json([
            'message' => 'Booking end date extended successfully.',
            'end_date' => optional($booking->{$endDateColumn})->toIso8601String(),
        ]);
    }

    public function destroy(BoxingBooking $booking)
    {
        $booking->delete();

        return response()->json([
            'message' => 'Boxing booking deleted successfully.',
        ]);
    }

}
