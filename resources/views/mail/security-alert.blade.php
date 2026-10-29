<!DOCTYPE html>
<html lang="en-GB">
<body style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color:#14213d; line-height:1.5; max-width:560px; margin:0 auto; padding:24px">
    <h2 style="margin:0 0 12px">{{ $what }}</h2>
    <p>Hello {{ $user->name }}, this happened on your {{ config('app.name') }} account:</p>
    <table style="border-collapse:collapse;margin-bottom:16px">
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">What</td><td>{{ $what }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">When</td><td>{{ $when }}</td></tr>
        @if ($ip)<tr><td style="padding:4px 12px 4px 0;color:#5d6578">From</td><td>{{ $ip }}</td></tr>@endif
    </table>
    <p>If this was you, there's nothing to do.</p>
    <p><strong>If it wasn't you</strong>, change your password straight away on the <a href="{{ $link }}" style="color:#0f766e">Security page</a> and tell your administrator.</p>
</body>
</html>
