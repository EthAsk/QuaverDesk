<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audio the business uploads or records: greetings, menus, hold music.
        Schema::create('sounds', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('file', 40)->unique(); // random name under storage/app/sounds
            $table->decimal('seconds', 7, 1);
            $table->timestamps();
        });

        // A recording plays instead of the greeting text; the text stays as the fallback.
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->foreignId('greeting_sound_id')->nullable()->after('greeting')->constrained('sounds')->nullOnDelete();
        });
        Schema::table('ivr_menus', function (Blueprint $table) {
            $table->foreignId('greeting_sound_id')->nullable()->after('greeting')->constrained('sounds')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ivr_menus', fn (Blueprint $table) => $table->dropConstrainedForeignId('greeting_sound_id'));
        Schema::table('mailboxes', fn (Blueprint $table) => $table->dropConstrainedForeignId('greeting_sound_id'));
        Schema::dropIfExists('sounds');
    }
};
