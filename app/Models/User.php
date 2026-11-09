<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable,SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'card_id',
        'notifications_enabled',
        'email_verified_at',
        'email_verification_code',
        'email_verification_expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'notifications_enabled' => 'boolean',
        ];
    }

    /**
     * Boot the model.
     * Prevent creating multiple administrator accounts.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($user) {
            // If trying to create a administrator, check if one already exists
            if ($user->role === 'administrator') {
                $existingRootUser = static::where('role', 'administrator')
                    ->where('id', '!=', $user->id)
                    ->exists();

                if ($existingRootUser) {
                    Log::warning('Attempt to create duplicate administrator blocked', [
                        'email' => $user->email,
                    ]);

                    throw ValidationException::withMessages([
                        'role' => ['Root user already exists. Only one root user is allowed in the system.'],
                    ]);
                }
            }
        });

        // static::created(function ($user) {
        //     if (! $user->user_id) {
        //         $user->forceFill([
        //             'user_id' => str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
        //         ])->saveQuietly();
        //     }
        // });

        static::updating(function ($user) {
            // Prevent changing a non root user to administrator if one already exists
            if ($user->isDirty('role') && $user->role === 'administrator') {
                $existingRootUser = static::where('role', 'administrator')
                    ->where('id', '!=', $user->id)
                    ->exists();

                if ($existingRootUser) {
                    Log::warning('Attempt to change role to administrator blocked - root user already exists', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ]);

                    throw ValidationException::withMessages([
                        'role' => ['Root user already exists. Only one root user is allowed in the system.'],
                    ]);
                }
            }
        });

        static::deleting(function ($user) {
            // Prevent deleting administrator
            if ($user->role === 'administrator') {
                Log::warning('Attempt to delete administrator blocked', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);

                throw ValidationException::withMessages([
                    'role' => ['Administrator cannot be deleted from the system.'],
                ]);
            }
        });

    }

    public function attendanceScans()
    {
        return $this->hasMany(AttendanceScan::class);
    }

    public function attendances()
    {
        return $this->hasMany(AttendanceScan::class);
    }

    public function rfidAttendances()
    {
        return $this->hasMany(RfidAttendance::class);
    }

    public function trainerAssignments()
    {
        return $this->hasMany(TrainerBooking::class, 'trainer_id');
    }

    public function trainerBookings()
    {
        return $this->hasMany(TrainerBooking::class, 'member_id');
    }

    public function boxingAssignments()
    {
        return $this->hasMany(BoxingBooking::class, 'trainer_id');
    }

    public function boxingBookings()
    {
        return $this->hasMany(BoxingBooking::class, 'member_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(MemberMembership::class, 'member_id');
    }

    public function point()
    {
        return $this->hasOne(Point::class);
    }
}
