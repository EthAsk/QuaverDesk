<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Jobs\DeliverWebhook;
use App\Models\Call;
use App\Models\Recording;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\SafeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Webhooks and Zapier (Plus, #20). */
class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://93.184.216.34/hook';

    private function admin(Plan $plan = Plan::Plus): User
    {
        if ($plan !== Plan::Free) {
            $this->usePlan($plan);
        }
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($owner);

        return $owner;
    }

    private function endpoint(?array $events = null, string $secret = 'whsec_test'): WebhookEndpoint
    {
        $endpoint = new WebhookEndpoint(['name' => 'Hook', 'url' => self::URL, 'events' => $events, 'enabled' => true]);
        $endpoint->secret = $secret;
        $endpoint->save();

        return $endpoint;
    }

    private function makeCall(array $extra = []): Call
    {
        return Call::create($extra + ['carrier_sid' => 'sid-'.uniqid(), 'direction' => 'inbound', 'from' => '+441142000000', 'to' => '+441142111111', 'status' => 'ringing']);
    }

    public function test_free_plan_has_no_webhooks_and_sends_nothing(): void
    {
        $owner = $this->admin(Plan::Free);
        $this->actingAs($owner)->get('/admin/webhooks')->assertRedirect('/dashboard');

        $this->endpoint();
        Http::fake();
        $this->makeCall();
        Http::assertNothingSent();
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_an_admin_adds_an_endpoint_and_sees_the_secret_once(): void
    {
        $owner = $this->admin();

        $response = $this->actingAs($owner)->post('/admin/webhooks', ['name' => 'Zapier', 'url' => self::URL, 'events' => ['call.ended']]);
        $endpoint = WebhookEndpoint::query()->firstOrFail();
        $response->assertRedirect("/admin/webhooks/{$endpoint->id}");
        $this->assertSame(['call.ended'], $endpoint->events);

        $secret = session('secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame($secret, $endpoint->secret);
        $this->assertNotSame($secret, $endpoint->getRawOriginal('secret'), 'The secret is stored encrypted.');

        $this->get("/admin/webhooks/{$endpoint->id}")->assertOk()->assertSee($secret);
        $this->get("/admin/webhooks/{$endpoint->id}")->assertOk()->assertDontSee($secret);
    }

    public function test_unsafe_addresses_are_refused(): void
    {
        $owner = $this->admin();

        foreach (['http://93.184.216.34/hook', 'https://127.0.0.1/hook', 'https://169.254.169.254/latest', 'https://[::1]/x', 'https://user:pw@93.184.216.34/x', 'https://93.184.216.34:6379/x', 'ftp://93.184.216.34/x'] as $url) {
            $this->actingAs($owner)->post('/admin/webhooks', ['name' => 'Bad', 'url' => $url])->assertSessionHasErrors('url');
        }
        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_private_addresses_are_allowed_on_a_self_hosted_install_but_not_the_hosted_service(): void
    {
        $this->assertTrue(SafeUrl::allowed('10.0.0.5'));
        $this->assertFalse(SafeUrl::allowed('::ffff:127.0.0.1'));

        config(['tenancy.enabled' => true]);
        $this->assertFalse(SafeUrl::allowed('10.0.0.5'));
        $this->assertFalse(SafeUrl::allowed('::ffff:10.0.0.5'));
        $this->assertFalse(SafeUrl::allowed('fd00::1'));
        $this->assertTrue(SafeUrl::allowed('93.184.216.34'));
    }

    public function test_a_call_starting_and_ending_is_sent_signed(): void
    {
        $this->admin();
        $this->endpoint();
        Http::fake(['*' => Http::response('ok')]);

        $user = User::factory()->create(['name' => 'Ann Agent']);
        $call = $this->makeCall(['user_id' => $user->id]);
        $call->forceFill(['status' => 'completed', 'duration_seconds' => 61])->save();

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(2, $sent);

        /** @var Request $ended */
        $ended = $sent[1];
        $body = json_decode($ended->body(), true);
        $this->assertSame('call.ended', $body['event']);
        $this->assertSame(61, $body['duration_seconds']);
        $this->assertSame('Ann Agent', $body['user_name']);
        $this->assertSame('+441142000000', $body['from']);
        $this->assertSame($body['id'], $ended->header('X-QuaverDesk-Delivery')[0]);
        $this->assertSame('call.ended', $ended->header('X-QuaverDesk-Event')[0]);

        preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $ended->header('X-QuaverDesk-Signature')[0], $m);
        $this->assertSame(hash_hmac('sha256', $m[1].'.'.$ended->body(), 'whsec_test'), $m[2]);

        $this->assertSame(['call.started', 'call.ended'], WebhookDelivery::query()->orderBy('id')->pluck('event')->all());
        $this->assertSame(['delivered', 'delivered'], WebhookDelivery::query()->pluck('status')->all());
    }

    public function test_transfer_and_follow_me_legs_are_not_calls_of_their_own(): void
    {
        $this->admin();
        $this->endpoint();
        Http::fake();

        $this->makeCall(['leg' => Call::LEG_TRANSFER]);
        $this->makeCall(['leg' => Call::LEG_FOLLOW_ME]);
        Http::assertNothingSent();
    }

    public function test_an_endpoint_only_gets_the_events_it_chose_and_only_while_on(): void
    {
        $this->admin();
        $voicemail = $this->endpoint(['voicemail.received']);
        Http::fake();

        $this->makeCall();
        Http::assertNothingSent();

        $voicemail->forceFill(['enabled' => false])->save();
        Recording::create(['carrier_sid' => 'rec-'.uniqid(), 'kind' => Recording::VOICEMAIL, 'status' => 'pending'])->forceFill(['status' => 'stored'])->save();
        Http::assertNothingSent();

        $voicemail->forceFill(['enabled' => true])->save();
        $recording = Recording::create(['carrier_sid' => 'rec-'.uniqid(), 'kind' => Recording::VOICEMAIL, 'status' => 'pending', 'from' => '+441142000000']);
        $recording->forceFill(['status' => 'stored', 'duration_seconds' => 12])->save();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['event'] === 'voicemail.received' && $r['from'] === '+441142000000' && $r['duration_seconds'] === 12);
    }

    public function test_recordings_and_ai_summaries_are_sent(): void
    {
        $this->admin(Plan::Pro);
        $this->endpoint();
        Http::fake();

        $call = Recording::create(['carrier_sid' => 'rec-'.uniqid(), 'kind' => Recording::CALL, 'status' => 'pending']);
        $call->forceFill(['status' => 'stored'])->save();
        $call->forceFill(['summary' => 'Wants a callback', 'action_items' => ['Ring back'], 'sentiment' => 'positive', 'ai_status' => 'done'])->save();

        // A message the AI receptionist wrote down has no audio to wait for.
        Recording::create(['carrier_sid' => 'rec-'.uniqid(), 'kind' => Recording::VOICEMAIL, 'status' => Recording::MESSAGE]);

        $this->assertSame(['recording.ready', 'ai.summary_ready', 'voicemail.received'], WebhookDelivery::query()->orderBy('id')->pluck('event')->all());
        Http::assertSent(fn (Request $r) => $r['event'] === 'ai.summary_ready' && $r['summary'] === 'Wants a callback' && $r['action_items'] === ['Ring back'] && $r['sentiment'] === 'positive');
    }

    public function test_a_failed_delivery_is_retried_with_backoff_then_given_up_on(): void
    {
        $this->admin();
        $endpoint = $this->endpoint();
        Http::fake(['*' => Http::response('no', 500)]);
        Queue::fake();

        $this->makeCall();
        $delivery = WebhookDelivery::query()->firstOrFail();
        Queue::assertPushed(DeliverWebhook::class, 1);

        foreach (DeliverWebhook::BACKOFF as $i => $delay) {
            (new DeliverWebhook($delivery->id))->handle();
            $delivery->refresh();
            $this->assertSame($i + 1, $delivery->attempts);
            $this->assertSame('pending', $delivery->status);
            $this->assertSame(500, $delivery->response_code);
            Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->delay === $delay);
        }

        (new DeliverWebhook($delivery->id))->handle();
        $this->assertSame('failed', $delivery->refresh()->status);
        $this->assertCount(1 + count(DeliverWebhook::BACKOFF), Queue::pushed(DeliverWebhook::class), 'No retry is queued after the last one.');
        $this->assertNotNull($endpoint->refresh()->last_failure_at);
        $this->assertNull($endpoint->last_success_at);
    }

    public function test_a_redirect_is_not_followed_and_counts_as_a_failure(): void
    {
        $this->admin();
        $this->endpoint();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/'])]);
        Queue::fake();

        $this->makeCall();
        (new DeliverWebhook(WebhookDelivery::query()->firstOrFail()->id))->handle();

        $this->assertSame(302, WebhookDelivery::query()->firstOrFail()->response_code);
        Http::assertSentCount(1);
    }

    public function test_a_switched_off_endpoint_gives_up_what_was_waiting(): void
    {
        $this->admin();
        $endpoint = $this->endpoint();
        Http::fake(['*' => Http::response('no', 500)]);
        Queue::fake();

        $this->makeCall();
        $endpoint->forceFill(['enabled' => false])->save();
        (new DeliverWebhook(WebhookDelivery::query()->firstOrFail()->id))->handle();

        $this->assertSame('failed', WebhookDelivery::query()->firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_test_event_edit_new_secret_and_removal(): void
    {
        $owner = $this->admin();
        $endpoint = $this->endpoint();
        Http::fake();

        $this->actingAs($owner)->post("/admin/webhooks/{$endpoint->id}/test")->assertRedirect();
        Http::assertSent(fn (Request $r) => $r['event'] === 'ping');

        $this->put("/admin/webhooks/{$endpoint->id}", ['name' => 'Renamed', 'url' => self::URL, 'events' => ['call.started', 'call.ended'], 'enabled' => '1', 'new_secret' => '1'])->assertRedirect();
        $endpoint->refresh();
        $this->assertSame('Renamed', $endpoint->name);
        $this->assertNotSame('whsec_test', $endpoint->secret);
        $this->assertSame(['call.started', 'call.ended'], $endpoint->events);

        // Ticking every event means "everything", including events added later.
        $this->put("/admin/webhooks/{$endpoint->id}", ['name' => 'Renamed', 'url' => self::URL, 'events' => array_keys(\App\Webhooks\Webhooks::EVENTS), 'enabled' => '1'])->assertRedirect();
        $this->assertNull($endpoint->refresh()->events);

        $this->delete("/admin/webhooks/{$endpoint->id}")->assertRedirect('/admin/webhooks');
        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_the_secret_is_never_in_a_company_export_and_old_deliveries_are_pruned(): void
    {
        $this->admin();
        $endpoint = $this->endpoint();
        $this->assertArrayNotHasKey('secret', $endpoint->toArray());

        Http::fake();
        $this->makeCall();
        $old = WebhookDelivery::query()->firstOrFail();
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        $this->makeCall();

        $this->artisan('webhooks:prune')->assertSuccessful();
        $this->assertSame(1, WebhookDelivery::query()->count());
        $this->assertFalse(WebhookDelivery::query()->whereKey($old->id)->exists());
    }

    public function test_a_call_only_reaches_its_own_companys_endpoints_even_when_logged_across_companies(): void
    {
        config(['tenancy.enabled' => true, 'tenancy.base_domain' => 'quaverdesk.test']);
        $tenancy = app(\App\Tenancy\Tenancy::class);
        $keys = \App\Domain\Licensing\LicenceCodec::generateKeyPair();
        config(['voip.licence_public_key' => $keys['public']]);

        $acme = \App\Models\Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $bolt = \App\Models\Tenant::query()->create(['name' => 'Bolt', 'slug' => 'bolt']);
        foreach ([$acme, $bolt] as $tenant) {
            $tenancy->run($tenant, function () use ($keys, $tenant) {
                \App\Models\Setting::put('licence_key', \App\Domain\Licensing\LicenceCodec::issue($keys['secret'], 'Test Ltd', Plan::Plus, 25, null));
                $endpoint = new WebhookEndpoint(['name' => $tenant->name, 'url' => 'https://93.184.216.34/'.$tenant->slug]);
                $endpoint->secret = 'whsec_'.$tenant->slug;
                $endpoint->save();
            });
        }
        Http::fake();

        // The voice server logs on-net calls with every company in reach.
        $tenancy->everywhere(fn () => $tenancy->run($bolt, fn () => $this->makeCall(['leg' => Call::LEG_ON_NET])));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/bolt'));
    }
}
