{{-- The phone's controls, shared by its own window (phone.blade.php) and the dock in the app. --}}
<div class="flash warn small" id="elsewhere" hidden>
    <span id="elsewhere-text"></span>
    <button type="button" class="btn ghost small" id="takeover">{{ ($docked ?? false) ? 'Use it here' : 'Use this window' }}</button>
</div>
<div class="flash small" id="hint" role="status" hidden></div>

<nav class="phone-tabs" role="tablist" aria-label="Phone">
    <button type="button" role="tab" data-tab="keypad" aria-selected="true">Keypad</button>
    <button type="button" role="tab" data-tab="colleagues" aria-selected="false">Colleagues</button>
    <button type="button" role="tab" data-tab="settings" aria-selected="false">Settings</button>
</nav>

<section data-panel="keypad">
    <div class="caller" id="caller" hidden>Incoming call from <strong id="caller-name"></strong></div>
    <input class="display" id="number" type="tel" inputmode="tel" autocomplete="off" spellcheck="false"
           aria-label="Number to call" placeholder="Number or extension">
    <div class="timer" id="timer" aria-live="off"></div>

    <div class="keypad" role="group" aria-label="Keypad">
        @foreach (['1','2','3','4','5','6','7','8','9','*','0','#'] as $k)
            <button type="button" data-key="{{ $k }}">{{ $k }}</button>
        @endforeach
    </div>

    <div class="phone-actions">
        <button type="button" class="btn ghost" id="mute" aria-pressed="false" disabled>Mute</button>
        <button type="button" class="btn ghost" id="hold" aria-pressed="false" disabled>Hold</button>
        <button type="button" class="btn ghost" id="clear">Clear</button>
        <button type="button" class="btn ghost" id="transfer" disabled>Transfer</button>
        <button type="button" class="btn ghost" id="park" disabled title="Park the call so any phone can pick it up">Park</button>
    </div>

    {{-- Transferring: type a number or extension above, then send the call straight there or talk to them first. --}}
    <div class="xfer" id="xfer" hidden>
        <p class="small muted" id="xfer-text">Type the number or extension to transfer to.</p>
        <div class="phone-actions">
            <button type="button" class="btn ghost" id="xfer-now">Transfer now</button>
            <button type="button" class="btn ghost" id="xfer-ask">Talk first</button>
            <button type="button" class="btn ghost" id="xfer-cancel">Cancel</button>
        </div>
    </div>

    <button type="button" class="btn call-btn" id="call" disabled>Call</button>
</section>

<section data-panel="colleagues" hidden>
    @forelse ($colleagues as $c)
        <div class="contact">
            <span>{{ $c->name }} <span class="muted small">ext {{ $c->dialable() }}</span></span>
            <button type="button" class="btn ghost small" data-dial="{{ $c->dialable() }}" data-name="{{ $c->name }}">Call</button>
        </div>
    @empty
        <p class="muted small">No colleagues yet. An admin can add users.</p>
    @endforelse
</section>

<section data-panel="settings" hidden>
    <label class="check"><input type="checkbox" id="pref-dnd"> <span><strong>Do not disturb</strong><br><span class="muted small">Calls go straight to voicemail (or your other phones).</span></span></label>
    <label class="check"><input type="checkbox" id="pref-popout"> <span><strong>Pop out when I sign in</strong><br><span class="muted small">Open the phone in its own small window on this computer.</span></span></label>

    <div class="field">
        <label>Notifications</label>
        <div class="row"><span id="notify-state" class="muted small"></span><button type="button" class="btn ghost small" id="notify-enable" hidden>Turn on</button></div>
    </div>
    <div class="field">
        <label for="pref-mic">Microphone</label>
        <select id="pref-mic"></select>
    </div>
    <div class="field">
        <label for="pref-speaker">Speaker</label>
        <select id="pref-speaker"></select>
    </div>
    <p class="small muted">More options: <a href="{{ route('softphone') }}" @unless ($docked ?? false) target="_blank" rel="noopener" @endunless>Phone set-up</a>.</p>
</section>

<audio id="remote-audio" autoplay></audio>
<p class="small muted phone-legal">Not for emergency calls. Dial 999 from a mobile or landline.</p>
