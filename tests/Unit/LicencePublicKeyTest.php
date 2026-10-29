<?php

namespace Tests\Unit;

use App\Domain\Licensing\LicenceCodec;
use PHPUnit\Framework\TestCase;

class LicencePublicKeyTest extends TestCase
{
    public function test_production_installs_only_trust_the_built_in_key(): void
    {
        $theirs = LicenceCodec::generateKeyPair()['public'];

        $this->assertSame(LicenceCodec::PUBLIC_KEY, LicenceCodec::trustedPublicKey('production', $theirs));
        $this->assertSame(LicenceCodec::PUBLIC_KEY, LicenceCodec::trustedPublicKey(null, $theirs));
        $this->assertSame($theirs, LicenceCodec::trustedPublicKey('local', $theirs), 'developers can test licensing');
    }
}
