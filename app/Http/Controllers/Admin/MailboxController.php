<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MailboxController extends Controller
{
    public function index(): View
    {
        return view('admin.mailboxes.index', [
            'mailboxes' => Mailbox::query()->withCount(['members', 'owners'])->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Mailbox([
            'greeting' => 'Sorry we missed your call. Please leave your name, number and a short message after the tone.',
            'max_seconds' => 120,
            'attach_audio' => true,
            'include_transcript' => true,
            'delete_after_send' => false,
        ]));
    }

    public function edit(Mailbox $mailbox): View
    {
        return $this->form($mailbox);
    }

    public function store(Request $request): RedirectResponse
    {
        $mailbox = DB::transaction(fn () => $this->save(new Mailbox(), $request));

        return redirect()->route('admin.mailboxes')->with('status', "Voicemail box \"{$mailbox->name}\" created.");
    }

    public function update(Request $request, Mailbox $mailbox): RedirectResponse
    {
        DB::transaction(fn () => $this->save($mailbox, $request));

        return redirect()->route('admin.mailboxes')->with('status', "Voicemail box \"{$mailbox->name}\" saved.");
    }

    public function destroy(Mailbox $mailbox): RedirectResponse
    {
        $mailbox->delete();

        return back()->with('status', "Voicemail box \"{$mailbox->name}\" deleted. Its saved messages stay in Recordings until they expire.");
    }

    private function form(Mailbox $mailbox): View
    {
        return view('admin.mailboxes.form', [
            'mailbox' => $mailbox,
            'users' => User::query()->orderBy('name')->get(),
            'memberIds' => $mailbox->exists ? $mailbox->members()->pluck('users.id')->all() : [],
            'ownerIds' => $mailbox->exists ? $mailbox->owners()->pluck('id')->all() : [],
        ]);
    }

    private function save(Mailbox $mailbox, Request $request): Mailbox
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'greeting' => ['required', 'string', 'max:1000'],
            'greeting_sound_id' => ['nullable', 'integer', TenantRule::exists('sounds')],
            'max_seconds' => ['required', 'integer', 'min:10', 'max:300'],
            'attach_audio' => ['nullable', 'boolean'],
            'include_transcript' => ['nullable', 'boolean'],
            'delete_after_send' => ['nullable', 'boolean'],
            'extra_emails' => ['nullable', 'string', 'max:2000'],
            'members' => ['nullable', 'array'],
            'members.*' => ['integer', TenantRule::exists('users')],
            'owners' => ['nullable', 'array'],
            'owners.*' => ['integer', TenantRule::exists('users')],
        ]);

        $emails = preg_split('/[\s,;]+/', (string) ($data['extra_emails'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['extra_emails' => "\"{$email}\" is not a valid email address."]);
            }
        }

        $mailbox->fill([
            'name' => $data['name'],
            'greeting' => $data['greeting'],
            'greeting_sound_id' => $data['greeting_sound_id'] ?? null,
            'max_seconds' => $data['max_seconds'],
            'attach_audio' => $request->boolean('attach_audio'),
            'include_transcript' => $request->boolean('include_transcript'),
            'delete_after_send' => $request->boolean('delete_after_send'),
            'extra_emails' => array_values(array_unique(array_map('strtolower', $emails))),
        ])->save();

        $mailbox->members()->sync($data['members'] ?? []);

        // Unanswered calls to these people go here (a person has one voicemail box).
        $owners = array_map('intval', $data['owners'] ?? []);
        User::query()->where('mailbox_id', $mailbox->id)->whereNotIn('id', $owners)->update(['mailbox_id' => null]);
        User::query()->whereIn('id', $owners)->update(['mailbox_id' => $mailbox->id]);

        return $mailbox;
    }
}
