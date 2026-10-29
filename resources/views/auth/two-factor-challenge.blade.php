@extends('layouts.guest')
@section('title', 'Two-factor check')
@section('content')
    <h1>Two-factor check</h1>
    <p class="muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
    <form method="POST" action="{{ route('two-factor.login') }}">
        @csrf
        <div class="field">
            <label for="code">Authenticator code</label>
            <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus>
            @error('code')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="recovery_code">Or a recovery code</label>
            <input id="recovery_code" type="text" name="recovery_code" autocomplete="off">
            @error('recovery_code')<div class="error">{{ $message }}</div>@enderror
        </div>
        <button class="btn call-btn" type="submit">Continue</button>
    </form>
@endsection
