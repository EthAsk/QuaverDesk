<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sip_trunks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 32);
            $table->json('inbound_ips');                       // provider's SBC addresses (CIDR)
            $table->json('origination_uris');                  // where we send outgoing calls, in order
            $table->string('auth_username')->nullable();       // for providers that authenticate by password
            $table->text('auth_password')->nullable();         // encrypted (model cast)
            $table->boolean('register')->default(false);       // sign in to the provider (registration trunks)
            $table->string('status', 16)->default('active');   // active | error
            $table->text('last_error')->nullable();
            $table->boolean('default_outbound')->default(false);
            $table->timestamps();
        });

        Schema::create('ring_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('strategy', 16)->default('simultaneous'); // simultaneous | in_order
            $table->unsignedSmallInteger('ring_seconds')->default(20);
            $table->string('fallback', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('ring_group_user', function (Blueprint $table) {
            $table->foreignId('ring_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['ring_group_id', 'user_id']);
        });

        Schema::create('ivr_menus', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('greeting');
            $table->json('options');                           // {"1": "ring_group:2", "2": "user:5"}
            $table->unsignedSmallInteger('timeout_seconds')->default(6);
            $table->string('fallback', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('e164', 20)->unique();
            $table->string('label')->nullable();
            $table->string('source', 16)->default('trunk');
            $table->foreignId('sip_trunk_id')->nullable()->constrained()->nullOnDelete();
            $table->string('destination', 40)->default('hangup');
            $table->json('hours')->nullable();
            $table->string('closed_destination', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_numbers');
        Schema::dropIfExists('ivr_menus');
        Schema::dropIfExists('ring_group_user');
        Schema::dropIfExists('ring_groups');
        Schema::dropIfExists('sip_trunks');
    }
};
