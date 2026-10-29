<?php

namespace App\Domain\Licensing;

use DateTimeImmutable;
use JsonException;

/**
 * Licence keys are Ed25519-signed JSON:  VP1.<base64url payload>.<base64url signature>
 *
 * Only the licensor holds the secret key. Installs hold the
 * public key, so anyone can verify a key but nobody else can mint one.
 * Removing or bypassing this check is prohibited by the Elastic License 2.0.
 */
final class LicenceCodec
{
    private const PREFIX = 'VP1';

    /**
     * The licensor's public key, built in so an install can't be pointed at a
     * key someone else made. Only the public half is ever stored here.
     */
    public const PUBLIC_KEY = 'uv8xHESD+ugX+IbWHl+XbLbyWzIPQpZnEjuA8MC01uk=';

    /**
     * The key installs trust. LICENCE_PUBLIC_KEY only counts on a development
     * machine (APP_ENV local or testing), for working on licensing itself.
     */
    public static function trustedPublicKey(?string $appEnv, ?string $override): string
    {
        if (in_array($appEnv, ['local', 'testing'], true) && ! empty($override)) {
            return $override;
        }

        return self::PUBLIC_KEY;
    }

    /** @return array{public: string, secret: string} base64-encoded keys */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    public static function issue(
        string $secretKeyB64,
        string $licensee,
        Plan $plan,
        int $seats,
        ?DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $issuedAt = null,
    ): string {
        if ($plan === Plan::Free) {
            throw new InvalidLicence('Free plan needs no licence key.');
        }
        if ($seats < 1) {
            throw new InvalidLicence('Seats must be at least 1.');
        }

        $secret = self::decodeKey($secretKeyB64, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'secret');

        $payload = json_encode([
            'id' => bin2hex(random_bytes(8)),
            'licensee' => $licensee,
            'plan' => $plan->value,
            'seats' => $seats,
            'iat' => ($issuedAt ?? new DateTimeImmutable())->getTimestamp(),
            'exp' => $expiresAt?->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        $signature = sodium_crypto_sign_detached(self::PREFIX.'.'.self::b64url($payload), $secret);

        return self::PREFIX.'.'.self::b64url($payload).'.'.self::b64url($signature);
    }

    /**
     * @param  bool  $allowExpired  only for renewal: lets an expired but genuine key prove who is asking
     */
    public static function verify(string $key, string $publicKeyB64, ?DateTimeImmutable $now = null, bool $allowExpired = false): Licence
    {
        $public = self::decodeKey($publicKeyB64, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public');

        $parts = explode('.', trim($key));
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            throw new InvalidLicence('This is not a valid licence key.');
        }

        $payloadJson = self::unb64url($parts[1]);
        $signature = self::unb64url($parts[2]);

        if ($payloadJson === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidLicence('This is not a valid licence key.');
        }

        if (! sodium_crypto_sign_verify_detached($signature, self::PREFIX.'.'.$parts[1], $public)) {
            throw new InvalidLicence('The licence key signature is invalid.');
        }

        try {
            $data = json_decode($payloadJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidLicence('The licence key is corrupted.');
        }

        $plan = Plan::tryFrom((string) ($data['plan'] ?? ''));
        if ($plan === null || $plan === Plan::Free || ! is_int($data['seats'] ?? null) || $data['seats'] < 1) {
            throw new InvalidLicence('The licence key is corrupted.');
        }

        $expiresAt = isset($data['exp']) ? new DateTimeImmutable('@'.(int) $data['exp']) : null;
        $now ??= new DateTimeImmutable();
        if (! $allowExpired && $expiresAt !== null && $expiresAt <= $now) {
            throw new InvalidLicence('This licence expired on '.$expiresAt->format('j M Y').'.');
        }

        return new Licence(
            (string) ($data['id'] ?? ''),
            (string) ($data['licensee'] ?? ''),
            $plan,
            $data['seats'],
            new DateTimeImmutable('@'.(int) ($data['iat'] ?? 0)),
            $expiresAt,
        );
    }

    private static function decodeKey(string $b64, int $length, string $kind): string
    {
        $raw = base64_decode(trim($b64), true);
        if ($raw === false || strlen($raw) !== $length) {
            throw new InvalidLicence("The licence {$kind} key is missing or malformed.");
        }

        return $raw;
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64url(string $s): ?string
    {
        if ($s === '' || preg_match('/[^A-Za-z0-9_-]/', $s)) {
            return null;
        }
        $decoded = base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
