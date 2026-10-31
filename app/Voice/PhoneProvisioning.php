<?php

namespace App\Voice;

use App\Models\DeskPhone;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Automatic set-up for desk phones. Each company has one provisioning address
 * (with a random token) and login; a phone given that address asks for its
 * own file by MAC address and gets its SIP account (TLS, compulsory SRTP), a
 * new password for every login to its web page, UK time, *00 for voicemail, and busy
 * lamp (BLF) keys for the colleagues chosen for it. Its keys are only managed
 * once someone has chosen them (blf_keys not null), so keys set up on a phone
 * by hand aren't wiped; after that, keys 2 onwards are the busy lamps and the
 * rest are cleared. Key 1 stays the phone's own line.
 *
 * Files phones ask for:
 *   Yealink      <mac>.cfg      (also y0000000000xx.cfg, .boot: not served)
 *   Fanvil       <mac>.cfg      (newer firmware's XML format; also f0<model>.cfg: not served)
 *   Grandstream  cfg<mac>.xml   (GRP series, named settings)
 * Yealink and Fanvil ask for the same name: the phone registered with that MAC says which.
 */
class PhoneProvisioning
{
    public const USERNAME = 'phones';
    private const SETTING = 'provisioning';
    private const NTP = 'uk.pool.ntp.org';
    private const RECHECK_MINUTES = 1440;

    /** @return array{token: string, username: string, password: string} */
    public function login(): array
    {
        $saved = Setting::get(self::SETTING);
        if (! is_array($saved) || empty($saved['token']) || empty($saved['password'])) {
            return $this->newLogin();
        }

        return ['token' => $saved['token'], 'username' => self::USERNAME, 'password' => Crypt::decryptString($saved['password'])];
    }

    /** A new address and password: phones set up with the old ones stop getting settings until given these. */
    public function newLogin(): array
    {
        $token = bin2hex(random_bytes(16));
        $password = Str::password(24, symbols: false);
        Setting::put(self::SETTING, ['token' => $token, 'password' => Crypt::encryptString($password)]);

        return ['token' => $token, 'username' => self::USERNAME, 'password' => $password];
    }

    /** The address to give phones (ends in /: they add their file name). On a company's own web address. */
    public function url(): string
    {
        return url('/provision/'.$this->login()['token']).'/';
    }

    /** True if the request carries this company's address and login. */
    public function allows(string $token, ?string $user, ?string $password): bool
    {
        $login = $this->login();

        return hash_equals($login['token'], $token)
            && hash_equals(self::USERNAME, (string) $user)
            && hash_equals($login['password'], (string) $password);
    }

    /**
     * The MAC address and file type a phone asked for, or null for any other
     * file (model-wide files and firmware are not served).
     *
     * @return array{0: string, 1: 'cfg'|'xml'}|null
     */
    public static function parse(string $file): ?array
    {
        $file = strtolower($file);

        return match (true) {
            (bool) preg_match('/^([0-9a-f]{12})\.cfg$/', $file, $m) => [$m[1], 'cfg'],
            (bool) preg_match('/^cfg([0-9a-f]{12})(?:\.xml)?$/', $file, $m) => [$m[1], 'xml'],
            default => null,
        };
    }

    /** Which brands answer to a file type. */
    public static function brandsFor(string $type): array
    {
        return $type === 'xml' ? ['grandstream'] : ['yealink', 'fanvil'];
    }

    /** @return array{0: string, 1: string} content type and file */
    public function render(DeskPhone $phone): array
    {
        $domain = (string) (DeskPhone::service()['domain'] ?? '');
        $admin = $phone->admin_password ?: tap($phone->newAdminPassword(), fn () => $phone->save());
        $v = [
            'domain' => $domain,
            'user' => $phone->sip_username,
            'password' => $phone->sip_password,
            'name' => $phone->user?->name ?? $phone->label,
            'label' => trim(($phone->user?->extension ? $phone->user->extension.' ' : '').($phone->user?->name ?? $phone->label)),
            'admin' => $admin,
            'mac' => $phone->mac,
        ];
        // null: leave the phone's keys alone. Otherwise one per colleague: the address it watches and dials, and its label.
        $keys = $phone->blf_keys === null ? null : $phone->blfColleagues()->take(DeskPhone::MAX_BLF_KEYS)
            ->map(fn ($u) => ['value' => $u->blfId(), 'label' => trim($u->name.' '.$u->extension)])->all();

        return match ($phone->brand) {
            'yealink' => ['text/plain; charset=UTF-8', $this->yealink($v, $keys)],
            'grandstream' => ['application/xml; charset=UTF-8', $this->grandstream($v, $keys)],
            'fanvil' => ['application/xml; charset=UTF-8', $this->fanvil($v, $keys)],
        };
    }

    /**
     * @param  array<string, string>  $v
     * @param  ?list<array{value: string, label: string}>  $keys
     */
    private function yealink(array $v, ?array $keys): string
    {
        $c = fn (string $s) => trim(preg_replace('/[\x00-\x1f\x7f#]+/', ' ', $s) ?? '');
        $lines = [
            '#!version:1.0.0.1',
            '## '.config('app.name').': settings for this phone. Changes made on the phone are replaced at its next check.',
            'account.1.enable = 1',
            'account.1.label = '.$c($v['label']),
            'account.1.display_name = '.$c($v['name']),
            'account.1.auth_name = '.$v['user'],
            'account.1.user_name = '.$v['user'],
            'account.1.password = '.$v['password'],
            'account.1.sip_server.1.address = '.$v['domain'],
            'account.1.sip_server.1.port = 5061',
            'account.1.sip_server.1.transport_type = 2', // TLS
            'account.1.srtp_encryption = 2', // compulsory
            'voice_mail.number.1 = *00',
            // Every web login gets the phone's password, so none is left on its default (user/user, var/var).
            'static.security.user_name.admin = admin',
            'static.security.user_password = admin:'.$v['admin'],
            'static.security.user_name.user = user',
            'static.security.user_password = user:'.$v['admin'],
            'static.security.user_name.var = var',
            'static.security.user_password = var:'.$v['admin'],
            'static.security.trust_certificates = 1',
            'static.auto_provision.repeat.enable = 1',
            'static.auto_provision.repeat.minutes = '.self::RECHECK_MINUTES,
            'sip.notify_reboot_enable = 0', // check-sync fetches settings; reboots only if asked
            'local_time.time_zone = 0',
            'local_time.time_zone_name = United Kingdom(London)',
            'local_time.ntp_server1 = '.self::NTP,
            'lang.gui = English',
            'lang.wui = English',
        ];
        if ($keys !== null) {
            array_push($lines, 'linekey.1.type = 15', 'linekey.1.line = 1'); // its own line
            for ($i = 0; $i < DeskPhone::MAX_BLF_KEYS; $i++) {
                $n = $i + 2;
                $key = $keys[$i] ?? null;
                array_push($lines,
                    "linekey.{$n}.type = ".($key ? 16 : 0), // BLF, or nothing
                    "linekey.{$n}.line = 1",
                    "linekey.{$n}.value = ".($key['value'] ?? ''),
                    "linekey.{$n}.label = ".$c($key['label'] ?? ''),
                );
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $v
     * @param  ?list<array{value: string, label: string}>  $keys
     */
    private function grandstream(array $v, ?array $keys): string
    {
        $items = [
            'account.1.enable' => 'Yes',
            'account.1.name' => $v['label'],
            'account.1.sip.server.1.address' => $v['domain'].':5061',
            'account.1.sip.userid' => $v['user'],
            'account.1.sip.subscriber.userid' => $v['user'],
            'account.1.sip.subscriber.password' => $v['password'],
            'account.1.sip.subscriber.name' => $v['name'],
            'account.1.sip.transport' => '2', // TLS
            'account.1.audio.srtpMode' => 'EnabledAndForced',
            'account.1.sip.voicemail.number' => '*00',
            'users.admin.password' => $v['admin'],
            'users.user.password' => $v['admin'], // the end-user login, otherwise 123
            'security.validate.serverCertificate' => 'Yes',
            'dateTime.ntp.server.1' => self::NTP,
            'dateTime.timezone' => 'GMT+0BST-1,M3.5.0/01:00:00,M10.5.0/02:00:00', // London
            'provisioning.auto.mode' => 'YesUpgradeMin',
            'provisioning.auto.minute' => (string) self::RECHECK_MINUTES,
        ];
        if ($keys !== null) {
            // A ringing lamp calls the colleague: its pickup prefix (** by default) would park the call here.
            $items['account.1.sip.blf.callPickup.forcePrefix'] = 'Disabled';
            $items += ['pks.vpk.1.keyMode' => 'Line', 'pks.vpk.1.account' => '0'];
            // Virtual keys must run in sequence (one skipped drops those after it), so unused ones are written as None.
            for ($i = 0; $i < DeskPhone::MAX_BLF_KEYS; $i++) {
                $n = $i + 2;
                $key = $keys[$i] ?? null;
                $items += [
                    "pks.vpk.{$n}.keyMode" => $key ? 'BLF' : 'None',
                    "pks.vpk.{$n}.account" => '0',
                    "pks.vpk.{$n}.description" => mb_substr($key['label'] ?? '', 0, 32),
                    "pks.vpk.{$n}.value" => $key['value'] ?? '',
                ];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<gs_provision version="1">'."\n".'  <config version="2">'."\n";
        foreach ($items as $name => $value) {
            $xml .= '    <item name="'.$name.'">'.self::xml($value).'</item>'."\n";
        }

        return $xml."  </config>\n</gs_provision>\n";
    }

    /**
     * @param  array<string, string>  $v
     * @param  ?list<array{value: string, label: string}>  $keys
     */
    private function fanvil(array $v, ?array $keys): string
    {
        $x = fn (string $s) => self::xml($s);
        $dss = $keys === null ? '' : self::fanvilKeys($keys);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <sysConf>
                <sip>
                    <line index="1">
                        <PhoneNumber>{$x($v['user'])}</PhoneNumber>
                        <DisplayName>{$x($v['name'])}</DisplayName>
                        <RegisterAddr>{$x($v['domain'])}</RegisterAddr>
                        <RegisterPort>5061</RegisterPort>
                        <RegisterUser>{$x($v['user'])}</RegisterUser>
                        <RegisterPswd>{$x($v['password'])}</RegisterPswd>
                        <EnableReg>1</EnableReg>
                        <Transport>3</Transport>
                        <EnableOSRTP>0</EnableOSRTP>
                        <MediaCrypto>1</MediaCrypto>
                        <MWINum>*00</MWINum>
                    </line>
                </sip>
                <phone>
                    <date>
                        <EnableSNTP>1</EnableSNTP>
                        <SNTPServer>{$x(self::NTP)}</SNTPServer>
                        <TimeZone>0</TimeZone>
                        <TimeZoneName>UTC+0</TimeZoneName>
                        <Enable_DST>1</Enable_DST>
                    </date>
                </phone>
                <web>
                    <account index="1">
                        <Name>admin</Name>
                        <Password>{$x($v['admin'])}</Password>
                        <Level>10</Level>
                    </account>
                    <account index="2">
                        <Name>guest</Name>
                        <Password>{$x($v['admin'])}</Password>
                        <Level>5</Level>
                    </account>
                </web>
            {$dss}</sysConf>

            XML;
    }

    /**
     * The screen's function keys, six a page: the phone's own line, then a
     * busy lamp per colleague (number@line/b, "a" for attended transfer when
     * pressed during a call), then empty keys.
     *
     * @param  list<array{value: string, label: string}>  $keys
     */
    private static function fanvilKeys(array $keys): string
    {
        $slots = [['type' => 2, 'value' => 'SIP1', 'title' => '']];
        for ($i = 0; $i < DeskPhone::MAX_BLF_KEYS; $i++) {
            $key = $keys[$i] ?? null;
            $slots[] = $key ? ['type' => 1, 'value' => $key['value'].'@1/ba', 'title' => $key['label']] : ['type' => 0, 'value' => '', 'title' => ''];
        }

        $xml = "    <dsskey>\n";
        foreach (array_chunk($slots, 6) as $p => $page) {
            $xml .= '        <internal index="'.($p + 1).'">'."\n";
            foreach ($page as $k => $slot) {
                $xml .= '            <Fkey index="'.($k + 1).'"><Type>'.$slot['type'].'</Type><Value>'.self::xml($slot['value'])
                    .'</Value><Title>'.self::xml($slot['title']).'</Title></Fkey>'."\n";
            }
            $xml .= "        </internal>\n";
        }

        return $xml."    </dsskey>\n";
    }

    private static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
