<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}SUN Alumni Admin</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('css/palette.css') }}">
    @include('partials.theme')
    @livewireStyles
</head>
<body>
@php($user = auth()->user())
<div class="shell">
    <aside class="side">
        <a class="brand" href="{{ route('home') }}" title="Back to the landing page">SUN Alumni<small>Soroti University · Alumni system</small></a>
        <nav>
            <span class="group">Overview</span>
            <a href="{{ route('admin.dashboard') }}" @class(['active' => request()->routeIs('admin.dashboard')])>Dashboard</a>
            <a href="{{ route('admin.alumni.index') }}" @class(['active' => request()->routeIs('admin.alumni.*')])>Alumni directory</a>
            <a href="{{ route('admin.outcomes') }}" @class(['active' => request()->routeIs('admin.outcomes*')])>Graduate outcomes</a>
            <a href="{{ route('admin.surveys') }}" @class(['active' => request()->routeIs('admin.surveys*')])>Tracer surveys</a>
            @if ($user->canManageRecords())
                <span class="group">Records</span>
            <a href="{{ route('admin.verification') }}" @class(['active' => request()->routeIs('admin.verification')])>
                    Verification queue
                    <livewire:admin.nav-count kind="pending" />
                </a>
                <a href="{{ route('admin.verification-enquiries') }}" @class(['active' => request()->routeIs('admin.verification-enquiries')])>
                    Employer enquiries
                    <livewire:admin.nav-count kind="enquiries" />
                </a>
                <a href="{{ route('admin.notifications') }}" @class(['active' => request()->routeIs('admin.notifications')])>Messages</a>
                <a href="{{ route('admin.follow-up') }}" @class(['active' => request()->routeIs('admin.follow-up')])>Follow-up list</a>
                <span class="group">Engagement</span>
            <a href="{{ route('admin.jobs') }}" @class(['active' => request()->routeIs('admin.jobs')])>Jobs and internships</a>
                <a href="{{ route('admin.events') }}" @class(['active' => request()->routeIs('admin.events')])>Events</a>
                <a href="{{ route('admin.mentorship') }}" @class(['active' => request()->routeIs('admin.mentorship')])>Mentorship</a>
                <span class="group">Data</span>
            <a href="{{ route('admin.import') }}" @class(['active' => request()->routeIs('admin.import')])>Import from spreadsheet</a>
                <a href="{{ route('admin.erp') }}" @class(['active' => request()->routeIs('admin.erp')])>SorotiUniERP sync</a>
            @endif
            @if ($user->hasRole(\App\Enums\RoleSlug::IctAdmin))
                <span class="group">System</span>
            <a href="{{ route('admin.staff') }}" @class(['active' => request()->routeIs('admin.staff')])>Staff accounts</a>
                <a href="{{ route('admin.activity') }}" @class(['active' => request()->routeIs('admin.activity')])>Activity log</a>
            @endif
        </nav>
        <div class="who">
            <span class="who-name">{{ $user->name }}</span>
            <span class="who-role">{{ $user->role?->name }}</span>
            <a href="{{ route('admin.password.edit') }}" class="who-link">Change my password</a>
            <button type="button" data-theme-toggle onclick="sunatesToggleTheme()">Light / dark</button>
            <button type="button" data-palette-toggle onclick="sunatesTogglePalette()">Palette</button>
            <form method="post" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        </div>
    </aside>
    <main class="main">
        {{ $slot }}
    </main>
</div>
@livewireScripts
</body>
</html>
