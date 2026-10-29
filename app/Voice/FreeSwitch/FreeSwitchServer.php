<?php

namespace App\Voice\FreeSwitch;

use App\Domain\Calls\CallScript;
use App\Models\Sound;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use App\Voice\Prompts;
use App\Voice\VoiceServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FreeSwitchServer implements VoiceServer
{
    /** @param array<string, mixed> $config config('voip.freeswitch') */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function softphoneSettings(User $user): array
    {
        $domain = $this->domain();
        if (! $domain || empty($this->config['websocket_url'])) {
            throw new RuntimeException('The voice server is not configured: set VOICE_DOMAIN and VOICE_WEBSOCKET_URL.');
        }

        return [
            'websocket_url' => $this->config['websocket_url'],
            'uri' => 'sip:'.$user->carrierIdentity().'@'.$domain,
            'username' => $user->carrierIdentity(),
            'password' => $user->sipSecret(),
            'stun_url' => $this->config['stun_url'] ?: null,
        ];
    }

    /** FreeSWITCH sends the shared secret with HTTP Basic auth on every request. */
    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');

        return strlen($secret) >= 16
            && $request->getUser() === 'freeswitch'
            && hash_equals($secret, (string) $request->getPassword());
    }

    public function render(CallScript $script): string
    {
        // Made when needed: the voice depends on the company's settings, and the
        // company isn't known until the request is under way.
        return (new HttapiRenderer((string) $this->domain(), app(Prompts::class)))->render($script);
    }

    public function responseContentType(): string
    {
        return 'text/xml';
    }

    public function domain(): ?string
    {
        $domain = (string) ($this->config['domain'] ?? '');

        return preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$|^localhost$/i', $domain) ? strtolower($domain) : null;
    }

    public function sync(): void
    {
        if (empty($this->config['conf_dir']) || ! $this->domain()) {
            return; // not set up yet (or in tests)
        }

        $secret = (string) $this->config['webhook_secret'];
        if (strlen($secret) < 16 || ! ctype_alnum($secret)) {
            throw new RuntimeException('VOICE_WEBHOOK_SECRET must be at least 16 letters and digits.');
        }

        // Each company's hold music, read as that company before writing every company's phones.
        $tenancy = app(Tenancy::class);
        $holdMusic = [];
        $tenancy->each(function (Tenant $tenant) use (&$holdMusic) {
            if ($url = Sound::holdMusic()?->url()) {
                $holdMusic[$tenant->id] = $url;
            }
        });

        // The voice server's config covers every company's phones and trunks at once.
        $changes = $tenancy->everywhere(fn () => (new ConfigWriter($this->config['conf_dir'], (string) $this->domain(), [
            'qd_app_url' => rtrim((string) ($this->config['app_url'] ?: config('app.url')), '/'),
            'qd_webhook_auth' => 'freeswitch:'.$secret,
            'qd_esl_password' => (string) ($this->config['esl_password'] ?: bin2hex(random_bytes(16))),
            'qd_esl_port' => (string) (int) $this->config['esl_port'],
            'qd_external_ip' => (string) ($this->config['external_ip'] ?: 'auto-nat'),
            // The default for anything without its own: phones carry their company's.
            'hold_music' => ($tenancy->enabled() ? null : reset($holdMusic)) ?: 'silence',
        ], (bool) $this->config['tls'], $holdMusic))->write());

        if (empty($this->config['esl_password'])) {
            Log::warning('Voice server config written but not reloaded: VOICE_ESL_PASSWORD is not set.');

            return;
        }

        $esl = new EventSocket($this->config['esl_host'], (int) $this->config['esl_port'], $this->config['esl_password']);
        try {
            $esl->api('reloadxml');
            $esl->api('reloadacl');
            foreach ($changes['gateways_changed'] as $gateway) {
                $esl->api("sofia profile external killgw {$gateway}");
            }
            $esl->api('sofia profile external rescan');
            foreach ([...$changes['users_removed'], ...$changes['users_rekeyed']] as $user) {
                $esl->api("sofia profile internal flush_inbound_reg {$user}@{$this->domain()}");
            }
        } finally {
            $esl->close();
        }
    }

    public function parkedSlots(string $lot): array
    {
        if (! preg_match('/^qd-park-\d{1,9}$/', $lot)) {
            throw new RuntimeException('Not a parking lot.');
        }

        // <lots><lot name="qd-park-1"><extension uuid="…">3</extension></lot></lots>
        $info = $this->api(fn (EventSocket $esl) => $esl->api('valet_info '.$lot));
        if (! preg_match('/<lot name="'.preg_quote($lot, '/').'">(.*?)<\/lot>/s', $info, $m)) {
            return [];
        }
        preg_match_all('/<extension[^>]*>(\d{1,2})<\/extension>/', $m[1], $slots);

        return array_values(array_unique(array_map('intval', $slots[1])));
    }

    public function callNumber(string $e164, string $trunk, string $callerId, string $callId, string $url): void
    {
        foreach ([[$e164, '/^\+?[0-9]{6,15}$/'], [$trunk, '/^[a-z0-9-]{1,40}$/'], [$callerId, '/^\+?[0-9]{3,15}$/'],
            [$callId, '/^[0-9a-f-]{36}$/'], [$url, '#^https?://[A-Za-z0-9.:/_?=&%-]+$#']] as [$value, $pattern]) {
            if (! preg_match($pattern, $value)) {
                throw new RuntimeException('Refusing an unsafe value in a call.');
            }
        }

        $vars = "origination_uuid={$callId},origination_caller_id_number={$callerId},origination_caller_id_name={$callerId},originate_timeout=30";
        $reply = $this->api(fn (EventSocket $esl) => $esl->api(
            "bgapi originate {{$vars}}sofia/gateway/{$trunk}/{$e164} &httapi({httapi_profile=quaverdesk,url={$url}})"
        ));
        if (str_starts_with(trim($reply), '-ERR')) {
            throw new RuntimeException('The voice server could not place the call.');
        }
    }

    /** @param callable(EventSocket): string $command */
    private function api(callable $command): string
    {
        if (empty($this->config['esl_password'])) {
            throw new RuntimeException('The voice server event socket is not set up: set VOICE_ESL_PASSWORD.');
        }

        $esl = new EventSocket($this->config['esl_host'], (int) $this->config['esl_port'], $this->config['esl_password']);
        try {
            return $command($esl);
        } finally {
            $esl->close();
        }
    }
}
