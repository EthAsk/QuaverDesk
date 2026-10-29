<?php

namespace Tests\Unit;

use App\Domain\Licensing\Feature;
use App\Domain\Licensing\InvalidLicence;
use App\Domain\Licensing\LicenceCodec;
use App\Domain\Licensing\Plan;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class LicenceCodecTest extends TestCase
{
    public function test_issued_key_verifies_with_matching_public_key(): void
    {
        $keys = LicenceCodec::generateKeyPair();
        $key = LicenceCodec::issue($keys['secret'], 'Acme Ltd', Plan::Pro, 25, new DateTimeImmutable('+1 year'));

        $licence = LicenceCodec::verify($key, $keys['public']);

        $this->assertSame('Acme Ltd', $licence->licensee);
        $this->assertSame(Plan::Pro, $licence->plan);
        $this->assertSame(25, $licence->maxUsers());
        $this->assertTrue($licence->allows(Feature::AiSentiment));
    }

    public function test_key_from_another_signer_is_rejected(): void
    {
        $real = LicenceCodec::generateKeyPair();
        $forger = LicenceCodec::generateKeyPair();
        $key = LicenceCodec::issue($forger['secret'], 'Mallory', Plan::Pro, 999, null);

        $this->expectException(InvalidLicence::class);
        LicenceCodec::verify($key, $real['public']);
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $keys = LicenceCodec::generateKeyPair();
        $key = LicenceCodec::issue($keys['secret'], 'Acme Ltd', Plan::Plus, 5, null);

        [$prefix, $payload, $sig] = explode('.', $key);
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $data['plan'] = 'pro';
        $data['seats'] = 500;
        $forged = rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        $this->expectException(InvalidLicence::class);
        LicenceCodec::verify("{$prefix}.{$forged}.{$sig}", $keys['public']);
    }

    public function test_expired_key_is_rejected(): void
    {
        $keys = LicenceCodec::generateKeyPair();
        $key = LicenceCodec::issue($keys['secret'], 'Acme Ltd', Plan::Plus, 5, new DateTimeImmutable('2026-01-01'));

        $this->expectException(InvalidLicence::class);
        LicenceCodec::verify($key, $keys['public'], new DateTimeImmutable('2026-06-01'));
    }

    public function test_garbage_is_rejected(): void
    {
        $keys = LicenceCodec::generateKeyPair();

        foreach (['', 'hello', 'VP1.a.b', 'VP1..', 'XX1.abc.def', 'VP1.@@@.###'] as $junk) {
            try {
                LicenceCodec::verify($junk, $keys['public']);
                $this->fail("Accepted junk key: {$junk}");
            } catch (InvalidLicence) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_free_plan_makes_real_calls_for_three_users_with_one_of_each(): void
    {
        $free = [Feature::PhoneNumbers, Feature::Routing, Feature::ByoTrunk];
        foreach (Feature::cases() as $feature) {
            $this->assertSame(in_array($feature, $free, true), Plan::Free->allows($feature), $feature->value);
        }
        $this->assertSame(3, Plan::Free->userCap());
        $this->assertSame(1, Plan::Free->limit('phone_numbers'));
        $this->assertSame(1, Plan::Free->limit('ring_groups'));
        $this->assertNull(Plan::Plus->limit('ring_groups'));
    }

    public function test_plus_includes_ai_basics_and_desk_phones_but_not_pro_extras(): void
    {
        $this->assertTrue(Plan::Plus->allows(Feature::AiTranscripts));
        $this->assertTrue(Plan::Plus->allows(Feature::AiSummaries));
        $this->assertTrue(Plan::Plus->allows(Feature::ByoTrunk));
        $this->assertTrue(Plan::Plus->allows(Feature::DeskPhones));
        $this->assertFalse(Plan::Plus->allows(Feature::AiSentiment));
        $this->assertFalse(Plan::Free->allows(Feature::DeskPhones));
    }

    public function test_expired_key_can_still_prove_identity_for_renewal_only(): void
    {
        $keys = LicenceCodec::generateKeyPair();
        $key = LicenceCodec::issue($keys['secret'], 'Acme', Plan::Plus, 5, new DateTimeImmutable('2026-01-01'));
        $later = new DateTimeImmutable('2026-03-01');

        $this->assertSame('Acme', LicenceCodec::verify($key, $keys['public'], $later, allowExpired: true)->licensee);

        $this->expectException(InvalidLicence::class);
        LicenceCodec::verify($key, $keys['public'], $later);
    }
}
