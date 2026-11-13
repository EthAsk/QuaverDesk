@extends('layouts.app')
@section('title', 'Live wallboard')
@push('scripts')
    @vite(['resources/js/wallboard.js'])
@endpush
@section('content')
    <div class="wallboard" data-wallboard data-url="{{ route('wallboard.data') }}" data-refresh="{{ $refresh }}" @if ($canMonitor) data-monitor="{{ url('/admin/wallboard/monitor') }}" data-csrf="{{ csrf_token() }}" @endif>
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
        @if (auth()->user()->isAdmin())
            <div class="card">
                <h2>Who sees what</h2>
                <form method="POST" action="{{ route('admin.wallboard.settings') }}">
                    @csrf @method('PUT')
                    <label class="check">
                        <input type="hidden" name="hide_numbers" value="0">
                        <input type="checkbox" name="hide_numbers" value="1" @checked($hideNumbers)>
                        <span>Hide callers' numbers from people who aren't admins. They still see who's on each call, the queues and today's totals.</span>
                    </label>
                    <button class="btn small" type="submit">Save</button>
                </form>
            </div>
        @endif
        <noscript><p class="muted">The wallboard needs JavaScript to update.</p></noscript>
    </div>
@endsection
