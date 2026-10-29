<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Companies. Every company-owned table gets a tenant_id; an existing install's
 * data all belongs to its one company.
 */
return new class extends Migration
{
    /** Tables owned by a company (pivot tables follow their parents). */
    private const OWNED = ['users', 'acknowledgements', 'calls', 'sip_trunks', 'ring_groups', 'ivr_menus',
        'phone_numbers', 'mailboxes', 'recordings', 'desk_phones', 'sounds'];

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 32)->unique();       // subdomain on the hosted service
            $table->string('domain')->nullable()->unique(); // the company's own web address, if any
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        $hasData = DB::table('users')->exists() || DB::table('settings')->exists();
        $tenant = $hasData
            ? DB::table('tenants')->insertGetId(['name' => config('app.name'), 'slug' => 'default', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()])
            : null;

        foreach (self::OWNED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            });
            if ($tenant) {
                DB::table($name)->update(['tenant_id' => $tenant]);
            }
        }

        // Extensions are unique within a company, not across the service.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['extension']);
            $table->unique(['tenant_id', 'extension']);
        });

        // Settings were keyed by name alone; now by company and name.
        Schema::rename('settings', 'settings_old');
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
        if ($tenant) {
            foreach (DB::table('settings_old')->get() as $row) {
                DB::table('settings')->insert(['tenant_id' => $tenant, 'key' => $row->key, 'value' => $row->value,
                    'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
            }
        }
        Schema::drop('settings_old');
    }

    public function down(): void
    {
        Schema::rename('settings', 'settings_new');
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        $first = DB::table('tenants')->orderBy('id')->value('id');
        foreach (DB::table('settings_new')->where('tenant_id', $first)->get() as $row) {
            DB::table('settings')->insert(['key' => $row->key, 'value' => $row->value, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
        }
        Schema::drop('settings_new');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'extension']);
            $table->unique(['extension']);
        });
        foreach (self::OWNED as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('tenant_id'));
        }
        Schema::drop('tenants');
    }
};
