<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Desk phones only: no sign-in or softphone. Counts as one of the plan's users.
            $table->boolean('phone_only')->default(false)->after('forward_only');
            // May call colleagues (and 999/112), but no outside numbers.
            $table->boolean('internal_only')->default(false)->after('phone_only');
            // Off: unanswered calls end instead of going to their voicemail box (the box and its messages stay).
            $table->boolean('voicemail')->default(true)->after('internal_only');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_only', 'internal_only', 'voicemail']);
        });
    }
};
