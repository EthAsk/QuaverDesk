<?php

namespace App\Voice\FreeSwitch;

use App\Models\DeskPhone;
use App\Models\SipTrunk;
use App\Models\User;
use RuntimeException;

/**
 * Writes the parts of the FreeSWITCH configuration this app owns into
 * conf_dir (included by deploy/freeswitch/conf):
 *
 *   vars/quaverdesk.xml      SIP domain, webhook address and credentials, event socket password
 *   sofia/internal-tls.xml   TLS settings for desk phones and wss:// (empty when TLS is off)
 *   directory/users.xml      softphones and desk phones that may sign in
 *   gateways/trunks.xml      SIP trunks for calls to and from the phone network
 *   acl/trunks.xml           provider addresses allowed to send us calls
 *
 * Phones of every company are written to one SIP domain: their usernames are
 * unique across the service, and every call they make goes through the app,
 * which only ever routes within the caller's own company.
 *
 * Secrets are written as SIP digest hashes where FreeSWITCH allows it. No
 * value may contain "$": FreeSWITCH would expand it as a variable.
 */
class ConfigWriter
{
    private const STATE = '.state.json';

    public function __construct(
        private readonly string $dir,
        private readonly string $domain,
        /** @var array<string, string> preprocessor variables, e.g. qd_app_url */
        private readonly array $vars,
        private readonly bool $tls,
        /** @var array<int, string> hold music URL by company id, set on each of its phones */
        private readonly array $holdMusic = [],
    ) {
    }

    /**
     * @return array{gateways_changed: list<string>, users_removed: list<string>, users_rekeyed: list<string>}
     *         gateways FreeSWITCH must drop and reload, and phones to sign out (removed, or given a new password)
     */
    public function write(): array
    {
        $before = $this->state();

        $gateways = $this->gateways();
        $users = $this->users();

        // Group-readable: FreeSWITCH reads it through the folder's group (see docs/VOICE-SERVER.md).
        $this->put('vars/quaverdesk.xml', $this->vars(), 0640);
        $this->put('sofia/internal-tls.xml', $this->wrap($this->tls ? $this->tlsParams() : ''));
        $this->put('directory/users.xml', $this->wrap(implode('', $users)));
        $this->put('gateways/trunks.xml', $this->wrap(implode('', $gateways)));
        $this->put('acl/trunks.xml', $this->wrap($this->acl()));

        $hashes = array_map('sha1', $gateways);
        // Each phone's password hash, so a changed password signs out whoever is using the old one.
        $keys = array_map(fn (string $xml) => preg_match('/a1-hash" value="([0-9a-f]{32})"/', $xml, $m) ? sha1($m[1]) : '', $users);
        $this->put(self::STATE, json_encode(['gateways' => $hashes, 'users' => $keys]), 0600);

        $changed = [];
        foreach ($before['gateways'] ?? [] as $name => $hash) {
            if (($hashes[$name] ?? null) !== $hash) {
                $changed[] = $name;
            }
        }

        // Older state files listed user ids only.
        $beforeKeys = array_is_list($before['users'] ?? []) ? array_fill_keys($before['users'] ?? [], null) : $before['users'];
        $rekeyed = [];
        foreach ($beforeKeys as $id => $key) {
            if ($key !== null && isset($keys[$id]) && $keys[$id] !== $key) {
                $rekeyed[] = (string) $id;
            }
        }

        return [
            'gateways_changed' => $changed,
            'users_removed' => array_values(array_map('strval', array_diff(array_keys($beforeKeys), array_keys($users)))),
            'users_rekeyed' => $rekeyed,
        ];
    }

    private function vars(): string
    {
        $vars = ['qd_domain' => $this->domain] + $this->vars;

        return $this->wrap(implode('', array_map(
            fn ($k, $v) => '<X-PRE-PROCESS cmd="set" data="'.$this->attr($k.'='.$v).'"/>'."\n",
            array_keys($vars),
            $vars,
        )));
    }

    /** Desk phones over TLS on 5061, browsers over wss:// on 7443; certificates in FreeSWITCH's certs dir. */
    private function tlsParams(): string
    {
        return '<param name="tls" value="true"/>'
            .'<param name="tls-only" value="true"/>'
            .'<param name="tls-bind-params" value="transport=tls"/>'
            .'<param name="tls-sip-port" value="5061"/>'
            .'<param name="tls-cert-dir" value="$${certs_dir}"/>'
            .'<param name="tls-version" value="tlsv1.2"/>'
            .'<param name="wss-binding" value=":7443"/>'."\n";
    }

    /** @return array<string, string> keyed by SIP username */
    private function users(): array
    {
        $out = [];

        foreach (User::query()->orderBy('id')->get() as $user) {
            $id = $user->carrierIdentity();
            $out[$id] = $this->user($id, $user->sipSecret(), [
                'effective_caller_id_name' => self::name($user->name),
                // Shown to colleagues they call. Who is calling is taken from the SIP username, never this.
                'effective_caller_id_number' => $user->dialable(),
            ] + $this->holdMusicFor($user));
        }

        foreach (DeskPhone::query()->with('user')->orderBy('id')->get() as $phone) {
            if ($phone->user === null) {
                continue;
            }
            $out[$phone->sip_username] = $this->user($phone->sip_username, $phone->sip_password, [
                'effective_caller_id_name' => self::name($phone->user->name),
                'effective_caller_id_number' => $phone->user->dialable(),
                // Desk phones must encrypt their audio.
                'rtp_secure_media' => 'mandatory',
            ] + $this->holdMusicFor($phone->user));
        }

        return $out;
    }

    /**
     * What the other person hears when this phone puts them on hold: its
     * company's choice (the voice server plays the holding phone's hold_music).
     *
     * @return array<string, string>
     */
    private function holdMusicFor(User $user): array
    {
        $url = $this->holdMusic[$user->tenant_id] ?? null;

        return $url ? ['hold_music' => $url] : [];
    }

    /** @param array<string, string> $vars */
    private function user(string $id, string $password, array $vars): string
    {
        $this->assertSafe($id, '/^[A-Za-z0-9_-]{1,64}$/');

        $xml = '<user id="'.$this->attr($id).'"><params>'
            .'<param name="a1-hash" value="'.md5("{$id}:{$this->domain}:{$password}").'"/>'
            .'</params><variables>'
            .'<variable name="user_context" value="users"/>';
        foreach ($vars as $k => $v) {
            $xml .= '<variable name="'.$k.'" value="'.$this->attr($v).'"/>';
        }

        return $xml."</variables></user>\n";
    }

    /** @return array<string, string> keyed by gateway name */
    private function gateways(): array
    {
        $out = [];

        foreach (SipTrunk::query()->where('status', 'active')->orderBy('id')->get() as $trunk) {
            foreach (array_values($trunk->origination_uris) as $i => $uri) {
                $name = $trunk->gatewayNames()[$i];
                [$proxy, $transport] = self::proxy($uri);

                $params = [
                    'proxy' => $proxy,
                    'register-transport' => $transport,
                    // FreeSWITCH needs a username even for trunks that authenticate by address.
                    'username' => $trunk->auth_username ?: 'quaverdesk',
                    'password' => $trunk->auth_password ?: 'unused',
                    'register' => $trunk->registers() ? 'true' : 'false',
                    'caller-id-in-from' => 'true',
                    'context' => 'public',
                ];

                $xml = '<gateway name="'.$name.'">';
                foreach ($params as $k => $v) {
                    $xml .= '<param name="'.$k.'" value="'.$this->attr($v).'"/>';
                }
                // Offer carriers G.711 only; otherwise the leg copies the caller's codec (Opus from browsers).
                $xml .= '<variables><variable name="absolute_codec_string" value="PCMA,PCMU" direction="outbound"/></variables>';
                $out[$name] = $xml."</gateway>\n";
            }
        }

        return $out;
    }

    private function acl(): string
    {
        $xml = '';
        foreach (SipTrunk::query()->where('status', 'active')->orderBy('id')->get() as $trunk) {
            foreach ($trunk->inbound_ips as $entry) {
                $cidr = $entry['ip'].'/'.(int) $entry['prefix'];
                $this->assertSafe($cidr, '#^[0-9.]{7,15}/\d{1,2}$#');
                $xml .= '<node type="allow" cidr="'.$cidr.'"/>'."\n";
            }
        }

        return $xml;
    }

    /** "sip:sbc.example.net:5060;transport=tcp" → ["sbc.example.net:5060", "tcp"] */
    public static function proxy(string $uri): array
    {
        if (! preg_match('/^(sips?):(?:[^@]+@)?([A-Za-z0-9.-]+)(?::(\d{1,5}))?((?:;[A-Za-z0-9=._-]+)*)$/i', $uri, $m)) {
            throw new RuntimeException("Not a SIP address: {$uri}");
        }

        $transport = strtolower($m[1]) === 'sips' ? 'tls' : 'udp';
        if (preg_match('/;transport=(udp|tcp|tls)/i', $m[4] ?? '', $t)) {
            $transport = strtolower($t[1]);
        }

        return [$m[2].(isset($m[3]) && $m[3] !== '' ? ':'.$m[3] : ''), $transport];
    }

    private static function name(string $name): string
    {
        return trim(substr(preg_replace('/[^\pL\pN .\'-]+/u', ' ', $name) ?? '', 0, 60)) ?: 'QuaverDesk';
    }

    private function wrap(string $body): string
    {
        return "<include>\n<!-- Written by QuaverDesk. Do not edit: changes are overwritten. -->\n{$body}</include>\n";
    }

    private function attr(string $value): string
    {
        if (str_contains($value, '$')) {
            throw new RuntimeException('Voice server settings cannot contain "$".');
        }

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function assertSafe(string $value, string $pattern): void
    {
        if (! preg_match($pattern, $value)) {
            throw new RuntimeException("Unsafe value for the voice server: {$value}");
        }
    }

    /** @return array{gateways?: array<string, string>, users?: array<string, string>|list<string>} */
    private function state(): array
    {
        $path = $this->dir.'/'.self::STATE;

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    private function put(string $relative, string $contents, int $mode = 0640): void
    {
        $path = $this->dir.'/'.$relative;
        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0750, true)) {
            throw new RuntimeException('Cannot create '.dirname($path));
        }

        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Cannot write {$path}");
        }
        chmod($tmp, $mode);
        rename($tmp, $path);
    }
}
