<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * GM Assistant no longer adds client details or payment terms — they
     * submit (Rfq::recordGmAssistantPart()). The role's description says so,
     * unless someone has already written their own on the Roles screen.
     */
    private const BEFORE = 'Adds client details and payment terms before forwarding an approved RFQ to the General Manager for final approval.';

    private const AFTER = 'Submits an RFQ the Head of Business Development approved on to the General Manager for final approval, with a comment if needed.';

    public function up(): void
    {
        Role::query()->where('name', 'GM Assistant')->where('description', self::BEFORE)->update(['description' => self::AFTER]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Role::query()->where('name', 'GM Assistant')->where('description', self::AFTER)->update(['description' => self::BEFORE]);
    }
};
