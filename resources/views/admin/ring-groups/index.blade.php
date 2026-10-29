@extends('layouts.app')
@section('title', 'Ring groups')
@section('content')
    <div class="page-head row" style="justify-content:space-between">
        <div>
            <h1>Ring groups</h1>
            <p class="muted">Ring several people for one number, all at once or one after another.</p>
        </div>
        <a class="btn" href="{{ route('admin.ring-groups.create') }}">New ring group</a>
    </div>

    <div class="card">
        @if ($groups->isEmpty())
            <p class="muted">No ring groups yet.</p>
        @else
            <table>
                <thead><tr><th>Name</th><th>Extension</th><th>Rings</th><th>Members</th><th>If nobody answers</th><th></th></tr></thead>
                <tbody>
                @foreach ($groups as $g)
                    <tr>
                        <td><a href="{{ route('admin.ring-groups.edit', $g) }}">{{ $g->name }}</a></td>
                        <td>{{ $g->extension ?? '—' }}</td>
                        <td>{{ $g->strategy === 'in_order' ? 'In order' : 'All at once' }}, {{ $g->ring_seconds }}s</td>
                        <td>{{ $g->members_count }}</td>
                        <td>{{ $g->fallback ? $catalog->label($g->fallback) : 'Unavailable message' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.ring-groups.destroy', $g) }}" data-confirm="Delete ring group {{ $g->name }}?">
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
