<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Message;
use App\Models\MemberMembership;
use App\Models\MembershipPlan;
use App\Models\Point;
use App\Models\TrainerBooking;
use App\Models\BoxingBooking;
use App\Models\User;
use App\Models\PricingSetting;
use App\Notifications\NewMessageNotification;
use App\Support\RfidAttendanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    private const CLASS_PLAN_NAME = 'Class';

    public function __construct(
        private readonly RfidAttendanceService $rfidAttendanceService,
    ) {
    }

    public function home(): JsonResponse
    {
        $posts = BlogPost::query()
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (BlogPost $post) => [
                'id' => $post->id,
                'title' => $post->title,
                'summary' => $post->summary,
                'content' => $post->content,
                'published_at' => $post->published_at?->toIso8601String(),
                'created_at' => $post->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'posts' => $posts,
        ]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $user = $request->user();

        $recentScans = $this->rfidAttendanceService->recentUserActivity($user);
        $latestScan = $this->rfidAttendanceService->latestUserStatus($user);

        return response()->json([
            'latest_scan' => $latestScan,
            'recent_scans' => $recentScans,
        ]);
    }

    public function scanFromQr(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'QR attendance has been retired. RFID is the only supported attendance method.',
        ], 410);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $user = $request->user();

        $subscriptions = MemberMembership::query()
            ->with('plan')
            ->where('member_id', $user->id)
            ->orderByDesc('start_date')
            ->get();

        $pricingSetting = PricingSetting::query()->firstOrCreate(
            [],
            [
                'class_subscription_price' => 70000,
                'monthly_subscription_price' => 80000,
                'three_month_subscription_price' => 240000,
                'quarterly_subscription_price' => 400000,
                'annual_subscription_price' => 960000,
            ]
        );

        $today = Carbon::today();

        return response()->json([
            'subscriptions' => $subscriptions->map(function (MemberMembership $subscription) use ($pricingSetting, $today) {
                $durationDays = $subscription->plan?->duration_days ?? 0;
                $price = $subscription->plan
                    ? $this->resolvePlanPrice($subscription->plan, $pricingSetting)
                    : null;
                $discountPercentage = $subscription->discount_percentage !== null
                    ? (float) $subscription->discount_percentage
                    : null;
                $storedFinalPrice = $subscription->final_price !== null
                    ? (float) $subscription->final_price
                    : null;

                $finalPrice = $storedFinalPrice;

                if (
                    ($finalPrice === null || $finalPrice <= 0)
                    && ($discountPercentage === null || $discountPercentage <= 0)
                    && $price !== null
                ) {
                    $finalPrice = (float) $price;
                }


                $holdDays = 0;
                $adjustedEndDate = $subscription->end_date;

                if ($subscription->is_on_hold && $subscription->hold_started_at && $subscription->end_date) {
                    $holdStartedAt = Carbon::parse($subscription->hold_started_at);
                    $holdDays = max(0, $holdStartedAt->diffInDays($today));
                    $adjustedEndDate = Carbon::parse($subscription->end_date)->addDays($holdDays);
                }

                $isExpired = ! $subscription->is_on_hold
                    && ($subscription->is_expired || ($adjustedEndDate && $adjustedEndDate->lt($today)));
                $status = $subscription->is_on_hold ? 'On Hold' : ($isExpired ? 'Expired' : 'Active');

                return [
                    'id' => $subscription->id,
                    'plan_name' => $subscription->plan?->name ?? 'Plan',
                    'duration_days' => $durationDays,
                    'price' => $price,
                    'discount_percentage' => $discountPercentage,
                    'final_price' => $finalPrice ?? 0,
                    'created_at' => optional($subscription->created_at)->toIso8601String(),
                    'start_date' => optional($subscription->start_date)->toDateString(),
                    'end_date' => optional($adjustedEndDate)->toDateString(),
                    'is_on_hold' => (bool) $subscription->is_on_hold,
                    'status' => $status,
                ];
            }),
        ]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $user = $request->user();


        $bookings = TrainerBooking::query()
            ->with(['trainer', 'trainerPackage'])
            ->where('member_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (TrainerBooking $booking) {
                return [
                    'id' => $booking->id,
                    'trainer' => [
                        'id' => $booking->trainer?->id,
                        'name' => $booking->trainer?->name,
                        'phone' => $booking->trainer?->phone,
                        'email' => $booking->trainer?->email,
                    ],
                    'trainer_package' => $booking->trainerPackage
                        ? [
                            'id' => $booking->trainerPackage->id,
                            'name' => $booking->trainerPackage->name,
                            'package_type' => $booking->trainerPackage->package_type,
                            'sessions_count' => $booking->trainerPackage->sessions_count,
                            'duration_months' => $booking->trainerPackage->duration_months,
                            'price' => (float) $booking->trainerPackage->price,
                        ]
                        : null,
                    'sessions_count' => $booking->sessions_count,
                    'sessions_remaining' => $booking->sessions_remaining,
                    'start_date' => optional($booking->month_start_date ?? $booking->sessions_start_date)
                        ->toIso8601String(),
                    'end_date' => optional($booking->month_end_date ?? $booking->sessions_end_date)
                        ->toIso8601String(),
                    'total_price' => (float) $booking->total_price,
                    'discount_percentage' => (float) $booking->discount_percentage,
                    'final_price' => (float) $booking->final_price,
                    'status' => $booking->status,
                ];
            });

        return response()->json([
            'bookings' => $bookings,
        ]);
    }

    public function boxingBookings(Request $request): JsonResponse
    {
        $user = $request->user();

        $bookings = BoxingBooking::query()
            ->with(['trainer', 'boxingPackage'])
            ->where('member_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (BoxingBooking $booking) {
                return [
                    'id' => $booking->id,
                    'trainer' => [
                        'id' => $booking->trainer?->id,
                        'name' => $booking->trainer?->name,
                        'phone' => $booking->trainer?->phone,
                        'email' => $booking->trainer?->email,
                    ],
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
                    'sessions_remaining' => $booking->sessions_remaining,
                    'start_date' => optional($booking->month_start_date ?? $booking->sessions_start_date)
                        ->toIso8601String(),
                    'end_date' => optional($booking->month_end_date ?? $booking->sessions_end_date)
                        ->toIso8601String(),
                    'total_price' => (float) $booking->total_price,
                    'discount_percentage' => (float) $booking->discount_percentage,
                    'final_price' => (float) $booking->final_price,
                    'status' => $booking->status,
                ];
            });

        return response()->json([
            'bookings' => $bookings,
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        $member = $request->user();
        $admin = $this->adminUser();

        if (! $admin) {
            return response()->json([
                'message' => 'Administrator account not found.',
            ], 404);
        }

        $messages = Message::query()
            ->with('sender')
            ->where(function ($query) use ($member, $admin) {
                $query->where('sender_id', $member->id)
                    ->where('recipient_id', $admin->id);
            })
            ->orWhere(function ($query) use ($member, $admin) {
                $query->where('sender_id', $admin->id)
                    ->where('recipient_id', $member->id);
            })
            ->orderBy('created_at')
            ->get();

        Message::where('sender_id', $admin->id)
            ->where('recipient_id', $member->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ],
            'messages' => $messages->map(function (Message $message) use ($member) {
                return [
                    'id' => $message->id,
                    'body' => $message->body,
                    'created_at' => $message->created_at?->toIso8601String(),
                    'is_user' => $message->sender_id === $member->id,
                    'sender_name' => $message->sender?->name,
                ];
            }),
        ]);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $member = $request->user();
        $admin = $this->adminUser();

        if (! $admin) {
            return response()->json([
                'message' => 'Administrator account not found.',
            ], 404);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = Message::create([
            'sender_id' => $member->id,
            'recipient_id' => $admin->id,
            'body' => $validated['body'],
        ]);

        if ($this->shouldNotify($admin)) {
            $admin->notify(new NewMessageNotification($message, $member));
        }

        return response()->json([
            'status' => 'sent',
        ], 201);
    }

    private function resolvePlanPrice(MembershipPlan $plan, ?PricingSetting $pricingSetting): ?float
    {
        if ($plan->price !== null) {
            return (float) $plan->price;
        }

        $durationDays = $plan->duration_days;

        if (! $pricingSetting || ! $durationDays) {
            return null;
        }

        if ($plan->plan_type === 'class' || $plan->name === self::CLASS_PLAN_NAME) {
            if ($durationDays >= 360 && $pricingSetting->class_twelve_month_price !== null) {
                return (float) $pricingSetting->class_twelve_month_price;
            }

            if ($durationDays >= 180 && $pricingSetting->class_six_month_price !== null) {
                return (float) $pricingSetting->class_six_month_price;
            }

            if ($durationDays >= 90 && $pricingSetting->class_three_month_price !== null) {
                return (float) $pricingSetting->class_three_month_price;
            }

            return (float) $pricingSetting->class_subscription_price;
        }


        if ($durationDays >= 360) {
            return (float) $pricingSetting->annual_subscription_price;
        }

        if ($durationDays >= 180) {
            return (float) $pricingSetting->quarterly_subscription_price;
        }

         if ($durationDays >= 90) {
            return (float) $pricingSetting->three_month_subscription_price;
        }

        if ($durationDays >= 30) {
            return (float) $pricingSetting->monthly_subscription_price;
        }

        return null;
    }


    private function shouldNotify(User $user): bool
    {
        return $user->role === 'administrator' || $user->notifications_enabled;
    }

    private function adminUser(): ?User
    {
        return User::query()
            ->where('role', 'administrator')
            ->first();
    }
}
