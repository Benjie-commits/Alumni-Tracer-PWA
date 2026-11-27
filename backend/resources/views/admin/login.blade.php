<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Staff sign-in · SUN Alumni</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('css/palette.css') }}">
    @include('partials.theme')
</head>
<body>
<div class="login">
    <h1>SUN Alumni staff console</h1>
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
    <button type="button" class="secondary" data-theme-toggle onclick="sunatesToggleTheme()">Light / dark</button>
    <button type="button" class="secondary" data-palette-toggle onclick="sunatesTogglePalette()">Palette</button>
</div>
</body>
</html>
