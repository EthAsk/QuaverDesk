<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The short number colleagues dial to reach someone (201, 202 …).
        Schema::table('users', function (Blueprint $table) {
            $table->string('extension', 5)->nullable()->unique()->after('email');
        });

        // Everyone already here gets one, in the order they joined.
        $next = 201;
        foreach (DB::table('users')->orderBy('id')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['extension' => (string) $next++]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['extension']);
            $table->dropColumn('extension');
        });
    }
};
