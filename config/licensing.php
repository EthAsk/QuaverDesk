<?php

return [

    /*
    | Where an install asks for a renewed key each day. Only the current licence
    | key is sent. Leave empty to turn automatic renewal checks off.
    */
    'server_url' => env('LICENCE_SERVER_URL'),

    /*
    | Licence prices, used when an admin is offered the next band up. Sold by
    | site size, not per user: a key's seats are the top of its band. GBP a
    | month, the full price (no VAT is added). A year costs `annual_months`
    | months. Bigger sites than the last band get a quote.
    */
    'bands' => [5, 10, 25, 50, 100],

    'monthly_gbp' => [
        'plus' => [5 => 15, 10 => 29, 25 => 49, 50 => 89, 100 => 149],
        'pro' => [5 => 29, 10 => 55, 25 => 99, 50 => 179, 100 => 299],
    ],

    'annual_months' => 10,
];
