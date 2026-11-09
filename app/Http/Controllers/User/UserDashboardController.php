<?php

namespace App\Http\Controllers\User;

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

class UserDashboardController extends Controller
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

        return view('user.home', [
            'posts' => $posts,
        ]);
    }

    public function checkIn(): View
    {
        $user = auth()->user();

        return view('user.check_in', [
            'recentScans' => $this->rfidAttendanceService->recentUserActivity($user),
            'latestScan' => $this->rfidAttendanceService->latestUserStatus($user),
        ]);
    }

    public function subscriptions(): View
    {
        $user = auth()->user();

        $bookings = TrainerBooking::query()
            ->with(['trainer', 'trainerPackage'])
            ->where('member_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return view('user.subscriptions', [
            'bookings' => $bookings,
        ]);
    }

    public function messages(): View
    {
        $member = auth()->user();
        $admin = $this->adminUser();

        $messages = collect();

        if ($admin) {
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
        }

        return view('user.messages', [
            'admin' => $admin,
            'messages' => $messages,
        ]);
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        $member = $request->user();
        $admin = $this->adminUser();

        if (!$admin) {
            return back()->withErrors(['message' => 'Administrator account not found.']);
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
