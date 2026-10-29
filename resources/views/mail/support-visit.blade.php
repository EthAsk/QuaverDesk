<!DOCTYPE html>
<html lang="en-GB">
<body style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color:#14213d; line-height:1.5; max-width:560px; margin:0 auto; padding:24px">
    <h2 style="margin:0 0 12px">{{ config('app.name') }} support signed in to your account</h2>
    <p>Hello {{ $owner->name }}, a member of our support team has signed in to your phone system:</p>
    <table style="border-collapse:collapse;margin-bottom:16px">
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">Who</td><td>{{ $operator }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">Signed in as</td><td>{{ $as->name }} ({{ $as->email }})</td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">Why</td><td>{{ $reason }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:#5d6578">When</td><td>{{ $from }}, ending by {{ $until }} at the latest</td></tr>
    </table>
    <p>If you didn't expect this, reply to this email or contact support straight away.</p>
</body>
</html>
