<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate MediGuide account</title>
    <style>
        body { font-family: Georgia, serif; background: #f4f7f5; color: #123; margin: 0; padding: 2rem; }
        .card { max-width: 28rem; margin: 2rem auto; background: #fff; padding: 1.5rem 1.75rem; border: 1px solid #cfd8d3; }
        label { display: block; margin: 0.85rem 0 0.35rem; font-size: 0.95rem; }
        input { width: 100%; padding: 0.55rem 0.65rem; box-sizing: border-box; }
        button { margin-top: 1.25rem; padding: 0.65rem 1rem; background: #0b5c4a; color: #fff; border: 0; cursor: pointer; }
        .muted { color: #456; font-size: 0.9rem; }
        .error { color: #8a1f1f; margin: 0.75rem 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Activate your account</h1>
        <p class="muted">Invitation for {{ $emailHint }}. Expires {{ $expiresAt->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}.</p>

        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('account.activate.store', $expiresAt, ['token' => $token]) }}">
            @csrf
            <label for="password">New password</label>
            <input id="password" type="password" name="password" autocomplete="new-password" required>

            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>

            <button type="submit">Activate account</button>
        </form>
    </div>
</body>
</html>
