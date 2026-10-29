<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What each hosted company may use, and the sizes needed to enforce it.
 * An unguessable part in each trunk's gateway name, so a call that
 * arrives on a trunk's own registration proves which trunk it is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan', 16)->default('plus')->after('status');
            $table->unsignedInteger('seats')->default(5)->after('plan');
            $table->unsignedInteger('max_numbers')->default(2)->after('seats');
            $table->unsignedInteger('monthly_minutes')->default(1000)->after('max_numbers'); // outside calls
            $table->unsignedInteger('concurrent_calls')->default(4)->after('monthly_minutes');
            $table->unsignedInteger('storage_mb')->default(1024)->after('concurrent_calls');
        });

        Schema::table('sip_trunks', function (Blueprint $table) {
            $table->string('gateway_token', 12)->nullable()->after('id');
        });
        foreach (DB::table('sip_trunks')->pluck('id') as $id) {
            DB::table('sip_trunks')->where('id', $id)->update(['gateway_token' => bin2hex(random_bytes(6))]);
        }

        foreach (['recordings', 'sounds'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('bytes')->default(0);
            });
        }
        foreach (DB::table('recordings')->whereNotNull('path')->get(['id', 'path']) as $row) {
            $file = storage_path('app/private/'.$row->path);
            DB::table('recordings')->where('id', $row->id)->update(['bytes' => is_file($file) ? filesize($file) : 0]);
        }
        foreach (DB::table('sounds')->get(['id', 'file']) as $row) {
            $file = storage_path('app/sounds/'.$row->file.'.wav');
            DB::table('sounds')->where('id', $row->id)->update(['bytes' => is_file($file) ? filesize($file) : 0]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['plan', 'seats', 'max_numbers', 'monthly_minutes', 'concurrent_calls', 'storage_mb']));
        Schema::table('sip_trunks', fn (Blueprint $t) => $t->dropColumn('gateway_token'));
        foreach (['recordings', 'sounds'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('bytes'));
        }
    }
};
