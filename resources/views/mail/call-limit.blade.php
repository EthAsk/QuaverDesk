<!DOCTYPE html>
<html lang="en-GB">
<body style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color:#14213d; line-height:1.5; max-width:560px; margin:0 auto; padding:24px">
    <h2 style="margin:0 0 12px">An outside call was blocked</h2>
    <p>Hello {{ $admin->name }}, {{ config('app.name') }} refused a call to protect you from an unexpected phone bill:</p>
    <table style="border-collapse:collapse;margin-bottom:16px">
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">Who</td><td>{{ $caller->name }} ({{ $caller->email }})</td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">Why</td><td>{{ $limit }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">When</td><td>{{ $when }}</td></tr>
    </table>
    <p>If that's a normal busy day, you can raise the limits under <a href="{{ $rules }}" style="color:#0f766e">Calling rules</a>.</p>
    <p><strong>If it looks wrong</strong> (calls nobody remembers making, unusual destinations, calls at night), check <a href="{{ $calls }}" style="color:#0f766e">Call history</a>, then change that person's password: it also signs their phones out.</p>
    <p style="color:#5d6578;font-size:13px">You'll get this at most once a day for each limit and person.</p>
</body>
</html>
