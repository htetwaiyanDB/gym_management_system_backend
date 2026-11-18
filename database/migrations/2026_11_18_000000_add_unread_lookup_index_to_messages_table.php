<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('messages', 'messages_recipient_read_sender_index')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->index(
                    ['recipient_id', 'read_at', 'sender_id'],
                    'messages_recipient_read_sender_index'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('messages', 'messages_recipient_read_sender_index')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropIndex('messages_recipient_read_sender_index');
            });
        }
    }
};
