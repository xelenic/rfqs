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
        // GM Assistant starts a part before working on it, as Data Entry does:
        // when and who (their time on it counts from then, credited to them),
        // and while it's paused, since when, why and the document it's waiting
        // on. See Rfq::startGmAssistantPart() / pauseGmAssistantPart().
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->timestamp('gm_assistant_started_at')->nullable()->after('head_of_bd_approved_by');
            $table->foreignId('gm_assistant_started_by')->nullable()->after('gm_assistant_started_at')->constrained('users')->nullOnDelete();
            $table->timestamp('gm_assistant_paused_at')->nullable()->after('gm_assistant_started_by');
            $table->text('gm_assistant_pause_reason')->nullable()->after('gm_assistant_paused_at');
            $table->string('gm_assistant_pause_reference')->nullable()->after('gm_assistant_pause_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gm_assistant_started_by');
            $table->dropColumn(['gm_assistant_started_at', 'gm_assistant_paused_at', 'gm_assistant_pause_reason', 'gm_assistant_pause_reference']);
        });
    }
};
