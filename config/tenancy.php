<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy (operator-hosted service only)
    |--------------------------------------------------------------------------
    |
    | Off for every self-hosted install: there is one company, created
    | automatically, and everything behaves as before. Only the operator's own
    | hosted service turns this on. No licence key can enable it, and the
    | licence forbids anyone else turning it on (COMMERCIAL-TERMS.md 1.4–1.6).
    |
    | When on, each company is found from the web address: a subdomain of
    | base_domain (quaver.quaverdesk.app) or the company's own domain.
    |
    */

    'enabled' => (bool) env('QUAVERDESK_MULTI_TENANT', false),

    'base_domain' => strtolower((string) env('TENANCY_BASE_DOMAIN', '')),

    // Where the operator console lives, e.g. operator.example.co.uk. Only
    // operator accounts work there, and the console exists nowhere else.
    'operator_host' => strtolower((string) env('TENANCY_OPERATOR_HOST', '')),

    // How long a support visit to a company may last, in minutes.
    'support_minutes' => 60,

    // Backstops across every company together, whatever their plans allow:
    // calls to phone numbers at once, and their minutes in a UK day.
    'max_concurrent_calls' => (int) env('TENANCY_MAX_CONCURRENT_CALLS', 30),
    'max_minutes_per_day' => (int) env('TENANCY_MAX_MINUTES_PER_DAY', 3000),

    // Fair use of shared work. Each company's slow background jobs (AI
    // analysis, generating spoken prompts) run at most this often, so one
    // company's backlog waits its turn rather than delaying everyone else.
    'jobs_per_minute' => (int) env('TENANCY_JOBS_PER_MINUTE', 6),

    /*
    | Calls to phone numbers (see CallCharges). A plan's monthly minutes cover
    | UK calls: incoming, and outgoing to UK landlines (01, 02, 03, 080) and
    | mobiles (07, except 070 and 076). With charges on, UK calls carry on
    | past them, up to overage_percent more, charged per minute for where
    | the call went; other numbers (international, and UK 084, 087, 070, 076
    | and 09) never use the minutes and are charged per minute, by the longest
    | matching prefix, and can only be called if they have a rate. Every rate
    | is Twilio's cost x markup (CallRates). Each call is rounded up to the
    | minute. Off: the minutes are a hard
    | stop for outgoing calls, and other numbers aren't charged.
    */
    'call_charges' => [
        'enabled' => (bool) env('TENANCY_CALL_CHARGES', false),
        'overage_percent' => (int) env('TENANCY_OVERAGE_PERCENT', 100),
        // What we charge is Twilio's rate for the destination (resources/call-rates/outbound.php, refreshed
        // with bin/call-rates) x markup, converted at usd_to_gbp (Twilio bills in dollars: review it now and then).
        'markup' => (float) env('TENANCY_CALL_MARKUP', 1.5),
        'usd_to_gbp' => (float) env('TENANCY_USD_GBP', 0.76),
        'rates_file' => resource_path('call-rates/outbound.php'),
        // Twilio's UK incoming minute (origination), USD.
        'inbound_usd' => 0.0060,
        // Our own rates in pence a minute, which win over the rate card when at least as specific:
        // e.g. '+33' => 6 (France). A destination the card doesn't have and isn't listed here is refused.
        'other_pence' => [],
    ],

    // AI analyses one company may ask for in an hour (each costs money).
    'ai_per_hour' => (int) env('TENANCY_AI_PER_HOUR', 60),

    // Voice server requests for one company in a minute: a flood of calls to
    // one company is turned away before it slows the others.
    'webhooks_per_minute' => (int) env('TENANCY_WEBHOOKS_PER_MINUTE', 600),

];
