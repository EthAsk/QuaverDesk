<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_trunks', function (Blueprint $table) {
            // Calls both ways over TLS with encrypted audio (SRTP); calls without it are refused.
            $table->boolean('secure')->default(false)->after('register');
        });
    }

    public function down(): void
    {
        Schema::table('sip_trunks', function (Blueprint $table) {
            $table->dropColumn('secure');
        });
    }
};
