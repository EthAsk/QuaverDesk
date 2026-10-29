<?php

namespace App\Console\Commands;

use App\Jobs\WarmPrompts;
use App\Support\RecordingPolicy;
use App\Tenancy\Tenancy;
use App\Voice\Prompts;
use Illuminate\Console\Command;
use Throwable;

/** Makes the audio for every prompt now. Run after changing TTS_* settings (a new voice makes new audio). */
class VoicePrompts extends Command
{
    protected $signature = 'voice:prompts';

    protected $description = 'Generate the spoken audio for all greetings and system messages';

    public function handle(Prompts $prompts, RecordingPolicy $recording, Tenancy $tenancy): int
    {
        // Each company's greetings, in each company's chosen voice.
        $failed = false;
        $tenancy->each(function () use ($prompts, $recording, &$failed) {
            $failed = $this->generate($prompts, $recording) === self::FAILURE || $failed;
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function generate(Prompts $prompts, RecordingPolicy $recording): int
    {
        if (! $prompts->makesFiles()) {
            $this->warn('TTS_DRIVER uses the voice server\'s built-in voice, so there is nothing to generate. Set TTS_DRIVER=piper for a natural voice.');

            return self::SUCCESS;
        }

        $texts = WarmPrompts::all($recording);
        $bar = $this->output->createProgressBar(count($texts));
        foreach ($texts as $text) {
            try {
                $prompts->warm($text);
            } catch (Throwable $e) {
                $this->newLine();
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info(count($texts).' prompts ready.');

        return self::SUCCESS;
    }
}
