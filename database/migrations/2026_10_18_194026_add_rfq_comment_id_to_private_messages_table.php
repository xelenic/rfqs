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
        // The comment a message was sent for: someone @mentioned the
        // recipient in it on a closed RFQ (RfqComment::messageMentionedUsers()).
        // Null for a message written in Messages. Deleting the comment takes
        // the message with it.
        Schema::table('private_messages', function (Blueprint $table) {
            $table->foreignId('rfq_comment_id')->nullable()->after('recipient_id')->constrained('rfq_comments')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('private_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rfq_comment_id');
        });
    }
};
