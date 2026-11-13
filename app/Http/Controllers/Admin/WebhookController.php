<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Rules\WebhookUrl;
use App\Webhooks\Webhooks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebhookController extends Controller
{
    public function index(): View
    {
        return view('admin.webhooks', ['endpoints' => WebhookEndpoint::query()->orderBy('name')->get(), 'events' => Webhooks::EVENTS]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $secret = 'whsec_'.Str::random(40);

        $endpoint = new WebhookEndpoint($data);
        $endpoint->secret = $secret;
        $endpoint->save();

        // The secret is shown this once; after that it can only be replaced.
        return redirect()->route('admin.webhooks.show', $endpoint)->with('secret', $secret)
            ->with('status', 'Endpoint added. Copy the signing secret now: it is not shown again.');
    }

    public function show(WebhookEndpoint $webhook): View
    {
        return view('admin.webhook', [
            'endpoint' => $webhook,
            'events' => Webhooks::EVENTS,
            'deliveries' => $webhook->deliveries()->latest('id')->limit(25)->get(['id', 'event', 'status', 'attempts', 'response_code', 'error', 'created_at']),
        ]);
    }

    public function update(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $webhook->fill($this->validated($request));
        $rotated = $request->boolean('new_secret');
        if ($rotated) {
            $webhook->secret = $secret = 'whsec_'.Str::random(40);
        }
        $webhook->save();

        $back = redirect()->route('admin.webhooks.show', $webhook)->with('status', 'Saved.');

        return $rotated ? $back->with('secret', $secret)->with('status', 'Saved with a new signing secret. Copy it now: it is not shown again.') : $back;
    }

    public function test(WebhookEndpoint $webhook): RedirectResponse
    {
        Webhooks::ping($webhook);

        return back()->with('status', 'A test event is on its way. Refresh in a moment to see how it went.');
    }

    public function destroy(WebhookEndpoint $webhook): RedirectResponse
    {
        $webhook->delete();

        return redirect()->route('admin.webhooks')->with('status', 'Endpoint removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:500', new WebhookUrl()],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::in(array_keys(Webhooks::EVENTS))],
        ]);

        // Nothing ticked means everything.
        $events = array_values($data['events'] ?? []);

        return [
            'name' => $data['name'],
            'url' => $data['url'],
            'events' => $events === [] || count($events) === count(Webhooks::EVENTS) ? null : $events,
            'enabled' => $request->boolean('enabled', true),
        ];
    }
}
