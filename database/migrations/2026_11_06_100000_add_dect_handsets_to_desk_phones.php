<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            // A handset on a DECT base with its own extension: the base (another desk phone) sets it up, as account and handset `handset`.
            $table->foreignId('base_id')->nullable()->after('user_id')->constrained('desk_phones')->cascadeOnDelete();
            $table->unsignedTinyInteger('handset')->nullable()->after('base_id');
            $table->unique(['base_id', 'handset']);
        });
    }

    public function down(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            $table->dropUnique(['base_id', 'handset']);
            $table->dropConstrainedForeignId('base_id');
            $table->dropColumn('handset');
        });
    }
};
