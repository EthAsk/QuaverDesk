<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a partner applicant gives so we can check they're a real business: address, and company number if they have one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('website');
            $table->string('registration_number', 40)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn(['address', 'registration_number']);
        });
    }
};
