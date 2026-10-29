<?php

namespace Tests\Unit;

use App\Rules\AiEndpoint;
use PHPUnit\Framework\TestCase;

class AiEndpointTest extends TestCase
{
    public function test_keys_and_audio_only_travel_encrypted(): void
    {
        $this->assertTrue(AiEndpoint::allows('https://api.openai.com/v1'));
        $this->assertTrue(AiEndpoint::allows('https://ai.internal.example.co.uk'));
        $this->assertTrue(AiEndpoint::allows('http://localhost:11434/v1'));
        $this->assertTrue(AiEndpoint::allows('http://127.0.0.1:8080'));
        $this->assertTrue(AiEndpoint::allows('http://[::1]:8080'));

        $this->assertFalse(AiEndpoint::allows('http://api.openai.com/v1'));
        $this->assertFalse(AiEndpoint::allows('http://192.168.1.20:11434'));
        $this->assertFalse(AiEndpoint::allows('http://localhost.evil.example'));
        $this->assertFalse(AiEndpoint::allows('https://user:pass@api.example.com'));
        $this->assertFalse(AiEndpoint::allows('ftp://api.example.com'));
        $this->assertFalse(AiEndpoint::allows('not a url'));
    }
}
