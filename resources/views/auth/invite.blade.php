@extends('layouts.guest')
@section('title', 'Set your password')
@section('content')
    <h1>Welcome, {{ $user->name }}</h1>
    <p class="muted">Choose a password for <strong>{{ $user->email }}</strong>. Use at least 12 characters with letters and numbers.</p>
    <form method="POST" action="{{ request()->fullUrl() }}">
        @csrf
        <input type="email" name="email" value="{{ $user->email }}" autocomplete="username" hidden>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autofocus autocomplete="new-password">
            @error('password')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button class="btn call-btn" type="submit">Set password and sign in</button>
    </form>
@endsection
