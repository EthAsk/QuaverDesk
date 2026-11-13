<?php

namespace App\Sso;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OpenID Connect sign-in (authorization code with PKCE) for Microsoft Entra
 * and Google Workspace.
 *
 * The ID token is taken straight from the provider's token endpoint over TLS
 * with our client secret, which OpenID Connect Core 3.1.3.7 accepts in place of
 * checking its signature. Its issuer, audience, expiry and nonce are checked,
 * and the company's own directory (Microsoft) or Workspace domain (Google) must
 * match, so people outside the company can't sign in even with a valid account.
 */
class Oidc
{
    public function __construct(private readonly string $provider, private readonly array $config, private readonly string $redirectUri)
    {
    }

    /** @return array{url: string, state: string, nonce: string, verifier: string} */
    public function begin(): array
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $query = [
            'client_id' => $this->config['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        $query += $this->provider === 'google' ? ['hd' => $this->config['restrict'], 'prompt' => 'select_account'] : ['prompt' => 'select_account'];

        return ['url' => $this->authorizeUrl().'?'.http_build_query($query), 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier];
    }

    /**
     * Swaps the code for who the person is.
     *
     * @return array{subject: string, email: string}
     */
    public function identify(string $code, string $verifier, string $nonce): array
    {
        $response = Http::asForm()->timeout(15)->withoutRedirecting()->post($this->tokenUrl(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code_verifier' => $verifier,
        ]);
        if (! $response->successful() || ! is_string($response->json('id_token'))) {
            throw new RuntimeException('The provider would not confirm the sign-in. Check the client id and secret.');
        }

        return $this->check($this->claims($response->json('id_token')), $nonce);
    }

    /** @return array{subject: string, email: string} */
    public function check(array $claims, string $nonce): array
    {
        $issuers = $this->provider === 'microsoft'
            ? ['https://login.microsoftonline.com/'.$this->config['restrict'].'/v2.0']
            : ['https://accounts.google.com', 'accounts.google.com'];

        if (! in_array($claims['iss'] ?? null, $issuers, true)
            || ($claims['aud'] ?? null) !== $this->config['client_id']
            || (int) ($claims['exp'] ?? 0) < time() - 60
            || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new RuntimeException('The sign-in could not be verified.');
        }

        if ($this->provider === 'microsoft') {
            if (strtolower((string) ($claims['tid'] ?? '')) !== $this->config['restrict']) {
                throw new RuntimeException('That account is not in this company\'s Microsoft directory.');
            }
            $email = $claims['email'] ?? $claims['preferred_username'] ?? null;
        } else {
            if (($claims['email_verified'] ?? false) !== true || strtolower((string) ($claims['hd'] ?? '')) !== $this->config['restrict']) {
                throw new RuntimeException('That account is not in this company\'s Google Workspace.');
            }
            $email = $claims['email'] ?? null;
        }
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The provider did not say which email address this is.');
        }

        return ['subject' => $claims['sub'], 'email' => strtolower($email)];
    }

    /** @return array<string, mixed> */
    private function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $payload = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;

        return is_array($payload) ? $payload : throw new RuntimeException('The provider sent something unreadable.');
    }

    private function authorizeUrl(): string
    {
        return $this->provider === 'microsoft'
            ? "https://login.microsoftonline.com/{$this->config['restrict']}/oauth2/v2.0/authorize"
            : 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    private function tokenUrl(): string
    {
        return $this->provider === 'microsoft'
            ? "https://login.microsoftonline.com/{$this->config['restrict']}/oauth2/v2.0/token"
            : 'https://oauth2.googleapis.com/token';
    }
}
