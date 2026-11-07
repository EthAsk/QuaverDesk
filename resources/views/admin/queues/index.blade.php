@extends('layouts.app')
@section('title', 'Call queues')
@section('content')
    <div class="page-head row" style="justify-content:space-between">
        <div>
            <h1>Call queues</h1>
            <p class="muted">Callers wait their turn with hold music and hear their place in the queue, while the agents logged in to it are rung.</p>
        </div>
        <a class="btn" href="{{ route('admin.queues.create') }}">New call queue</a>
    </div>

    <div class="card">
        @if ($queues->isEmpty())
            <p class="muted">No call queues yet.</p>
        @else
            <table>
                <thead><tr><th>Name</th><th>Extension</th><th>Waiting now</th><th>Agents logged in</th><th>Longest wait</th><th>Then</th><th></th></tr></thead>
                <tbody>
                @foreach ($queues as $q)
                    <tr>
                        <td><a href="{{ route('admin.queues.edit', $q) }}">{{ $q->name }}</a></td>
                        <td>{{ $q->extension ?? '—' }}</td>
                        <td>{{ $q->waiting_count }}</td>
                        <td>{{ $q->agents_count }} of {{ $q->members_count }}</td>
                        <td>{{ intdiv($q->max_wait_seconds, 60) }} min</td>
                        <td>{{ $q->overflow ? $catalog->label($q->overflow) : 'Unavailable message' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.queues.destroy', $q) }}" data-confirm="Delete call queue {{ $q->name }}?">
                                @csrf @method('DELETE')
                                <button class="btn ghost" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="small muted" style="margin-top:12px">Agents log in and out on their dashboard, or by dialling <strong>*45</strong> from any of their phones.</p>
        @endif
    </div>
@endsection
