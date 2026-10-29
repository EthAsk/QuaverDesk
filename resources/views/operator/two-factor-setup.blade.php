@extends('layouts.guest')
@section('title', 'Set up two-factor')
@section('content')
    <h1>Set up two-factor</h1>
    <p class="muted">Operator accounts always use two-factor. Scan this with your authenticator app, then enter the code it shows.</p>
    <div style="background:#fff;padding:8px;display:inline-block;border-radius:8px">{!! $qr !!}</div>
    <p class="small">Can't scan it? Enter this key: <code>{{ $secret }}</code></p>
    <form method="POST" action="{{ route('operator.two-factor.submit') }}">
        @csrf
        <div class="field">
            <label for="code">Code</label>
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
            @error('code')<div class="error">{{ $message }}</div>@enderror
        </div>
        <button class="btn call-btn" type="submit">Turn on and sign in</button>
    </form>
@endsection
