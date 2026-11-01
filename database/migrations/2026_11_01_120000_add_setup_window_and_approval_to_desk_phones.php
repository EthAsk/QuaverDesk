<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            // Until then, the phone's next settings download is accepted from anywhere (and its address trusted).
            $table->timestamp('setup_until')->nullable()->after('provisioned_agent');
            // Where later downloads may come from without an admin approving them.
            $table->string('trusted_ip', 45)->nullable()->after('setup_until');
            // The last download held back for an admin to approve.
            $table->string('waiting_ip', 45)->nullable()->after('trusted_ip');
            $table->string('waiting_agent')->nullable()->after('waiting_ip');
            $table->timestamp('waiting_at')->nullable()->after('waiting_agent');
        });

        // Phones that already set themselves up keep working from where they last asked;
        // ones that haven't yet get a day to do so.
        DB::table('desk_phones')->whereNotNull('provisioned_at')->update(['trusted_ip' => DB::raw('provisioned_ip')]);
        DB::table('desk_phones')->whereNull('provisioned_at')->whereNotNull('brand')->update(['setup_until' => now()->addDay()]);
    }

    public function down(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            $table->dropColumn(['setup_until', 'trusted_ip', 'waiting_ip', 'waiting_agent', 'waiting_at']);
        });
    }
};
