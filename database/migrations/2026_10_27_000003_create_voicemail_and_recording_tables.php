<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('greeting');
            $table->unsignedSmallInteger('max_seconds')->default(120);
            $table->boolean('attach_audio')->default(true);
            $table->boolean('include_transcript')->default(true);
            $table->boolean('delete_after_send')->default(false);
            $table->json('extra_emails')->nullable();
            $table->timestamps();
        });

        // People who receive this mailbox's voicemail emails and can play its messages.
        Schema::create('mailbox_user', function (Blueprint $table) {
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['mailbox_id', 'user_id']);
        });

        // Where a person's own unanswered calls go.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('mailbox_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->boolean('record_calls')->default(false)->after('closed_destination');
        });

        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);                       // call | voicemail
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->string('carrier_sid', 64)->unique();   // voice server's id for the recording
            $table->text('upload_path')->nullable();       // uploaded audio waiting to be encrypted
            $table->string('from', 64)->nullable();
            $table->string('to', 64)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('path')->nullable();                // encrypted audio on the private disk
            $table->string('status', 16)->default('pending');  // pending | stored | failed | deleted
            $table->text('error')->nullable();
            // Personal data: stored encrypted (model casts).
            $table->longText('transcript')->nullable();
            $table->text('summary')->nullable();
            $table->text('action_items')->nullable();
            $table->string('sentiment', 16)->nullable();
            $table->string('ai_status', 16)->default('none');  // none | queued | done | failed
            $table->text('ai_error')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordings');
        Schema::table('phone_numbers', fn (Blueprint $t) => $t->dropColumn('record_calls'));
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('mailbox_id');
        });
        Schema::dropIfExists('mailbox_user');
        Schema::dropIfExists('mailboxes');
    }
};
