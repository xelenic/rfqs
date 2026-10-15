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
        // A Sourcing member's usual person in Data Entry, set by an Admin on
        // their page: the one their "Assign to Data Entry" picks to begin
        // with. Null: anyone in Data Entry. See User::defaultDataEntryUser().
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_data_entry_user_id')->nullable()->after('avatar_path')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_data_entry_user_id');
        });
    }
};
