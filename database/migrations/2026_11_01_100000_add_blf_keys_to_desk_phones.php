<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            // The colleagues whose busy lamps this phone shows, in key order (user ids).
            $table->json('blf_keys')->nullable()->after('mac');
        });
    }

    public function down(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            $table->dropColumn('blf_keys');
        });
    }
};
