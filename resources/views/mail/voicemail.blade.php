<!DOCTYPE html>
<html lang="en-GB">
<body style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color:#14213d; line-height:1.5; max-width:560px; margin:0 auto; padding:24px">
    <h2 style="margin:0 0 4px">New voicemail</h2>
    <p style="margin:0 0 16px;color:#5d6578">{{ $mailbox->name }}</p>

    <table style="border-collapse:collapse;margin-bottom:16px">
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">From</td><td>{{ $recording->from ?: 'Unknown' }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">To</td><td>{{ $recording->to ?: '—' }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">When</td><td>{{ $recording->created_at->timezone('Europe/London')->format('D j M Y, H:i') }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">Length</td><td>{{ $recording->duration_seconds ? gmdate('i:s', $recording->duration_seconds) : '—' }}</td></tr>
    </table>

    @if ($recording->status === 'failed')
        <p style="background:#fef3e2;padding:12px;border-radius:8px">The audio for this message could not be saved. The caller's number is above.</p>
    @endif

    @if ($showText && $recording->summary)
        <h3 style="margin:16px 0 4px">Summary</h3>
        <p>{{ $recording->summary }}</p>
    @endif

    @if ($showText && $recording->action_items)
        <h3 style="margin:16px 0 4px">To do</h3>
        <ul>@foreach ($recording->action_items as $item)<li>{{ $item }}</li>@endforeach</ul>
    @endif

    @if ($showText && $recording->transcript)
        <h3 style="margin:16px 0 4px">Transcript</h3>
        <p style="white-space:pre-wrap">{{ $recording->transcript }}</p>
        <p style="font-size:12px;color:#5d6578">Transcripts and summaries are produced automatically and may contain mistakes.</p>
    @endif

    <p style="margin-top:24px">
        <a href="{{ $link }}" style="background:#0f766e;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Open in the phone system</a>
    </p>
    @if ($hasAudio)
        <p style="font-size:12px;color:#5d6578">The recording is attached as a WAV file.</p>
    @endif
</body>
</html>
