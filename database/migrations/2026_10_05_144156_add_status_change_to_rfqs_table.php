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
        Schema::table('rfqs', function (Blueprint $table) {
            // Why, by whom and when an RFQ was last put on hold or cancelled —
            // or resumed — by Senior Operations. See Rfq::changeStatus().
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
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn(['status_reason', 'status_changed_at']);
        });
    }
};
