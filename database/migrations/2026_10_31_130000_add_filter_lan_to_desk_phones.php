<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            // Off: a locked phone may also sign in from any local (private) address. On: only from its list.
            $table->boolean('filter_lan')->default(false)->after('allowed_ips');
        });
    }

    public function down(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            $table->dropColumn('filter_lan');
        });
    }
};
