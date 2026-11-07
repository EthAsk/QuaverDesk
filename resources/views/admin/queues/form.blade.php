@extends('layouts.app')
@section('title', $queue->exists ? $queue->name : 'New call queue')
@section('content')
    <div class="page-head">
        <h1>{{ $queue->exists ? $queue->name : 'New call queue' }}</h1>
        <p class="muted"><a href="{{ route('admin.queues') }}">All call queues</a></p>
    </div>

    <form method="POST" action="{{ $queue->exists ? route('admin.queues.update', $queue) : route('admin.queues.store') }}">
        @csrf
        @if ($queue->exists) @method('PUT') @endif

        <div class="card">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $queue->name) }}" placeholder="Support" required>
                @error('name')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="extension">Extension <span class="muted">(optional)</span></label>
                <input id="extension" type="text" inputmode="numeric" name="extension" value="{{ old('extension', $queue->extension) }}" placeholder="600" maxlength="5">
                <div class="small muted">A short number phones can dial, or transfer a call to, to put it in this queue.</div>
                @error('extension')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="greeting">Greeting <span class="muted">(optional)</span></label>
                <input id="greeting" type="text" name="greeting" value="{{ old('greeting', $queue->greeting) }}" maxlength="500"
                       placeholder="Thank you for calling support. Please hold and we'll be with you as soon as we can.">
                <div class="small muted">Read out once, when the caller joins the queue.</div>
                @error('greeting')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card">
            <h2>While callers wait</h2>
            <div class="grid">
                <div class="field">
                    <label for="hold_sound_id">Hold music</label>
                    <select id="hold_sound_id" name="hold_sound_id">
                        <option value="">The company's hold music</option>
                        @foreach ($sounds as $s)
                            <option value="{{ $s->id }}" @selected((string) old('hold_sound_id', $queue->hold_sound_id) === (string) $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                    <div class="small muted">Upload music under <a href="{{ route('admin.sounds') }}">Sounds &amp; hold music</a>.</div>
                </div>
                <div class="field">
                    <label for="ring_seconds">Ring agents for (seconds)</label>
                    <input id="ring_seconds" type="text" inputmode="numeric" name="ring_seconds" value="{{ old('ring_seconds', $queue->ring_seconds) }}" required>
                    <div class="small muted">Each time it's the caller's turn. If nobody answers, they hold again and are rung again.</div>
                    @error('ring_seconds')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <label style="font-weight:normal">
                <input type="hidden" name="announce_position" value="0">
                <input type="checkbox" name="announce_position" value="1" @checked(old('announce_position', $queue->announce_position))>
                Tell callers their place in the queue (when they join, when it changes, and every minute)
            </label>
        </div>

        <div class="card">
            <h2>Agents</h2>
            <p class="small muted">Tick who takes this queue's calls. Free agents ring together; anyone already on a call is skipped.
                Agents log in and out on their dashboard or by dialling <strong>*45</strong>.</p>
            <table>
                <thead><tr><th>Agent</th><th>Person</th><th>Logged in</th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    @php($in = in_array($u->id, old('members', array_keys($members))))
                    <tr>
                        <td><input type="checkbox" name="members[]" value="{{ $u->id }}" @checked($in) aria-label="{{ $u->name }} is an agent"></td>
                        <td>{{ $u->name }} <span class="muted small">{{ $u->dialable() }}</span></td>
                        <td class="small">
                            @if (array_key_exists($u->id, $members))
                                {{ $members[$u->id] ? 'Yes' : 'No' }}
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @error('members')<div class="error">{{ $message }}</div>@enderror
        </div>

        <div class="card">
            <div class="grid">
                <div class="field">
                    <label for="max_wait_minutes">Longest wait (minutes)</label>
                    <input id="max_wait_minutes" type="text" inputmode="numeric" name="max_wait_minutes" value="{{ old('max_wait_minutes', intdiv((int) $queue->max_wait_seconds, 60)) }}" required>
                    @error('max_wait_minutes')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="overflow">Then</label>
                    @include('partials.destination-select', ['name' => 'overflow', 'options' => $options, 'selected' => old('overflow', $queue->overflow), 'empty' => 'Play "unavailable" message and hang up'])
                    @error('overflow')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <label style="font-weight:normal">
                <input type="hidden" name="overflow_when_empty" value="0">
                <input type="checkbox" name="overflow_when_empty" value="1" @checked(old('overflow_when_empty', $queue->overflow_when_empty))>
                Go there straight away when no agent is logged in
            </label>
        </div>

        <button class="btn" type="submit">Save call queue</button>
    </form>

    @if ($queue->exists && $members)
        <div class="card">
            <h2>Log agents in or out</h2>
            <table>
                @foreach ($users->whereIn('id', array_keys($members)) as $u)
                    <tr>
                        <td>{{ $u->name }}</td>
                        <td>{{ $members[$u->id] ? 'Logged in' : 'Logged out' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.queues.agent', [$queue, $u]) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="logged_in" value="{{ $members[$u->id] ? 0 : 1 }}">
                                <button class="btn ghost small" type="submit">{{ $members[$u->id] ? 'Log out' : 'Log in' }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
@endsection
