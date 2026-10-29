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

    // AI analyses one company may ask for in an hour (each costs money).
    'ai_per_hour' => (int) env('TENANCY_AI_PER_HOUR', 60),

    // Voice server requests for one company in a minute: a flood of calls to
    // one company is turned away before it slows the others.
    'webhooks_per_minute' => (int) env('TENANCY_WEBHOOKS_PER_MINUTE', 600),

];
