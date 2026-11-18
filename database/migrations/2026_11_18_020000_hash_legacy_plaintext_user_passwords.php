<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->select(['id', 'password'])
            ->whereNotNull('password')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    if (! Hash::isHashed($user->password)) {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['password' => Hash::make($user->password)]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Password hashing is intentionally irreversible.
    }
};
