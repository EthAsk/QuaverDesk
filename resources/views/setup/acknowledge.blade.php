@extends('layouts.app')
@section('title', 'Setup & terms')
@section('content')
    <div class="page-head">
        <h1>Before calling is switched on</h1>
        <p class="muted">Please read and confirm each statement. Your answer is recorded with your name, the time and your IP address.</p>
    </div>

    @if ($accepted)
        <div class="flash ok">These terms have already been accepted for this version. You can accept again if your organisation needs a fresh record.</div>
    @endif

    <form method="POST" action="{{ route('setup.store') }}" class="card">
        @csrf
        @foreach ($items as $key => $text)
            <label class="check">
                <input type="checkbox" name="accept[{{ $key }}]" value="1" @checked(old("accept.$key"))>
                <span>{{ $text }}</span>
            </label>
        @endforeach
        @if ($errors->any())
            <div class="error" style="margin-bottom:12px">Please confirm every statement.</div>
        @endif
        <p class="small muted">Read the full <strong>DISCLAIMER.md</strong> and <strong>LICENSE</strong> in the source code before accepting.</p>
        <button class="btn" type="submit">I confirm all of the above</button>
    </form>
@endsection
