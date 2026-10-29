<!DOCTYPE html>
<html lang="en-GB">
<body style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color:#14213d; line-height:1.5; max-width:560px; margin:0 auto; padding:24px">
    <h2 style="margin:0 0 12px">Welcome, {{ $user->name }}</h2>
    <p>{{ $invitedBy ? $invitedBy.' has' : 'You have been' }} set you up with a phone account on {{ config('app.name') }}. Your extension is <strong>{{ $user->carrierIdentity() }}</strong>.</p>
    <p>Choose a password to get started:</p>
    <p style="margin:20px 0">
        <a href="{{ $link }}" style="background:#0f766e;color:#ffffff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block">Set my password</a>
    </p>
    <p style="color:#5d6578;font-size:14px">This link works once and runs out in {{ $days }} days. If you weren't expecting it, you can ignore this email.</p>
    <p style="color:#5d6578;font-size:12px;word-break:break-all">If the button doesn't work, copy this address into your browser:<br>{{ $link }}</p>
</body>
</html>
