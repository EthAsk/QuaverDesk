<?php

namespace App\Http\Controllers\Admin;

use App\Ai\AiSettings;
use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Models\Receptionist;
use App\Rules\ValidDestination;
use App\Support\DestinationCatalog;
use App\Tenancy\TenantRule;
use App\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** AI receptionists (Pro): set up what they know and where they can put callers through. */
class ReceptionistController extends Controller
{
    public function index(DestinationCatalog $catalog, AiSettings $ai): View
    {
        return view('admin.receptionists.index', [
            'receptionists' => Receptionist::query()->with('mailbox:id,name')->orderBy('name')->get(),
            'catalog' => $catalog,
            'aiReady' => $ai->transcriptionReady() && $ai->analysisReady(),
        ]);
    }

    public function create(DestinationCatalog $catalog, Tenancy $tenancy): View
    {
        $business = $tenancy->current()->name ?: config('app.name');

        return $this->form(new Receptionist([
            'business' => $business,
            'greeting' => "Hello, you've reached {$business}. How can I help?",
            'routes' => [],
            'max_turns' => 6,
        ]), $catalog);
    }

    public function edit(Receptionist $receptionist, DestinationCatalog $catalog): View
    {
        return $this->form($receptionist, $catalog);
    }

    public function store(Request $request): RedirectResponse
    {
        $receptionist = $this->save(new Receptionist(), $request);

        return redirect()->route('admin.receptionists')->with('status', "Receptionist \"{$receptionist->name}\" created. Choose it as a number's destination to put it to work.");
    }

    public function update(Request $request, Receptionist $receptionist): RedirectResponse
    {
        $this->save($receptionist, $request);

        return redirect()->route('admin.receptionists')->with('status', "Receptionist \"{$receptionist->name}\" saved.");
    }

    public function destroy(Receptionist $receptionist): RedirectResponse
    {
        $receptionist->delete();

        return back()->with('status', "Receptionist \"{$receptionist->name}\" deleted. Anything that pointed at it now plays the unavailable message.");
    }

    private function form(Receptionist $receptionist, DestinationCatalog $catalog): View
    {
        $options = $catalog->options();
        // A receptionist can't put callers through to itself.
        foreach ($options as $group => $items) {
            unset($options[$group]['receptionist:'.$receptionist->id]);
        }

        return view('admin.receptionists.form', [
            'receptionist' => $receptionist,
            'options' => $options,
            'mailboxes' => Mailbox::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    private function save(Receptionist $receptionist, Request $request): Receptionist
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'business' => ['required', 'string', 'max:120'],
            'greeting' => ['required', 'string', 'max:500'],
            'about' => ['nullable', 'string', 'max:4000'],
            'routes' => ['nullable', 'array', 'max:'.Receptionist::MAX_ROUTES],
            'routes.*.name' => ['nullable', 'string', 'max:60'],
            'routes.*.about' => ['nullable', 'string', 'max:300'],
            'routes.*.to' => ['nullable', 'required_with:routes.*.name', new ValidDestination()],
            'mailbox_id' => ['nullable', 'integer', TenantRule::exists('mailboxes')],
            'fallback' => ['nullable', new ValidDestination()],
            'max_turns' => ['required', 'integer', 'min:2', 'max:10'],
        ], ['routes.*.to.required_with' => 'Choose where this option puts callers through to.']);

        $routes = [];
        foreach ($data['routes'] ?? [] as $route) {
            if (filled($route['name'] ?? null) && filled($route['to'] ?? null) && $route['to'] !== 'receptionist:'.$receptionist->id) {
                $routes[] = ['name' => trim($route['name']), 'about' => trim((string) ($route['about'] ?? '')), 'to' => $route['to']];
            }
        }

        $receptionist->fill([
            'name' => $data['name'],
            'business' => $data['business'],
            'greeting' => $data['greeting'],
            'about' => $data['about'] ?? null,
            'routes' => $routes,
            'mailbox_id' => $data['mailbox_id'] ?? null,
            'fallback' => ($data['fallback'] ?? null) === 'receptionist:'.$receptionist->id ? null : ($data['fallback'] ?? null),
            'max_turns' => $data['max_turns'],
        ])->save();

        return $receptionist;
    }
}
