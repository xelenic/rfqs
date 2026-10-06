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
        Schema::table('rfq_user', function (Blueprint $table) {
            // A part of a split stopped on its own — on hold or cancelled
            // (App\Models\Rfq::ON_HOLD / CANCELLED), null while it's going
            // ahead — with why, by whom and when. See
            // App\Models\Rfq::changePartStatus().
            $table->string('status')->nullable()->after('bd_reference_code');
            $table->text('status_reason')->nullable()->after('status');
            $table->foreignId('status_changed_by')->nullable()->after('status_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable()->after('status_changed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rfq_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn(['status', 'status_reason', 'status_changed_at']);
        });
    }
};
