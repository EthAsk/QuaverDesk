@extends('layouts.guest')
@section('title', 'Sign in')
@push('scripts')
    @vite(['resources/js/passkeys.js'])
@endpush
@section('content')
    <h1>Sign in</h1>
    <form method="POST" action="{{ route('login') }}">
        @csrf
        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username webauthn">
            @error('email')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
            @error('password')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label class="check" style="border:0;padding:0"><input type="checkbox" name="remember"> Keep me signed in</label>
        </div>
        <button class="btn call-btn" type="submit">Sign in</button>
    </form>
    @feature('passkeys')
        <div id="passkey-login" hidden>
            <div class="or"><span>or</span></div>
            <button class="btn ghost call-btn" type="button" id="passkey-login-btn">Sign in with a passkey</button>
            <div class="error" id="passkey-login-error" role="alert" hidden></div>
        </div>
    @endfeature
    <p class="small" style="margin-top:16px">
        <a href="{{ route('password.request') }}">Forgotten your password?</a>
        @if ($canRegister)
            · <a href="{{ route('register') }}">Set up this phone system</a>
        @endif
    </p>
@endsection
