<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which trunk carried each outside call, and which trunks the operator supplies (for usage and billing). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->foreignId('sip_trunk_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
        Schema::table('sip_trunks', function (Blueprint $table) {
            $table->boolean('supplied_by_operator')->default(false)->after('default_outbound');
        });
    }

    public function down(): void
    {
        Schema::table('sip_trunks', fn (Blueprint $table) => $table->dropColumn('supplied_by_operator'));
        Schema::table('calls', fn (Blueprint $table) => $table->dropConstrainedForeignId('sip_trunk_id'));
    }
};
