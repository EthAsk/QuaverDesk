<?php

namespace Tests\Feature;

use App\Models\SipTrunk;
use App\Models\User;
use App\Voice\FreeSwitch\ConfigWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class ConfigWriterTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/freeswitch-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function write(array $vars = ['qd_app_url' => 'https://app.test']): array
    {
        return (new ConfigWriter($this->dir, 'voice.test', $vars, tls: true))->write();
    }

    private function trunk(array $overrides = []): SipTrunk
    {
        return SipTrunk::create($overrides + [
            'name' => 'HQ', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.10', 'prefix' => 32]],
            'origination_uris' => ['sip:sbc.example.net:5061;transport=tls'], 'status' => 'active',
        ]);
    }

    public function test_users_are_written_with_hashed_passwords(): void
    {
        $user = User::factory()->create(['name' => 'Ann <script>']);
        $this->write();

        $xml = file_get_contents($this->dir.'/directory/users.xml');
        $secret = $user->fresh()->sipSecret();
        $this->assertStringContainsString('<user id="user-'.$user->id.'">', $xml);
        $this->assertStringContainsString(md5("user-{$user->id}:voice.test:{$secret}"), $xml);
        $this->assertStringNotContainsString($secret, $xml);
        $this->assertStringContainsString('value="Ann  script"', $xml);
        $this->assertStringContainsString('<variable name="effective_caller_id_number" value="'.$user->extension.'"/>', $xml);
        $this->assertSame('0640', substr(sprintf('%o', fileperms($this->dir.'/vars/quaverdesk.xml')), -4));
    }

    public function test_trunks_become_gateways_and_an_address_allow_list(): void
    {
        $trunk = $this->trunk();
        $this->write();

        $gateways = file_get_contents($this->dir.'/gateways/trunks.xml');
        $this->assertStringContainsString('<gateway name="'.$trunk->gatewayNames()[0].'"><param name="proxy" value="sbc.example.net:5061"/><param name="register-transport" value="tls"/>', $gateways);
        $this->assertStringContainsString('<param name="register" value="false"/>', $gateways);
        $this->assertStringContainsString('absolute_codec_string" value="PCMA,PCMU"', $gateways);
        $this->assertStringContainsString('<node type="allow" cidr="203.0.113.10/32"/>', file_get_contents($this->dir.'/acl/trunks.xml'));
        $this->assertStringContainsString('tls-sip-port', file_get_contents($this->dir.'/sofia/internal-tls.xml'));
    }

    public function test_changed_gateways_and_removed_users_are_reported(): void
    {
        $trunk = $this->trunk();
        $user = User::factory()->create();
        $this->assertSame(['gateways_changed' => [], 'users_removed' => [], 'users_rekeyed' => []], $this->write());

        $trunk->update(['origination_uris' => ['sip:sbc2.example.net']]);
        $user->delete();

        $this->assertSame(['gateways_changed' => [$trunk->gatewayNames()[0]], 'users_removed' => ["user-{$user->id}"], 'users_rekeyed' => []], $this->write());
    }

    public function test_dollar_signs_are_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->write(['qd_app_url' => 'https://app.test/${evil}']);
    }

    public function test_a_new_phone_password_signs_that_phone_out(): void
    {
        $user = User::factory()->create();
        $user->sipSecret();
        $this->write();

        $user->rotateSipSecret();

        $this->assertSame(["user-{$user->id}"], $this->write()['users_rekeyed']);
        $this->assertSame([], $this->write()['users_rekeyed'], 'only reported once');
    }
}
