<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_phones', function (Blueprint $table) {
            // Set up automatically (provisioning): the handset's make and MAC address,
            // and the admin password we give its web page.
            $table->string('brand', 16)->nullable()->after('allowed_ips');
            $table->string('mac', 12)->nullable()->after('brand');
            $table->text('admin_password')->nullable()->after('mac');
            $table->timestamp('provisioned_at')->nullable()->after('admin_password');
            $table->string('provisioned_ip', 45)->nullable()->after('provisioned_at');
            $table->string('provisioned_agent', 255)->nullable()->after('provisioned_ip');
            $table->unique(['tenant_id', 'mac']);
        });

        // What happened to each phone, and who did it, for anyone to check later.
        Schema::create('desk_phone_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('desk_phone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->string('phone_label', 60)->nullable(); // kept after the phone is removed
            $table->string('ip', 45)->nullable();
            $table->string('detail', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_phone_events');
        Schema::table('desk_phones', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'mac']);
            $table->dropColumn(['brand', 'mac', 'admin_password', 'provisioned_at', 'provisioned_ip', 'provisioned_agent']);
        });
    }
};
