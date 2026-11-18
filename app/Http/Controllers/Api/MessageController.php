<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MessageController extends Controller
{
    public function conversations(Request $request): Response
    {
        $admin = $request->user();

        $messages = Message::with(['sender', 'recipient'])
            ->where(function ($query) use ($admin) {
                $query->where('sender_id', $admin->id)
                    ->orWhere('recipient_id', $admin->id);
            })
            ->orderByDesc('created_at')
            ->get();

        $unreadCounts = Message::query()
            ->selectRaw('sender_id, COUNT(*) as unread_count')
            ->unreadForAdmin($admin)
            ->groupBy('sender_id')
            ->pluck('unread_count', 'sender_id');

        $conversations = [];
        $seen = [];

        foreach ($messages as $message) {
            $otherUser = $message->sender_id === $admin->id
                ? $message->recipient
                : $message->sender;

            if (! $otherUser) {
                continue;
            }

            if (isset($seen[$otherUser->id])) {
                continue;
            }

            $seen[$otherUser->id] = true;

            $conversations[] = [
                'user_id' => $otherUser->id,
                'user_name' => $otherUser->name,
                'user_email' => $otherUser->email,
                'user_role' => $otherUser->role,
                'preview' => $message->body,
                'updated_at' => $message->created_at,
                'unread_count' => (int) ($unreadCounts[$otherUser->id] ?? 0),
            ];
        }

        usort($conversations, function (array $first, array $second): int {
            $unreadComparison = ($second['unread_count'] > 0) <=> ($first['unread_count'] > 0);

            if ($unreadComparison !== 0) {
                return $unreadComparison;
            }

            return $second['updated_at'] <=> $first['updated_at'];
        });

        return response([
            'conversations' => $conversations,
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => Message::query()
                ->unreadForAdmin($request->user())
                ->count(),
        ]);
    }

    public function markAsRead(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        if (! in_array($user->role, ['user', 'trainer'], true)) {
            return response()->json([
                'message' => 'User or trainer conversation not found.',
            ], 404);
        }

        $hasConversation = Message::query()
            ->where(function ($query) use ($admin, $user) {
                $query->where('sender_id', $user->id)
                    ->where('recipient_id', $admin->id);
            })
            ->orWhere(function ($query) use ($admin, $user) {
                $query->where('sender_id', $admin->id)
                    ->where('recipient_id', $user->id);
            })
            ->exists();

        if (! $hasConversation) {
            return response()->json([
                'message' => 'User or trainer conversation not found.',
            ], 404);
        }

        $markedCount = Message::query()
            ->unreadForAdmin($admin)
            ->where('sender_id', $user->id)
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Messages marked as read.',
            'marked_count' => $markedCount,
            'unread_count' => Message::query()
                ->unreadForAdmin($admin)
                ->count(),
        ]);
    }

    public function thread(Request $request, User $user): Response
    {
        $admin = $request->user();

        $messages = Message::with(['sender'])
            ->where(function ($query) use ($admin, $user) {
                $query->where('sender_id', $user->id)
                    ->where('recipient_id', $admin->id);
            })
            ->orWhere(function ($query) use ($admin, $user) {
                $query->where('sender_id', $admin->id)
                    ->where('recipient_id', $user->id);
            })
            ->orderBy('created_at')
            ->get();

        Message::where('sender_id', $user->id)
            ->where('recipient_id', $admin->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $payload = $messages->map(function (Message $message) use ($admin) {
            return [
                'id' => $message->id,
                'body' => $message->body,
                'created_at' => $message->created_at,
                'is_admin' => $message->sender_id === $admin->id,
                'sender_name' => $message->sender?->name,
            ];
        });

        return response([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'messages' => $payload,
        ]);
    }

    public function store(Request $request, User $user): Response
    {
        $admin = $request->user();

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = Message::create([
            'sender_id' => $admin->id,
            'recipient_id' => $user->id,
            'body' => $validated['body'],
        ]);

        if ($this->shouldNotify($user)) {
            $user->notify(new NewMessageNotification($message, $admin));
        }

        return response(['status' => 'sent'], 201);
    }

    private function shouldNotify(User $user): bool
    {
        return $user->role === 'administrator' || $user->notifications_enabled;
    }
}
