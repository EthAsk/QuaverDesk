<?php

/*
| Providers offered in the bring-your-own-trunk wizard.
|
| These presets deliberately contain NO technical defaults (hosts, IP ranges,
| ports). Providers give each customer their own SBC addresses, and guessed
| values would silently break calls. Add verified defaults per provider only
| from that provider's own documentation.
*/

return [
    'gamma' => ['name' => 'Gamma'],
    'bt' => ['name' => 'BT'],
    'simwood' => ['name' => 'Simwood'],
    'voiceflex' => ['name' => 'Voiceflex'],
    'twilio' => ['name' => 'Twilio Elastic SIP Trunking'],
    'other' => ['name' => 'Another provider'],
];
