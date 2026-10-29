@extends('layouts.operator')
@section('title', 'Audit log')
@section('content')
    <div class="page-head">
        <h1>Audit log</h1>
        <p class="muted">Everything operators do, in the console or on the command line. Kept when a company is deleted.</p>
    </div>
    <div class="card">
        @include('operator.partials.audit-table', ['entries' => $entries])
        {{ $entries->links('pagination::simple-default') }}
    </div>
@endsection
