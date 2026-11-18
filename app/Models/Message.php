<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function scopeUnreadForAdmin(Builder $query, User $admin): Builder
    {
        return $query
            ->where('recipient_id', $admin->id)
            ->where('sender_id', '!=', $admin->id)
            ->whereNull('read_at')
            ->whereHas('sender', function (Builder $query) {
                $query->whereIn('role', ['user', 'trainer']);
            });
    }
}
