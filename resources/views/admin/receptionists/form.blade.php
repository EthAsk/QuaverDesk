@extends('layouts.app')
@section('title', $receptionist->exists ? $receptionist->name : 'New AI receptionist')
@section('content')
    <div class="page-head">
        <h1>{{ $receptionist->exists ? $receptionist->name : 'New AI receptionist' }}</h1>
        <p class="muted"><a href="{{ route('admin.receptionists') }}">All receptionists</a></p>
    </div>

    <form method="POST" action="{{ $receptionist->exists ? route('admin.receptionists.update', $receptionist) : route('admin.receptionists.store') }}">
        @csrf
        @if ($receptionist->exists) @method('PUT') @endif

        <div class="card">
            <div class="row" style="align-items:flex-start">
                <div class="field" style="flex:1;min-width:200px">
                    <label for="name">Name</label>
                    <input id="name" type="text" name="name" value="{{ old('name', $receptionist->name) }}" placeholder="Main line" required>
                    @error('name')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field" style="flex:1;min-width:200px">
                    <label for="business">Answers for</label>
                    <input id="business" type="text" name="business" value="{{ old('business', $receptionist->business) }}" required>
                    <div class="small muted">The business name it uses on calls.</div>
                    @error('business')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="field">
                <label for="greeting">How it answers</label>
                <textarea id="greeting" name="greeting" rows="2" required>{{ old('greeting', $receptionist->greeting) }}</textarea>
                <div class="small muted">Read aloud in the voice chosen under <a href="{{ route('admin.voice') }}">Voice</a>. End with a question, so callers know to speak.</div>
                @error('greeting')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="about">What it can tell callers</label>
                <textarea id="about" name="about" rows="6" placeholder="Opening hours, address and parking, what you do and don't do, how to book, common questions and answers…">{{ old('about', $receptionist->about) }}</textarea>
                <div class="small muted">It only states facts from here. Anything else, it offers to put the caller through or take a message. Don't put anything here you wouldn't tell any caller.</div>
                @error('about')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card">
            <h2>Who it can put callers through to</h2>
            <p class="small muted">It picks one from what the caller asks for, using the name and description. It can never dial anything else.</p>
            <table>
                <thead><tr><th style="width:24%">Name</th><th>When to choose it</th><th style="width:30%">Puts callers through to</th></tr></thead>
                <tbody>
                @php($routes = array_values(old('routes', (array) $receptionist->routes)))
                @for ($i = 0; $i < \App\Models\Receptionist::MAX_ROUTES; $i++)
                    <tr>
                        <td><input type="text" name="routes[{{ $i }}][name]" value="{{ $routes[$i]['name'] ?? '' }}" placeholder="{{ $i === 0 ? 'Sales' : ($i === 1 ? 'Support' : '') }}" aria-label="Option {{ $i + 1 }} name"></td>
                        <td><input type="text" name="routes[{{ $i }}][about]" value="{{ $routes[$i]['about'] ?? '' }}" placeholder="{{ $i === 0 ? 'New orders, quotes and prices' : ($i === 1 ? 'Problems with something we supplied' : '') }}" aria-label="Option {{ $i + 1 }} description"></td>
                        <td>
                            @include('partials.destination-select', ['name' => "routes[$i][to]", 'options' => $options, 'selected' => $routes[$i]['to'] ?? null, 'empty' => 'Not used'])
                            @error("routes.$i.to")<div class="error">{{ $message }}</div>@enderror
                        </td>
                    </tr>
                @endfor
                </tbody>
            </table>
        </div>

        <div class="card">
            <div class="field" style="max-width:360px">
                <label for="mailbox_id">Takes messages for</label>
                <select id="mailbox_id" name="mailbox_id">
                    <option value="">Doesn't take messages</option>
                    @foreach ($mailboxes as $id => $name)
                        <option value="{{ $id }}" @selected((int) old('mailbox_id', $receptionist->mailbox_id) === $id)>{{ $name }}</option>
                    @endforeach
                </select>
                <div class="small muted">Messages arrive like a voicemail (written, no audio), emailed to the mailbox's members.</div>
                @error('mailbox_id')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="fallback">If it can't help, or the AI provider has a problem</label>
                @include('partials.destination-select', ['name' => 'fallback', 'options' => $options, 'selected' => old('fallback', $receptionist->fallback), 'empty' => 'Play "unavailable" message and hang up'])
                @error('fallback')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field" style="max-width:240px">
                <label for="max_turns">Most replies from the caller</label>
                <input id="max_turns" type="text" inputmode="numeric" name="max_turns" value="{{ old('max_turns', $receptionist->max_turns) }}" required>
                <div class="small muted">2 to 10. Then it puts them through or takes a message.</div>
                @error('max_turns')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <p class="small muted">Each reply the caller gives goes to your transcription provider, then the conversation goes to your summaries provider to decide what to say, so expect a pause of a second or two between turns.</p>
        <button class="btn" type="submit">Save receptionist</button>
    </form>
@endsection
