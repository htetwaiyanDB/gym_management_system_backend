<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_scans') || ! Schema::hasTable('rfid_attendances')) {
            return;
        }

        DB::table('attendance_scans')
            ->select('user_id')
            ->selectRaw('DATE(scanned_at) as attendance_date')
            ->selectRaw("MIN(CASE WHEN action = 'check_in' THEN scanned_at END) as check_in_time")
            ->selectRaw("MAX(CASE WHEN action = 'check_out' THEN scanned_at END) as check_out_time")
            ->whereIn('action', ['check_in', 'check_out'])
            ->groupBy('user_id', DB::raw('DATE(scanned_at)'))
            ->orderBy('user_id')
            ->orderBy(DB::raw('DATE(scanned_at)'))
            ->chunk(500, function ($dailyAttendances) {
                foreach ($dailyAttendances as $dailyAttendance) {
                    if (! $dailyAttendance->check_in_time) {
                        continue;
                    }

                    $alreadyMigrated = DB::table('rfid_attendances')
                        ->where('user_id', $dailyAttendance->user_id)
                        ->whereDate('attendance_date', $dailyAttendance->attendance_date)
                        ->exists();

                    if ($alreadyMigrated) {
                        continue;
                    }

                    DB::table('rfid_attendances')->insertOrIgnore([
                        'user_id' => $dailyAttendance->user_id,
                        'attendance_date' => $dailyAttendance->attendance_date,
                        'check_in_time' => $dailyAttendance->check_in_time,
                        'check_out_time' => $dailyAttendance->check_out_time,
                        'source' => 'legacy',
                        'created_at' => $dailyAttendance->check_in_time,
                        'updated_at' => $dailyAttendance->check_out_time ?? $dailyAttendance->check_in_time,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Historical attendance backfills are intentionally preserved on rollback.
    }
};
