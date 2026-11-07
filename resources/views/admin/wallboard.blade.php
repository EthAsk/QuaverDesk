@extends('layouts.app')
@section('title', 'Live wallboard')
@push('scripts')
    @vite(['resources/js/wallboard.js'])
@endpush
@section('content')
    <div class="wallboard" data-wallboard data-url="{{ route('admin.wallboard.data') }}" data-refresh="{{ $refresh }}">
        <script type="application/json">@json($initial)</script>
        <div class="page-head row" style="justify-content:space-between">
            <div>
                <h1>Live wallboard</h1>
                <p class="muted">Updates every {{ $refresh }} seconds. <span data-part="status" class="pill">Live</span></p>
            </div>
            <button type="button" class="btn ghost" data-fullscreen>Full screen</button>
        </div>

        <div class="wb-tiles" data-part="totals"></div>

        <div class="card">
            <h2>Queues</h2>
            <div data-part="queues"></div>
        </div>

        <div class="wb-split">
            <div class="card">
                <h2>Live calls</h2>
                <div data-part="live"></div>
            </div>
            <div class="card">
                <h2>People</h2>
                <div data-part="people"></div>
            </div>
        </div>
        <noscript><p class="muted">The wallboard needs JavaScript to update.</p></noscript>
    </div>
@endsection
