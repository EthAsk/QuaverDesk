<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            // Where the phone may sign in and call from (comma-separated CIDRs); null: anywhere.
            $table->string('allowed_ips', 255)->nullable()->after('sip_password');
        });
    }

    public function down(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            $table->dropColumn('allowed_ips');
        });
    }
};
