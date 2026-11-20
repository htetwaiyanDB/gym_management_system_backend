<?php

namespace App\Support;

use App\Models\BoxingBooking;
use App\Models\MemberMembership;
use App\Models\TrainerBooking;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class ActivePlanReminderService
{
    /**
     * Load the single most relevant active plan in each reminder category.
     *
     * @return array{
     *     subscriptions: array<int, array<string, int|string|null>>,
     *     trainer_bookings: array<int, array<string, int|string|null>>,
     *     boxing_bookings: array<int, array<string, int|string|null>>
     * }
     */
    public function forUser(User $user, CarbonInterface $day): array
    {
        $date = $day->toDateString();
        $dayStart = $day->toImmutable()->startOfDay();
        $dayEnd = $day->toImmutable()->endOfDay();

        $subscription = MemberMembership::query()
            ->with('plan:id,name')
            ->where('member_id', $user->id)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->where(function (Builder $query) {
                $query->where('is_expired', false)->orWhereNull('is_expired');
            })
            ->where(function (Builder $query) {
                $query->where('is_on_hold', false)->orWhereNull('is_on_hold');
            })
            ->orderByDesc('end_date')
            ->first(['id', 'membership_plan_id', 'start_date', 'end_date']);

        $trainerBooking = $this->currentBooking(
            TrainerBooking::query()->with('trainerPackage:id,name'),
            $user,
            $dayStart,
            $dayEnd,
            'trainer_package_id',
        );

        $boxingBooking = $this->currentBooking(
            BoxingBooking::query()->with('boxingPackage:id,name'),
            $user,
            $dayStart,
            $dayEnd,
            'boxing_package_id',
        );

        return [
            'subscriptions' => $subscription ? [[
                'id' => $subscription->id,
                'plan_name' => $subscription->plan?->name ?? 'Plan',
                'start_date' => $subscription->start_date?->toDateString(),
                'end_date' => $subscription->end_date?->toDateString(),
                'status' => 'active',
            ]] : [],
            'trainer_bookings' => $trainerBooking ? [[
                'id' => $trainerBooking->id,
                'package_name' => $trainerBooking->trainerPackage?->name ?? 'Trainer package',
                'start_date' => $this->bookingStartDate($trainerBooking)?->toDateString(),
                'end_date' => $this->bookingEndDate($trainerBooking)?->toDateString(),
                'status' => 'active',
            ]] : [],
            'boxing_bookings' => $boxingBooking ? [[
                'id' => $boxingBooking->id,
                'package_name' => $boxingBooking->boxingPackage?->name ?? 'Boxing package',
                'start_date' => $this->bookingStartDate($boxingBooking)?->toDateString(),
                'end_date' => $this->bookingEndDate($boxingBooking)?->toDateString(),
                'status' => 'active',
            ]] : [],
        ];
    }

    private function currentBooking(
        Builder $query,
        User $user,
        CarbonInterface $dayStart,
        CarbonInterface $dayEnd,
        string $packageForeignKey,
    ): TrainerBooking|BoxingBooking|null {
        return $query
            ->where('member_id', $user->id)
            ->whereIn('status', ['active', 'confirmed'])
            ->whereNull('hold_start_date')
            ->where(function (Builder $query) use ($dayStart, $dayEnd) {
                $query->where(function (Builder $query) use ($dayStart, $dayEnd) {
                    $query->where('month_start_date', '<=', $dayEnd)
                        ->where('month_end_date', '>=', $dayStart);
                })->orWhere(function (Builder $query) use ($dayStart, $dayEnd) {
                    $query->where('sessions_start_date', '<=', $dayEnd)
                        ->where('sessions_end_date', '>=', $dayStart);
                });
            })
            ->orderByRaw('COALESCE(month_end_date, sessions_end_date) DESC')
            ->first([
                'id',
                $packageForeignKey,
                'sessions_start_date',
                'sessions_end_date',
                'month_start_date',
                'month_end_date',
            ]);
    }

    private function bookingStartDate(TrainerBooking|BoxingBooking $booking): ?CarbonInterface
    {
        return $booking->month_start_date ?? $booking->sessions_start_date;
    }

    private function bookingEndDate(TrainerBooking|BoxingBooking $booking): ?CarbonInterface
    {
        return $booking->month_end_date ?? $booking->sessions_end_date;
    }
}
