{{-- The phone docked in the corner of every app page. Turbo keeps this element (and its calls) across page changes. --}}
<aside id="phone" class="phone-window phone-dock" data-docked data-turbo-permanent data-open="false"
       data-token-url="{{ route('softphone.token') }}" data-park-url="{{ route('softphone.park') }}" aria-label="Phone">
    <div class="dock-bar">
        <button type="button" class="dock-toggle" id="dock-toggle" aria-expanded="false" aria-controls="dock-body">
            <span class="status-dot" id="status-dot" data-state="connecting"></span>
            <span class="dock-label">Phone</span>
            <span id="state" aria-live="polite">Connecting…</span>
        </button>
        <button type="button" class="dock-icon" id="dock-popout" title="Pop out into its own window" aria-label="Pop out into its own window">↗</button>
    </div>
    <div class="dock-body" id="dock-body" hidden>
        @include('partials.phone', ['docked' => true])
    </div>
</aside>
