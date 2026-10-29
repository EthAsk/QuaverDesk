@extends('layouts.operator')
@section('title', 'Add a company')
@section('content')
    <div class="page-head"><h1>Add a company</h1></div>
    <form method="POST" action="{{ route('operator.companies.store') }}" class="card">
        @csrf
        <div class="field">
            <label for="name">Company name</label>
            <input id="name" name="name" value="{{ old('name') }}" required maxlength="255">
            @error('name')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="slug">Address</label>
            <div class="row"><input id="slug" name="slug" value="{{ old('slug') }}" required maxlength="32" pattern="[a-z0-9-]+" style="max-width:14em"> <span class="muted">.{{ $base }}</span></div>
            @error('slug')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="domain">Their own web address <span class="muted">(optional, e.g. phones.example.co.uk)</span></label>
            <input id="domain" name="domain" value="{{ old('domain') }}" maxlength="253">
            @error('domain')<div class="error">{{ $message }}</div>@enderror
        </div>
        <h2>Owner</h2>
        <p class="muted small">They get an email with a link to set their password.</p>
        <div class="field">
            <label for="owner_name">Name</label>
            <input id="owner_name" name="owner_name" value="{{ old('owner_name') }}" required maxlength="255">
            @error('owner_name')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="owner_email">Email</label>
            <input id="owner_email" type="email" name="owner_email" value="{{ old('owner_email') }}" required maxlength="255">
            @error('owner_email')<div class="error">{{ $message }}</div>@enderror
        </div>
        <button class="btn" type="submit">Add company</button>
    </form>
@endsection
