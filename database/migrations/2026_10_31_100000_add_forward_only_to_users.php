<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // No sign-in and no phones: calls ring their mobile or go to voicemail, which is emailed.
            // Free, so not counted against the plan's users.
            $table->boolean('forward_only')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('forward_only');
        });
    }
};
