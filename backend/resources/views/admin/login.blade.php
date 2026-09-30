<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Staff sign-in · SUN-ATES</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body>
<div class="login">
    <h1>SUN-ATES staff console</h1>
    <p class="muted">Soroti University Alumni Tracking, Engagement and Tracer Study Information System</p>

    <form method="post" action="{{ route('admin.login.store') }}" class="panel">
        @csrf

        @if ($errors->any())
            <div class="alert bad">{{ $errors->first() }}</div>
        @endif

        <label class="field">
            <span>Email</span>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>
        <label class="field">
            <span>Password</span>
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit" style="width:100%">Sign in</button>
    </form>

    <p class="muted small">Alumni: use the alumni app instead. This console is for Registrar, ICT and QA staff.</p>
</div>
</body>
</html>
