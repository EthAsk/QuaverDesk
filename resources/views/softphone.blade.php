@extends('layouts.app')
@section('title', 'Phone')
@section('content')
    <div class="page-head">
        <h1>Phone</h1>
        <p class="muted">
            Your phone sits in the bottom corner of every page here, and keeps going while you move between pages.
            Pop it out into its own small window to keep it ringing while you use other apps.
            Call a colleague's extension or any UK number.
        </p>
    </div>

    <div class="grid" style="align-items:start">
        <div class="card">
            <h2>Your phone</h2>
            <p><span class="status-dot" data-phone-dot data-state="closed"></span> <span data-phone-text>Checking…</span></p>
            <div class="row">
                <button type="button" class="btn" data-phone-open>Show phone</button>
                <button type="button" class="btn ghost" data-phone-popout>Pop out</button>
                <button type="button" class="btn ghost" data-install hidden>Install as an app</button>
            </div>

            <h2 style="margin-top:24px">Settings on this computer</h2>
            <label class="check"><input type="checkbox" data-popout-pref> <span><strong>Pop out when I sign in</strong><br>
                <span class="muted small">Opens the phone in its own window. If your browser blocks it, allow pop-ups for this site.</span></span></label>
            <div class="field">
                <label>Call notifications</label>
                <div class="row"><span class="muted" data-notify-state></span><button type="button" class="btn ghost" data-notify-enable hidden>Turn on</button></div>
            </div>
            <div class="field">
                <label>Phone number links</label>
                <div class="row"><span class="muted small">Open <code>tel:</code> links from websites and email in this phone.</span><button type="button" class="btn ghost" data-tel-handler>Use for phone links</button></div>
            </div>
        </div>

        <div class="card">
            <h2>Keep it running</h2>
            <ol class="steps">
                <li><strong>Install it</strong> with <em>Install as an app</em> (Chrome or Edge), or in Safari choose <em>File → Add to Dock</em>.</li>
                <li><strong>Start it with your computer.</strong>
                    Windows: open <code>chrome://apps</code> or <code>edge://apps</code>, right-click {{ config('app.name') }} Phone, and choose <em>Start app when you sign in</em>.
                    Mac: right-click its Dock icon, then <em>Options → Open at Login</em>.</li>
                <li><strong>Turn on notifications</strong> so calls show up while the phone is behind other windows.</li>
                <li>Minimise the phone window rather than closing it. Closing it moves the phone back into {{ config('app.name') }} if that's open; otherwise calls go to your other phones or voicemail.</li>
            </ol>
            <h2 style="margin-top:24px">Transfer and park</h2>
            <p class="small"><strong>Transfer</strong> during a call, type who to send it to, then <em>Transfer now</em>,
                or <em>Talk first</em> to speak to them before handing over. <strong>Park</strong> puts the caller on hold
                in a numbered space; any phone here picks it up by dialling <code>**</code> and the number, such as <code>**3</code>.
                A parked call nobody picks up rings you again after 3 minutes.</p>
            <p class="small muted">Not for emergency calls. In an emergency, use a mobile or landline to dial 999.
                A 999 call can't be transferred or parked.</p>
        </div>

        <div class="card">
            <h2>Ring my mobile too</h2>
            <p class="muted small">Your calls can ring your own mobile as well as this phone. When you answer, you press 1 to take the call,
                so your mobile's voicemail never takes it by mistake. Calls to your mobile count as outside calls.</p>
            @php($me = auth()->user())
            <form method="POST" action="{{ route('follow-me.update') }}">
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="mobile">Your mobile</label>
                    <input id="mobile" type="tel" name="mobile" value="{{ old('mobile', $me->mobile) }}" placeholder="07700 900123" autocomplete="tel">
                    @if ($me->mobile)
                        <div class="small {{ $me->mobile_verified_at ? 'muted' : 'error' }}">{{ $me->mobile_verified_at ? 'Confirmed.' : 'Not confirmed yet.' }}</div>
                    @endif
                    @error('mobile')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="follow_me">Ring it</label>
                    <select id="follow_me" name="follow_me">
                        <option value="off" @selected(old('follow_me', $me->follow_me) === 'off')>Never</option>
                        <option value="together" @selected(old('follow_me', $me->follow_me) === 'together')>At the same time as this phone</option>
                        <option value="after" @selected(old('follow_me', $me->follow_me) === 'after')>If I haven't answered after…</option>
                    </select>
                </div>
                <div class="field">
                    <label for="follow_me_delay">Seconds to wait first</label>
                    <input id="follow_me_delay" type="text" inputmode="numeric" name="follow_me_delay" value="{{ old('follow_me_delay', $me->follow_me_delay) }}">
                    @error('follow_me_delay')<div class="error">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn">Save</button>
            </form>
            @if ($me->mobile && ! $me->mobile_verified_at)
                <form method="POST" action="{{ route('follow-me.verify') }}" style="margin-top:8px">
                    @csrf
                    <button type="submit" class="btn ghost">Call my mobile to confirm it</button>
                </form>
            @endif
        </div>

        <div class="card">
            <h2>Colleagues</h2>
            @forelse ($colleagues as $c)
                <div class="contact">
                    <span>{{ $c->name }} <span class="muted small">ext {{ $c->dialable() }}</span></span>
                    <button type="button" class="btn ghost" data-dial="{{ $c->dialable() }}">Call</button>
                </div>
            @empty
                <p class="muted">No colleagues yet. An admin can add users.</p>
            @endforelse
        </div>
    </div>
@endsection
