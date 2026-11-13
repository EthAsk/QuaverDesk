<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('caller_name', 80)->nullable(); // who the CRM says is calling
        });
    }

    public function down(): void
    {
        Schema::table('calls', fn (Blueprint $table) => $table->dropColumn('caller_name'));
    }
};
