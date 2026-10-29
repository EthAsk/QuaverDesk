@extends('layouts.guest')
@section('title', 'Operator sign in')
@section('content')
    <h1>Operator sign in</h1>
    <form method="POST" action="{{ route('operator.login.submit') }}">
        @csrf
        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            @error('email')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn call-btn" type="submit">Continue</button>
    </form>
@endsection
