@extends('layouts.guest')
@section('title', 'Confirm password')
@section('content')
    <h1>Confirm your password</h1>
    <p class="muted">This is a protected area. Please confirm your password to continue.</p>
    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <div class="field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autofocus autocomplete="current-password">
            @error('password')<div class="error">{{ $message }}</div>@enderror
        </div>
        <button class="btn call-btn" type="submit">Confirm</button>
    </form>
@endsection
