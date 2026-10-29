<?php

namespace Tests\Unit;

use App\Voice\Prompts;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class PromptsTest extends TestCase
{
    private static function prompts(array $config): Prompts
    {
        return new Prompts($config + ['engine' => 'flite', 'voice' => 'slt', 'command' => []]);
    }

    private static function command(Prompts $prompts): array
    {
        return (new ReflectionProperty(Prompts::class, 'command'))->getValue($prompts);
    }

    public function test_piper_runs_the_chosen_voice(): void
    {
        $prompts = self::prompts(['driver' => 'piper', 'piper' => ['binary' => '/opt/piper/piper', 'voice' => '/opt/piper/en_GB-cori-high.onnx']]);

        $this->assertTrue($prompts->makesFiles());
        $this->assertSame(['/opt/piper/piper', '--model', '/opt/piper/en_GB-cori-high.onnx', '--output-file', '{out}'], array_slice(self::command($prompts), 0, 5));
    }

    public function test_piper_without_a_voice_falls_back_to_the_voice_server(): void
    {
        $prompts = self::prompts(['driver' => 'piper', 'piper' => ['binary' => 'piper', 'voice' => null]]);

        $this->assertFalse($prompts->makesFiles());
        $this->assertSame(['speak', 'Hello.'], $prompts->for('Hello.'));
    }

    public function test_built_in_voice_makes_no_files(): void
    {
        $this->assertFalse(self::prompts(['driver' => 'freeswitch'])->makesFiles());
    }

    public function test_kokoro_runs_the_bundled_script_with_the_chosen_voice_and_pace(): void
    {
        $prompts = self::prompts([
            'driver' => 'kokoro',
            'speed' => 1.1,
            'kokoro' => ['python' => '/opt/kokoro/bin/python', 'models' => '/opt/kokoro', 'voice' => 'bm_george'],
        ]);

        $command = self::command($prompts);
        $this->assertTrue($prompts->makesFiles());
        $this->assertSame('/opt/kokoro/bin/python', $command[0]);
        $this->assertStringEndsWith('/bin/kokoro-tts', $command[1]);
        $this->assertSame(['--model-dir', '/opt/kokoro', '--voice', 'bm_george', '--speed', '1.1', '--out', '{out}'], array_slice($command, 2));
    }

    public function test_pace_is_kept_within_a_sensible_range(): void
    {
        $prompts = self::prompts(['driver' => 'piper', 'speed' => 5, 'piper' => ['binary' => 'piper', 'voice' => '/v.onnx']]);

        $command = self::command($prompts);
        $this->assertSame('0.77', $command[array_search('--length-scale', $command, true) + 1]); // 1 / 1.3
    }
}
