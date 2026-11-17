<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Choose your password · SUN-ATES</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    @include('partials.theme')
    @livewireStyles
</head>
<body>
<main class="login">
    <h1>{{ $forced ? 'Welcome, '.strtok(auth()->user()->name, ' ').'!' : 'Change your password' }}</h1>
    @if ($forced)
        <p class="muted">You signed in with a temporary password. Choose your own to continue. Only you will know it.</p>
    @endif

    <form method="post" action="{{ route('admin.password.update') }}" class="panel" novalidate>
        @csrf
        @method('put')

        @if ($errors->any())
            <div class="alert bad" role="alert">{{ $errors->first() }}</div>
        @endif

        <label class="field">
            <span>{{ $forced ? 'Temporary password' : 'Current password' }}</span>
            <x-password-input name="current_password" autocomplete="current-password" required autofocus />
        </label>
        <label class="field">
            <span>New password</span>
            <x-password-input name="password" required minlength="10" aria-describedby="pw-hint" />
            <small id="pw-hint" class="muted">At least 10 characters. A short sentence is easy to remember.</small>
        </label>
        <label class="field">
            <span>Type the new password again</span>
            <x-password-input name="password_confirmation" required />
        </label>
        <button type="submit" style="width:100%">Save and continue</button>
    </form>

    <form method="post" action="{{ route('admin.logout') }}" style="text-align:center">
        @csrf
        <button type="submit" class="secondary">Sign out instead</button>
    </form>
</main>
@livewireScripts
</body>
</html>
