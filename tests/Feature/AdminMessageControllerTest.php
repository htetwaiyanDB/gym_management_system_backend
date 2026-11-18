<?php

use App\Http\Controllers\Admin\MessageController;
use App\Models\Message;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'administrator']);
    $this->actingAs($this->admin);
});

it('returns the admin unread message count for users and trainers only', function () {
    $user = User::factory()->create(['role' => 'user']);
    $trainer = User::factory()->create(['role' => 'trainer']);
    $otherRole = User::factory()->create(['role' => 'admission']);

    Message::create(['sender_id' => $user->id, 'recipient_id' => $this->admin->id, 'body' => 'User unread']);
    Message::create(['sender_id' => $trainer->id, 'recipient_id' => $this->admin->id, 'body' => 'Trainer unread']);
    Message::create(['sender_id' => $otherRole->id, 'recipient_id' => $this->admin->id, 'body' => 'Other role']);
    Message::create(['sender_id' => $this->admin->id, 'recipient_id' => $user->id, 'body' => 'Admin outgoing']);

    $response = app(MessageController::class)->unreadCount();

    expect($response->getData(true))->toBe(['unread_count' => 2]);
});

it('marks only the selected user conversation as read', function () {
    $user = User::factory()->create(['role' => 'user']);
    $trainer = User::factory()->create(['role' => 'trainer']);

    $userMessages = collect([
        Message::create(['sender_id' => $user->id, 'recipient_id' => $this->admin->id, 'body' => 'One']),
        Message::create(['sender_id' => $user->id, 'recipient_id' => $this->admin->id, 'body' => 'Two']),
    ]);
    $trainerMessage = Message::create([
        'sender_id' => $trainer->id,
        'recipient_id' => $this->admin->id,
        'body' => 'Trainer unread',
    ]);

    $response = app(MessageController::class)->markAsRead($user);

    expect($response->getData(true))->toBe([
        'message' => 'Messages marked as read.',
        'marked_count' => 2,
        'unread_count' => 1,
    ]);
    $userMessages->each(fn (Message $message) => expect($message->fresh()->read_at)->not->toBeNull()
    );
    expect($trainerMessage->fresh()->read_at)->toBeNull();
});

it('adds unread counts and sorts unread admin conversations before read conversations', function () {
    $olderUnreadUser = User::factory()->create(['role' => 'user']);
    $newerUnreadTrainer = User::factory()->create(['role' => 'trainer']);
    $newestReadUser = User::factory()->create(['role' => 'user']);

    $olderUnread = Message::create([
        'sender_id' => $olderUnreadUser->id,
        'recipient_id' => $this->admin->id,
        'body' => 'Older unread',
    ]);
    $newerUnread = Message::create([
        'sender_id' => $newerUnreadTrainer->id,
        'recipient_id' => $this->admin->id,
        'body' => 'Newer unread',
    ]);
    $newestRead = Message::create([
        'sender_id' => $newestReadUser->id,
        'recipient_id' => $this->admin->id,
        'body' => 'Newest read',
        'read_at' => now(),
    ]);

    $olderUnread->forceFill(['created_at' => now()->subHours(3)])->saveQuietly();
    $newerUnread->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
    $newestRead->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    $response = app(MessageController::class)->conversations();
    $conversations = $response->getOriginalContent()['conversations'];

    expect(array_column($conversations, 'user_id'))->toBe([
        $newerUnreadTrainer->id,
        $olderUnreadUser->id,
        $newestReadUser->id,
    ])->and(array_column($conversations, 'unread_count'))->toBe([1, 1, 0]);
});
