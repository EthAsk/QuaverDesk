<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('hubspot_call_id', 40)->nullable(); // the call's record in HubSpot, once logged
        });
    }

    public function down(): void
    {
        Schema::table('calls', fn (Blueprint $table) => $table->dropColumn('hubspot_call_id'));
    }
};
