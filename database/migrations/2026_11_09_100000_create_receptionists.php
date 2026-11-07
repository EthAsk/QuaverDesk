<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receptionists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('business')->nullable();
            $table->text('greeting');
            $table->text('about')->nullable();
            $table->json('routes')->nullable();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fallback')->nullable();
            $table->unsignedTinyInteger('max_turns')->default(6);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receptionists');
    }
};
