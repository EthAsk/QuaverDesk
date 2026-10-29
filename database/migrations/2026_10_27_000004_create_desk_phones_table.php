<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desk_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('sip_username', 64)->unique();
            $table->text('sip_password');                 // encrypted (model cast)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_phones');
    }
};
