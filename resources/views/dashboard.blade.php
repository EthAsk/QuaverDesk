@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
    <div class="page-head">
        <h1>Hello, {{ auth()->user()->name }}</h1>
        <p class="muted">Your extension is <strong>{{ auth()->user()->carrierIdentity() }}</strong>.</p>
    </div>

    @unless ($setupDone)
        <div class="flash warn">
            Calling is switched off until an administrator completes setup.
            @if (auth()->user()->isAdmin())
                <a href="{{ route('setup.acknowledge') }}">Complete setup</a>
            @endif
        </div>
    @endunless

    <div class="grid">
        <div class="card stat">
            <div class="label">Plan</div>
            <div class="value">{{ $licence->plan->label() }}</div>
            <div class="muted small">{{ $licence->isFree() ? 'No licence key needed' : 'Licensed to '.$licence->licensee }}</div>
        </div>
        <div class="card stat">
            <div class="label">Users</div>
            <div class="value">{{ $userCount }} / {{ $licence->maxUsers() }}</div>
            <div class="muted small">Allowed on this plan</div>
        </div>
        <div class="card stat">
            <div class="label">Licence expires</div>
            <div class="value">{{ $licence->expiresAt?->format('j M Y') ?? '—' }}</div>
            <div class="muted small">{{ $licence->isFree() ? 'No licence installed' : 'Renew before this date' }}</div>
        </div>
    </div>

    <div class="card">
        <h2>Features</h2>
        <ul class="features">
            @foreach ($features as $feature)
                <li>
                    <span>{{ $feature['label'] }}</span>
                    @if ($feature['enabled'])
                        <span class="pill">On</span>
                    @else
                        <span class="pill off" title="Available on a paid plan">Locked</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($licence->isFree())
            <p class="muted small" style="margin-top:12px">
                AI features use your own AI provider; Plus and Pro unlock the buttons.
            </p>
        @endif
    </div>
@endsection
