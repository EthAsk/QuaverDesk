@extends('layouts.app')
@section('title', 'IVR menus')
@section('content')
    <div class="page-head row" style="justify-content:space-between">
        <div>
            <h1>IVR menus</h1>
            <p class="muted">"Press 1 for sales" menus. Callers choose with their keypad.</p>
        </div>
        <a class="btn" href="{{ route('admin.ivr.create') }}">New menu</a>
    </div>

    <div class="card">
        @if ($menus->isEmpty())
            <p class="muted">No menus yet.</p>
        @else
            <table>
                <thead><tr><th>Name</th><th>Options</th><th>If no choice</th><th></th></tr></thead>
                <tbody>
                @foreach ($menus as $m)
                    <tr>
                        <td><a href="{{ route('admin.ivr.edit', $m) }}">{{ $m->name }}</a></td>
                        <td>
                            @forelse ($m->options as $digit => $dest)
                                <div class="small"><strong>{{ $digit }}</strong> → {{ $catalog->label($dest) }}</div>
                            @empty
                                <span class="muted">None</span>
                            @endforelse
                        </td>
                        <td>{{ $m->fallback ? $catalog->label($m->fallback) : 'Unavailable message' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.ivr.destroy', $m) }}" data-confirm="Delete menu {{ $m->name }}?">
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
