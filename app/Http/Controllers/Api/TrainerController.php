<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Message;
use App\Models\TrainerBooking;
use App\Models\BoxingBooking;
use App\Notifications\NewMessageNotification;
use App\Support\RfidAttendanceService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainerController extends Controller
{
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

        $bookings = TrainerBooking::query()
            ->with(['member', 'trainerPackage'])
            ->where('trainer_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (TrainerBooking $booking) {
                return [
                    'id' => $booking->id,
                    'member' => [
                        'id' => $booking->member?->id,
                        'name' => $booking->member?->name,
                        'email' => $booking->member?->email,
                        'phone' => $booking->member?->phone,
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
                    'status' => $booking->status,
                    'paid_status' => $booking->paid_status,
                    'notes' => $booking->notes,
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
            ->with(['member', 'boxingPackage'])
            ->where('trainer_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (BoxingBooking $booking) {
                return [
                    'id' => $booking->id,
                    'member' => [
                        'id' => $booking->member?->id,
                        'name' => $booking->member?->name,
                        'email' => $booking->member?->email,
                        'phone' => $booking->member?->phone,
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
                    'status' => $booking->status,
                    'paid_status' => $booking->paid_status,
                    'notes' => $booking->notes,
                ];
            });

        return response()->json([
            'bookings' => $bookings,
        ]);
    }


    public function messages(Request $request): JsonResponse
    {
        $trainer = $request->user();
        $admin = $this->adminUser();

        if (! $admin) {
            return response()->json([
                'message' => 'Administrator account not found.',
            ], 404);
        }

        $messages = Message::query()
            ->with('sender')
            ->where(function ($query) use ($trainer, $admin) {
                $query->where('sender_id', $trainer->id)
                    ->where('recipient_id', $admin->id);
            })
            ->orWhere(function ($query) use ($trainer, $admin) {
                $query->where('sender_id', $admin->id)
                    ->where('recipient_id', $trainer->id);
            })
            ->orderBy('created_at')
            ->get();

        Message::where('sender_id', $admin->id)
            ->where('recipient_id', $trainer->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ],
            'messages' => $messages->map(function (Message $message) use ($trainer) {
                return [
                    'id' => $message->id,
                    'body' => $message->body,
                    'created_at' => $message->created_at?->toIso8601String(),
                    'is_trainer' => $message->sender_id === $trainer->id,
                    'sender_name' => $message->sender?->name,
                ];
            }),
        ]);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $trainer = $request->user();
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
            'sender_id' => $trainer->id,
            'recipient_id' => $admin->id,
            'body' => $validated['body'],
        ]);

        if ($this->shouldNotify($admin)) {
            $admin->notify(new NewMessageNotification($message, $trainer));
        }

        return response()->json([
            'status' => 'sent',
        ], 201);
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
