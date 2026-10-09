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
        // Photos and files attached to a comment on an RFQ's thread — a
        // comment of anyone's own, or the reason an action gave (a return, a
        // reject, a hold...). The file itself is kept on the private disk, at
        // path. rfq_id as well, so clearing an RFQ's data clears these with it
        // (app:clear-rfq-data). See App\Models\RfqCommentAttachment.
        Schema::create('rfq_comment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_comment_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rfq_comment_attachments');
    }
};
