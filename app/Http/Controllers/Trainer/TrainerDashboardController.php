<?php

namespace App\Http\Controllers\Trainer;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Message;
use App\Models\TrainerBooking;
use App\Support\RfidAttendanceService;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrainerDashboardController extends Controller
{
    public function __construct(
        private readonly RfidAttendanceService $rfidAttendanceService,
    ) {
    }

    public function home(): View
    {
        $posts = BlogPost::query()
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get();

        return view('trainer.home', [
            'posts' => $posts,
        ]);
    }

    public function checkIn(): View
    {
        $user = auth()->user();

        return view('trainer.check_in', [
            'recentScans' => $this->rfidAttendanceService->recentUserActivity($user),
            'latestScan' => $this->rfidAttendanceService->latestUserStatus($user),
        ]);
    }

    public function subscriptions(): View
    {
        $user = auth()->user();

        $bookings = TrainerBooking::query()
            ->with(['member', 'trainerPackage'])
            ->where('trainer_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return view('trainer.subscriptions', [
            'bookings' => $bookings,
        ]);
    }

    public function messages(): View
    {
        $trainer = auth()->user();
        $admin = $this->adminUser();

        $messages = collect();

        if ($admin) {
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
        }

        return view('trainer.messages', [
            'admin' => $admin,
            'messages' => $messages,
        ]);
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        $trainer = $request->user();
        $admin = $this->adminUser();

        if (!$admin) {
            return back()->withErrors(['message' => 'Administrator account not found.']);
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

        return back()->with('status', 'Message sent to admin.');
    }

    private function adminUser(): ?User
    {
        return User::query()
            ->where('role', 'administrator')
            ->first();
    }

    private function shouldNotify(User $user): bool
    {
        return $user->role === 'administrator' || $user->notifications_enabled;
    }
}
