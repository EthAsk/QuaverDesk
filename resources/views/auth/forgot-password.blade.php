@extends('layouts.guest')
@section('title', 'Reset password')
@section('content')
    <h1>Reset your password</h1>
    <p class="muted">We'll email you a reset link if the address belongs to an account.</p>
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
            @error('email')<div class="error">{{ $message }}</div>@enderror
        </div>
        <button class="btn call-btn" type="submit">Send reset link</button>
    </form>
    <p class="small" style="margin-top:16px"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
