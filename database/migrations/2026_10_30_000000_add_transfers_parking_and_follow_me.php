<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ring groups get a short number too, so phones can dial or transfer to them.
        Schema::table('ring_groups', function (Blueprint $table) {
            $table->string('extension', 5)->nullable()->after('name');
            $table->unique(['tenant_id', 'extension']);
        });

        // Extra legs of a call: transfers and rings to a person's mobile.
        Schema::table('calls', function (Blueprint $table) {
            $table->string('parent_sid', 64)->nullable()->index()->after('carrier_sid');
            $table->string('leg', 16)->nullable()->after('parent_sid'); // transfer | follow_me
        });

        // Follow-me: ring the person's own mobile as well.
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile', 16)->nullable()->after('extension');
            $table->timestamp('mobile_verified_at')->nullable()->after('mobile');
            $table->string('follow_me', 8)->default('off')->after('mobile_verified_at'); // off | together | after
            $table->unsignedSmallInteger('follow_me_delay')->default(10)->after('follow_me');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['mobile', 'mobile_verified_at', 'follow_me', 'follow_me_delay']));
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['parent_sid']);
            $table->dropColumn(['parent_sid', 'leg']);
        });
        Schema::table('ring_groups', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'extension']);
            $table->dropColumn('extension');
        });
    }
};
