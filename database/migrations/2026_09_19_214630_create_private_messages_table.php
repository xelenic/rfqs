<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per private message between two users — the "Message" button
     * on a person's hover card. Only the sender and the recipient ever see
     * it; read_at is when the recipient opened the conversation.
     */
    public function up(): void
    {
        Schema::create('private_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The inbox's unread count, and one conversation's thread.
            $table->index(['recipient_id', 'read_at']);
            $table->index(['sender_id', 'recipient_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('private_messages');
    }
};
