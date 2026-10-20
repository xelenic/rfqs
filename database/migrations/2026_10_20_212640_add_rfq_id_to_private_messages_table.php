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
        // The RFQ a task message is about (App\TaskMessages): a part assigned
        // to the recipient, sent back to them, and so on — the way back to it
        // from the conversation. Null for a message written in Messages.
        // Deleting the RFQ takes the message with it.
        Schema::table('private_messages', function (Blueprint $table) {
            $table->foreignId('rfq_id')->nullable()->after('rfq_comment_id')->constrained('rfqs')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('private_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rfq_id');
        });
    }
};
