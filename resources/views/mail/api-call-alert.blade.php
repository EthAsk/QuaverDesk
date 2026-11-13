<!DOCTYPE html>
<html lang="en-GB">
<body style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color:#14213d; line-height:1.5; max-width:560px; margin:0 auto; padding:24px">
    <h2 style="margin:0 0 12px">{{ ucfirst($what) }}</h2>
    <p>This happened at {{ $when }} (UK time) with calls placed over your {{ config('app.name') }} API.</p>
    <p>If you expected it, there's nothing to do. If not, remove the token on the <a href="{{ $link }}" style="color:#0f766e">API page</a>, or pause calling there while you look.</p>
</body>
</html>
