<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IvrMenu;
use App\Rules\ValidDestination;
use App\Support\DestinationCatalog;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IvrMenuController extends Controller
{
    public function index(DestinationCatalog $catalog): View
    {
        return view('admin.ivr.index', [
            'menus' => IvrMenu::query()->orderBy('name')->get(),
            'catalog' => $catalog,
        ]);
    }

    public function create(DestinationCatalog $catalog): View
    {
        return $this->form(new IvrMenu([
            'greeting' => 'Thank you for calling. Press 1 for sales, or 2 for support.',
            'options' => [],
            'timeout_seconds' => 6,
        ]), $catalog);
    }

    public function edit(IvrMenu $menu, DestinationCatalog $catalog): View
    {
        return $this->form($menu, $catalog);
    }

    public function store(Request $request): RedirectResponse
    {
        $menu = $this->save(new IvrMenu(), $request);

        return redirect()->route('admin.ivr')->with('status', "Menu \"{$menu->name}\" created.");
    }

    public function update(Request $request, IvrMenu $menu): RedirectResponse
    {
        $this->save($menu, $request);

        return redirect()->route('admin.ivr')->with('status', "Menu \"{$menu->name}\" saved.");
    }

    public function destroy(IvrMenu $menu): RedirectResponse
    {
        $menu->delete();

        return back()->with('status', "Menu \"{$menu->name}\" deleted. Anything that pointed at it now plays the unavailable message.");
    }

    private function form(IvrMenu $menu, DestinationCatalog $catalog): View
    {
        return view('admin.ivr.form', ['menu' => $menu, 'options' => $catalog->options()]);
    }

    private function save(IvrMenu $menu, Request $request): IvrMenu
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'greeting' => ['required', 'string', 'max:1000'],
            'greeting_sound_id' => ['nullable', 'integer', TenantRule::exists('sounds')],
            'timeout_seconds' => ['required', 'integer', 'min:3', 'max:20'],
            'fallback' => ['nullable', new ValidDestination()],
            'options' => ['nullable', 'array'],
            'options.*' => ['nullable', new ValidDestination()],
        ]);

        $options = [];
        foreach ($data['options'] ?? [] as $digit => $destination) {
            if ($destination !== null && preg_match('/^[0-9]$/', (string) $digit)) {
                $options[(string) $digit] = $destination;
            }
        }

        $menu->fill([
            'name' => $data['name'],
            'greeting' => $data['greeting'],
            'greeting_sound_id' => $data['greeting_sound_id'] ?? null,
            'timeout_seconds' => $data['timeout_seconds'],
            'fallback' => $data['fallback'] ?? null,
            'options' => $options,
        ])->save();

        return $menu;
    }
}
