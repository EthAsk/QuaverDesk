<?php

namespace Tests\Unit;

use App\Domain\Telephony\DialDecision;
use App\Domain\Telephony\Dialplan;
use PHPUnit\Framework\TestCase;

class DialplanTest extends TestCase
{
    public function test_colleagues_can_be_called_by_identity(): void
    {
        $plan = new Dialplan();

        $this->assertSame(DialDecision::CLIENT, $plan->decide('user-12')->type);
        $this->assertSame('user-12', $plan->decide('client:user-12')->target);
    }

    public function test_star_shortcut_calls_a_colleague(): void
    {
        $decision = (new Dialplan())->decide('*12');

        $this->assertSame(DialDecision::CLIENT, $decision->type);
        $this->assertSame('user-12', $decision->target);
    }

    public function test_emergency_numbers_are_dialled(): void
    {
        $plan = new Dialplan();

        $this->assertSame(DialDecision::EMERGENCY, $plan->decide('999')->type);
        $this->assertSame(DialDecision::EMERGENCY, $plan->decide('112')->type);
    }

    public function test_uk_numbers_are_normalised(): void
    {
        $plan = new Dialplan();

        $this->assertSame('+447700900123', $plan->decide('07700 900123')->target);
        $this->assertSame('+442079460000', $plan->decide('020 7946 0000')->target);
        $this->assertSame('+442079460000', $plan->decide('0044 20 7946 0000')->target);
        $this->assertSame('+442079460000', $plan->decide('+44 (0)20-7946-0000')->target);
    }

    public function test_premium_and_international_blocked_by_default(): void
    {
        $plan = new Dialplan();

        $this->assertSame(DialDecision::REJECT, $plan->decide('09098 790000')->type);
        $this->assertSame(DialDecision::REJECT, $plan->decide('+33 1 23 45 67 89')->type);
    }

    public function test_admin_can_allow_a_country(): void
    {
        $plan = new Dialplan(allowedInternationalPrefixes: ['+353']);

        $this->assertSame(DialDecision::NUMBER, $plan->decide('+353 1 234 5678')->type);
        $this->assertSame(DialDecision::REJECT, $plan->decide('+33 1 23 45 67 89')->type);
    }

    public function test_numbers_are_read_from_carrier_addresses(): void
    {
        $this->assertSame('+442079460000', Dialplan::numberFromCarrierAddress('+442079460000'));
        $this->assertSame('+442079460000', Dialplan::numberFromCarrierAddress('sip:+442079460000@acme.sip.twilio.com;transport=udp'));
        $this->assertSame('+442079460000', Dialplan::numberFromCarrierAddress('sip:02079460000@10.0.0.1'));
        $this->assertSame(null, Dialplan::numberFromCarrierAddress('sip:reception@acme.sip.twilio.com'));
    }

    public function test_invalid_input_is_rejected(): void
    {
        $plan = new Dialplan();

        foreach (['', 'abc', '123', '+44 20 79', '0207946', 'user-x'] as $bad) {
            $this->assertSame(DialDecision::REJECT, $plan->decide($bad)->type, "Accepted: {$bad}");
        }
    }

    public function test_colleagues_can_be_called_by_extension(): void
    {
        $plan = new Dialplan(extensions: fn (string $ext) => $ext === '204' ? 'user-9' : null);

        $this->assertSame('user-9', $plan->decide('204')->target);
        $this->assertSame(DialDecision::REJECT, $plan->decide('205')->type);
        $this->assertSame('There is no extension 205.', $plan->decide('205')->message);
    }

    public function test_extensions_never_shadow_emergency_or_service_numbers(): void
    {
        $plan = new Dialplan(extensions: fn () => 'user-1');

        $this->assertSame(DialDecision::EMERGENCY, $plan->decide('999')->type);
        $this->assertSame(DialDecision::EMERGENCY, $plan->decide('112')->type);
        foreach (['101', '111', '999', '020', '07700900123'] as $number) {
            $this->assertDoesNotMatchRegularExpression(Dialplan::EXTENSION, $number);
        }
    }
}
