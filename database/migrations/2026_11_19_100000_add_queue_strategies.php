<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->string('strategy', 20)->default('ring_all');
            $table->unsignedBigInteger('last_agent_id')->nullable(); // for round robin: who was rung last
        });
        Schema::table('call_queue_user', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(1); // 1 is first choice
        });
        Schema::table('queue_callers', function (Blueprint $table) {
            $table->json('rung_agents')->nullable(); // agents already rung for this caller, so the next round tries someone else
        });
    }

    public function down(): void
    {
        Schema::table('queue_callers', fn (Blueprint $t) => $t->dropColumn('rung_agents'));
        Schema::table('call_queue_user', fn (Blueprint $t) => $t->dropColumn('priority'));
        Schema::table('call_queues', fn (Blueprint $t) => $t->dropColumn(['strategy', 'last_agent_id']));
    }
};
