@extends('layouts.guest')
@section('title', 'Operator sign in')
@section('content')
    <h1>Authenticator code</h1>
    <p class="muted">Enter the 6-digit code from your authenticator app.</p>
    <form method="POST" action="{{ route('operator.two-factor.submit') }}">
        @csrf
        <div class="field">
            <label for="code">Code</label>
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
            @error('code')<div class="error">{{ $message }}</div>@enderror
        </div>
        <button class="btn call-btn" type="submit">Sign in</button>
    </form>
@endsection
