<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hosted companies paid for with a Stripe subscription (not through a
 * partner): which subscription pays for a company (the licence server's
 * record), the reference that makes creating one idempotent (on the hosted
 * service), and call charges already added to an invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('billing_reference', 64)->nullable()->unique()->after('partner_branding');
        });

        Schema::create('hosted_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_subscription_id', 64)->unique();
            $table->string('stripe_customer_id', 64)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index(); // the company's id on the hosted service
            $table->string('slug', 64)->nullable();
            $table->string('company');
            $table->string('owner_name')->nullable();
            $table->string('owner_email');
            $table->string('plan', 16);
            $table->unsignedSmallInteger('package');
            $table->unsignedInteger('price_pence')->nullable(); // an agreed price instead of the list price
            $table->string('source', 16)->default('checkout'); // checkout (bought online) or operator (set up by us)
            $table->string('status', 16)->default('active'); // active, past_due, cancelled, needs_setup
            $table->string('error')->nullable();
            $table->timestamps();
        });

        Schema::create('hosted_call_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hosted_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->unsignedInteger('amount_pence');
            $table->string('stripe_invoice_item_id', 64)->nullable();
            $table->timestamps();
            $table->unique(['hosted_subscription_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosted_call_bills');
        Schema::dropIfExists('hosted_subscriptions');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('billing_reference'));
    }
};
