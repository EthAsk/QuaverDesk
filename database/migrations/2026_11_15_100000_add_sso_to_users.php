<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Who the identity provider says this person is, remembered after their first single sign-on.
            $table->string('sso_provider', 20)->nullable();
            $table->string('sso_subject')->nullable();
            $table->index(['sso_provider', 'sso_subject']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['sso_provider', 'sso_subject']);
            $table->dropColumn(['sso_provider', 'sso_subject']);
        });
    }
};
