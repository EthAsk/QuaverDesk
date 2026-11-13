<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->string('scope', 10)->default('read');        // read | calls
            $table->json('allowed_user_ids')->nullable();         // calls tokens: who they may ring
            $table->json('allowed_prefixes')->nullable();         // calls tokens: countries besides the UK, such as +353
            $table->unsignedSmallInteger('per_minute')->default(10);
            $table->unsignedSmallInteger('per_hour')->default(60);
            $table->unsignedSmallInteger('daily_minutes')->default(120);
        });

        // Every request to place a call, kept as the audit trail.
        Schema::create('api_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_name');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to', 20);
            $table->string('prefix', 8)->nullable();              // the country the number is in, as a dialling prefix
            $table->string('idempotency_key', 80)->nullable();
            $table->string('call_id', 40)->nullable();             // the voice server's id for the first leg
            $table->string('status', 12)->default('requested');   // requested | answered | refused
            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['api_token_id', 'created_at']);
            $table->index(['api_token_id', 'idempotency_key']);
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->foreignId('api_call_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calls', fn (Blueprint $t) => $t->dropConstrainedForeignId('api_call_id'));
        Schema::dropIfExists('api_calls');
        Schema::table('api_tokens', fn (Blueprint $t) => $t->dropColumn(['scope', 'allowed_user_ids', 'allowed_prefixes', 'per_minute', 'per_hour', 'daily_minutes']));
    }
};
