<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trainer_pricing', function (Blueprint $table) {
            $table->dropForeign(['trainer_id']);
            $table->foreign('trainer_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('trainer_bookings', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropForeign(['trainer_id']);
            $table->foreign('member_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('trainer_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trainer_pricing', function (Blueprint $table) {
            $table->dropForeign(['trainer_id']);
            $table->foreign('trainer_id')->references('id')->on('trainers')->cascadeOnDelete();
        });

        Schema::table('trainer_bookings', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropForeign(['trainer_id']);
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('trainer_id')->references('id')->on('trainers')->cascadeOnDelete();
        });
    }
};
