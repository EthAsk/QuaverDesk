@extends('layouts.app')
@section('title', 'Voicemail boxes')
@section('content')
    <div class="page-head row" style="justify-content:space-between">
        <div>
            <h1>Voicemail boxes</h1>
            <p class="muted">Messages are emailed to the box's members. Point unanswered calls at a box, or use one as a destination for a number, ring group or IVR option.</p>
        </div>
        <a class="btn" href="{{ route('admin.mailboxes.create') }}">New voicemail box</a>
    </div>

    <div class="card">
        @if ($mailboxes->isEmpty())
            <p class="muted">No voicemail boxes yet. Without one, unanswered calls hear "nobody is available".</p>
        @else
            <table>
                <thead><tr><th>Name</th><th>Emails go to</th><th>Missed calls for</th><th>Audio attached</th><th></th></tr></thead>
                <tbody>
                @foreach ($mailboxes as $m)
                    <tr>
                        <td><a href="{{ route('admin.mailboxes.edit', $m) }}">{{ $m->name }}</a></td>
                        <td>{{ $m->members_count }} {{ \Illuminate\Support\Str::plural('person', $m->members_count) }}{{ $m->extra_emails ? ' + '.count($m->extra_emails).' other' : '' }}</td>
                        <td>{{ $m->owners_count }} {{ \Illuminate\Support\Str::plural('person', $m->owners_count) }}</td>
                        <td>{{ $m->attach_audio ? 'Yes' : 'No' }}{{ $m->delete_after_send ? ', then deleted' : '' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.mailboxes.destroy', $m) }}" data-confirm="Delete voicemail box {{ $m->name }}?">
                                @csrf @method('DELETE')
                                <button class="btn ghost" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
