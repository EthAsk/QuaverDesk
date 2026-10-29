<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Evidence that the operator accepted the safety and legal terms:
        // who, when, which version, from where. Never deleted by the app.
        Schema::create('acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('version', 32);
            $table->json('items');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('accepted_at');
            $table->index(['version']);
        });

        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->string('carrier_sid', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16); // outbound | inbound | internal
            $table->string('from', 64)->nullable();
            $table->string('to', 64)->nullable();
            $table->string('decision', 16)->nullable(); // client | number | emergency | reject
            $table->string('status', 24)->default('initiated');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
        Schema::dropIfExists('acknowledgements');
        Schema::dropIfExists('settings');
    }
};
