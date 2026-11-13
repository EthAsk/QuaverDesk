<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Hosted service: a company a partner looks after, and how its pages credit them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_id')->nullable()->index()->after('domain');
            $table->string('partner_name', 120)->nullable()->after('partner_id');
            $table->boolean('partner_branding')->default(false)->after('partner_name'); // their name only, no "Powered by QuaverDesk"
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['partner_id']);
            $table->dropColumn(['partner_id', 'partner_name', 'partner_branding']);
        });
    }
};
