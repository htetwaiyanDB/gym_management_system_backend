<?php

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();

    $this->admin = User::factory()->create(['role' => 'administrator']);
});

it('counts an unread message sent by a user to the administrator', function () {
    $user = User::factory()->create(['role' => 'user']);

    Sanctum::actingAs($user);
    $this->postJson('/api/user/messages', ['body' => 'Help from a user'])
        ->assertCreated()
        ->assertExactJson(['status' => 'sent']);

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/messages/unread-count')
        ->assertOk()
        ->assertExactJson(['unread_count' => 1]);
});

it('counts an unread message sent by a trainer to the administrator', function () {
    $trainer = User::factory()->create(['role' => 'trainer']);

    Sanctum::actingAs($trainer);
    $this->postJson('/api/trainer/messages', ['body' => 'Help from a trainer'])
        ->assertCreated()
        ->assertExactJson(['status' => 'sent']);

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/messages/unread-count')
        ->assertOk()
        ->assertExactJson(['unread_count' => 1]);
});

it('does not count messages sent by the administrator', function () {
    $user = User::factory()->create(['role' => 'user']);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/messages/{$user->id}", ['body' => 'Admin reply'])
        ->assertCreated()
        ->assertExactJson(['status' => 'sent']);

    $this->getJson('/api/messages/unread-count')
        ->assertOk()
        ->assertExactJson(['unread_count' => 0]);
});

it('marks only the selected conversation as read and returns the remaining total', function () {
    $user = User::factory()->create(['role' => 'user']);
    $trainer = User::factory()->create(['role' => 'trainer']);

    $userMessages = collect([
        Message::create(['sender_id' => $user->id, 'recipient_id' => $this->admin->id, 'body' => 'One']),
        Message::create(['sender_id' => $user->id, 'recipient_id' => $this->admin->id, 'body' => 'Two']),
    ]);
    $trainerMessage = Message::create([
        'sender_id' => $trainer->id,
        'recipient_id' => $this->admin->id,
        'body' => 'Trainer message',
    ]);
    $adminMessage = Message::create([
        'sender_id' => $this->admin->id,
        'recipient_id' => $user->id,
        'body' => 'Admin message',
    ]);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/messages/{$user->id}/mark-as-read")
        ->assertOk()
        ->assertExactJson([
            'message' => 'Messages marked as read.',
            'marked_count' => 2,
            'unread_count' => 1,
        ]);

    $userMessages->each(fn (Message $message) => $this->assertNotNull($message->fresh()->read_at)
    );
    $this->assertNull($trainerMessage->fresh()->read_at);
    $this->assertNull($adminMessage->fresh()->read_at);
});

it('returns the correct total unread count across user and trainer conversations', function () {
    $user = User::factory()->create(['role' => 'user']);
    $trainer = User::factory()->create(['role' => 'trainer']);

    Message::create(['sender_id' => $user->id, 'recipient_id' => $this->admin->id, 'body' => 'Unread user']);
    Message::create(['sender_id' => $trainer->id, 'recipient_id' => $this->admin->id, 'body' => 'Unread trainer']);
    Message::create([
        'sender_id' => $user->id,
        'recipient_id' => $this->admin->id,
        'body' => 'Read user',
        'read_at' => now(),
    ]);
    Message::create(['sender_id' => $this->admin->id, 'recipient_id' => $trainer->id, 'body' => 'Admin reply']);

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/messages/unread-count')
        ->assertOk()
        ->assertExactJson(['unread_count' => 2]);
});

it('prevents users and trainers from accessing unread administration endpoints', function (string $role) {
    $actor = User::factory()->create(['role' => $role]);
    $conversationUser = User::factory()->create(['role' => $role === 'user' ? 'trainer' : 'user']);

    Sanctum::actingAs($actor);
    $this->getJson('/api/messages/unread-count')->assertForbidden();
    $this->postJson("/api/messages/{$conversationUser->id}/mark-as-read")->assertForbidden();
})->with(['user', 'trainer']);

it('adds per-conversation unread counts and prioritizes unread conversations by latest message', function () {
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
        'body' => 'Newest but read',
        'read_at' => now(),
    ]);

    $olderUnread->forceFill(['created_at' => now()->subHours(3)])->saveQuietly();
    $newerUnread->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
    $newestRead->forceFill(['created_at' => now()->subHour()])->saveQuietly();

    Sanctum::actingAs($this->admin);
    $response = $this->getJson('/api/messages')
        ->assertOk()
        ->assertJsonStructure([
            'conversations' => [[
                'user_id',
                'user_name',
                'user_email',
                'user_role',
                'preview',
                'updated_at',
                'unread_count',
            ]],
        ]);

    expect($response->json('conversations.*.user_id'))->toBe([
        $newerUnreadTrainer->id,
        $olderUnreadUser->id,
        $newestReadUser->id,
    ]);
    expect($response->json('conversations.*.unread_count'))->toBe([1, 1, 0]);
});

it('keeps existing send and thread API response contracts working', function () {
    $user = User::factory()->create(['role' => 'user']);

    Sanctum::actingAs($user);
    $this->postJson('/api/user/messages', ['body' => 'Existing send workflow'])
        ->assertCreated()
        ->assertExactJson(['status' => 'sent']);

    Sanctum::actingAs($this->admin);
    $this->getJson("/api/messages/{$user->id}")
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('messages.0.body', 'Existing send workflow')
        ->assertJsonPath('messages.0.is_admin', false);
});

it('returns not found for an invalid or nonexistent conversation', function () {
    $userWithoutMessages = User::factory()->create(['role' => 'user']);
    $invalidRole = User::factory()->create(['role' => 'admission']);

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/messages/{$userWithoutMessages->id}/mark-as-read")->assertNotFound();
    $this->postJson("/api/messages/{$invalidRole->id}/mark-as-read")->assertNotFound();
    $this->postJson('/api/messages/999999/mark-as-read')->assertNotFound();
});
