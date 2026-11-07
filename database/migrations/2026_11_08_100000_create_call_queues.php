<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('extension', 5)->nullable();
            $table->text('greeting')->nullable();                    // said once, when the caller joins
            $table->unsignedSmallInteger('ring_seconds')->default(20); // each time free agents are rung
            $table->unsignedSmallInteger('max_wait_seconds')->default(300); // then the caller goes to the overflow
            $table->boolean('announce_position')->default(true);
            $table->foreignId('hold_sound_id')->nullable()->constrained('sounds')->nullOnDelete(); // else the company's hold music
            $table->string('overflow', 40)->nullable();              // a destination; null plays the unavailable message
            $table->boolean('overflow_when_empty')->default(true);   // nobody logged in: straight to the overflow
            $table->timestamps();
            $table->unique(['tenant_id', 'extension']);
        });

        Schema::create('call_queue_user', function (Blueprint $table) {
            $table->foreignId('call_queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('logged_in')->default(true);
            $table->primary(['call_queue_id', 'user_id']);
        });

        // Each caller's time in a queue: their place in it, and how it ended.
        Schema::create('queue_callers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('call_queue_id')->constrained()->cascadeOnDelete();
            $table->string('carrier_sid', 64);
            $table->string('from', 64)->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('seen_at');                  // the last round it was in; see QueueCaller::scopeWaiting
            $table->timestamp('ringing_until')->nullable(); // while agents are being rung
            $table->unsignedSmallInteger('announced_position')->nullable();
            $table->timestamp('announced_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('outcome', 16)->nullable();     // answered | overflow | abandoned
            $table->timestamps();
            $table->unique(['call_queue_id', 'carrier_sid']);
            $table->index(['call_queue_id', 'ended_at', 'joined_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_callers');
        Schema::dropIfExists('call_queue_user');
        Schema::dropIfExists('call_queues');
    }
};
