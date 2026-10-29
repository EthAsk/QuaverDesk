<?php

namespace App\Domain\Telephony;

use Closure;

/**
 * Decides what an outbound call request from a softphone becomes.
 *
 * Safety rules, in order:
 *  1. Emergency numbers (999, 112) are never blocked by fraud rules. If the
 *     install has a phone line they are dialled; if not, the caller
 *     hears a clear message telling them to use another phone.
 *  2. Colleagues (user-<id>, or their extension such as 201) and ring groups
 *     (by extension) can always be called, on every plan. Extensions start 2–8
 *     so they can never be mistaken for 999, a UK short code (1xx) or a UK
 *     number (0…). ** parks a call; **1 to **9 park on, or pick up from, that slot.
 *  3. International and premium-rate destinations are blocked unless an
 *     admin allowed them.
 */
final class Dialplan
{
    private const EMERGENCY = ['999', '112'];

    /** A colleague's extension: 3–5 digits starting 2–8. */
    public const EXTENSION = '/^[2-8][0-9]{2,4}$/';

    /**
     * @param  list<string>  $allowedInternationalPrefixes  e.g. ['+353'] (E.164 prefixes an admin enabled)
     * @param  ?Closure(string): ?string  $extensions  extension => that person's identity (user-12),
     *                                               a ring group (ring_group:3), or null
     */
    public function __construct(
        private readonly array $allowedInternationalPrefixes = [],
        private readonly bool $premiumAllowed = false,
        private readonly ?Closure $extensions = null,
    ) {
    }

    public function decide(string $to): DialDecision
    {
        $to = trim($to);

        if (preg_match('/^(?:client:)?(user-\d+)$/', $to, $m)) {
            return DialDecision::client($m[1]);
        }

        // Call parking: ** (any free slot) or **1 to **9.
        if (preg_match('/^\*\*([1-9])?$/', $to, $m)) {
            return DialDecision::park(isset($m[1]) ? (int) $m[1] : null);
        }

        // Desk-phone shortcut: *12 calls colleague user-12.
        if (preg_match('/^\*(\d{1,9})$/', $to, $m)) {
            return DialDecision::client('user-'.(int) $m[1]);
        }

        $digits = preg_replace('/[\s().-]/', '', $to) ?? '';

        if (self::isEmergency($to)) {
            return DialDecision::emergency($digits);
        }

        if (preg_match(self::EXTENSION, $digits)) {
            $identity = $this->extensions ? ($this->extensions)($digits) : null;

            return match (true) {
                $identity === null => DialDecision::reject("There is no extension {$digits}."),
                str_starts_with($identity, 'ring_group:') => DialDecision::ringGroup((int) substr($identity, 11)),
                default => DialDecision::client($identity),
            };
        }

        $e164 = self::normaliseUk($digits);
        if ($e164 === null) {
            return DialDecision::reject('That number is not valid.');
        }

        if (str_starts_with($e164, '+44')) {
            if (str_starts_with($e164, '+449') && ! $this->premiumAllowed) {
                return DialDecision::reject('Calls to premium-rate numbers are blocked. An admin can allow them.');
            }

            return DialDecision::number($e164);
        }

        foreach ($this->allowedInternationalPrefixes as $prefix) {
            if (str_starts_with($e164, $prefix)) {
                return DialDecision::number($e164);
            }
        }

        return DialDecision::reject('International calls to this country are blocked. An admin can allow them.');
    }

    /**
     * The dialled number from a trunk's "To" value, which may be plain
     * (+442079460000, 442079460000, 02079460000) or a SIP URI
     * (sip:+442079460000@sbc.provider.example;transport=udp).
     */
    public static function numberFromCarrierAddress(string $to): ?string
    {
        $to = trim($to);
        if (preg_match('/^sips?:([^@;]+)/i', $to, $m)) {
            $to = rawurldecode($m[1]);
        }

        $to = preg_replace('/[\s().-]/', '', $to) ?? '';

        // Many UK trunks send the country code without "+".
        if (preg_match('/^44[1-9]\d{8,9}$/', $to)) {
            $to = '+'.$to;
        }

        return self::normaliseUk($to);
    }

    public static function isEmergency(string $to): bool
    {
        return in_array(preg_replace('/[\s().-]/', '', trim($to)), self::EMERGENCY, true);
    }

    public static function noEmergencyService(): DialDecision
    {
        return DialDecision::reject('This phone system cannot make emergency calls. Hang up and dial 999 from a mobile or landline.');
    }

    /** Turns UK-style input (07…, 01…, 0044…, +44…) into E.164; null if invalid. */
    public static function normaliseUk(string $digits): ?string
    {
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        // Common UK habit: "+44 (0)20 …" — drop the trunk 0 after the country code.
        if (str_starts_with($digits, '+440')) {
            $digits = '+44'.substr($digits, 4);
        } elseif (preg_match('/^0[1-9]\d{8,9}$/', $digits)) {
            $digits = '+44'.substr($digits, 1);
        }

        if (! preg_match('/^\+[1-9]\d{7,14}$/', $digits)) {
            return null;
        }

        // UK numbers are +44 followed by 9 or 10 digits, never a leading 0.
        if (str_starts_with($digits, '+44') && ! preg_match('/^\+44[1-9]\d{8,9}$/', $digits)) {
            return null;
        }

        return $digits;
    }
}
