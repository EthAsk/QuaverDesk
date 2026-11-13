@extends('layouts.app')
@section('title', 'Home')
@section('content')
    @foreach (app(\App\Support\Modules::class)->homeCards() as $card)
        @include($card)
    @endforeach
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

    @if ($queues->isNotEmpty())
        <div class="card">
            <h2>Your call queues</h2>
            <p class="small muted">While you're logged in to a queue, its callers ring you when you're free. Dial <strong>*45</strong> from your phone to log in to or out of them all.</p>
            <table>
                @foreach ($queues as $q)
                    <tr>
                        <td>{{ $q->name }}</td>
                        <td>{{ $q->pivot->logged_in ? 'Logged in' : 'Logged out' }}</td>
                        <td>
                            <form method="POST" action="{{ route('my-queues.update', $q) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="logged_in" value="{{ $q->pivot->logged_in ? 0 : 1 }}">
                                <button class="btn {{ $q->pivot->logged_in ? 'ghost' : '' }} small" type="submit">{{ $q->pivot->logged_in ? 'Log out' : 'Log in' }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
@endsection
