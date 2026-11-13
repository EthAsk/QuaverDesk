<?php

namespace App\Console\Commands;

use App\Support\Health;
use Illuminate\Console\Command;

/** Run after every update by quaverdesk-deploy: exits 1 if anything is broken (warnings are listed but don't fail it). */
class HealthCheck extends Command
{
    protected $signature = 'quaverdesk:health';

    protected $description = 'Check this install is in good shape';

    public function handle(Health $health): int
    {
        $results = $health->run();
        foreach ($results as $r) {
            $mark = ['ok' => '  ok ', 'warn' => 'warn ', 'fail' => 'FAIL '][$r['status']];
            $this->line("{$mark} {$r['name']}: {$r['detail']}");
        }

        return Health::failed($results) ? self::FAILURE : self::SUCCESS;
    }
}
