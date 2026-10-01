<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ isset($title) ? $title.' · ' : '' }}SUN-ATES Admin</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    @livewireStyles
</head>
<body>
@php($user = auth()->user())
<div class="shell">
    <aside class="side">
        <div class="brand">SUN-ATES<small>Soroti University · Alumni system</small></div>
        <nav>
            <a href="{{ route('admin.dashboard') }}" @class(['active' => request()->routeIs('admin.dashboard')])>Dashboard</a>
            <a href="{{ route('admin.alumni.index') }}" @class(['active' => request()->routeIs('admin.alumni.*')])>Alumni directory</a>
            <a href="{{ route('admin.outcomes') }}" @class(['active' => request()->routeIs('admin.outcomes*')])>Graduate outcomes</a>
            <a href="{{ route('admin.surveys') }}" @class(['active' => request()->routeIs('admin.surveys*')])>Tracer surveys</a>
            @if ($user->canManageRecords())
                <a href="{{ route('admin.verification') }}" @class(['active' => request()->routeIs('admin.verification')])>
                    Verification queue
                    @if ($pendingCount = \App\Models\AlumniProfile::where('verification_status', \App\Enums\VerificationStatus::Pending)->count())
                        <span class="pill pending">{{ $pendingCount }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.verification-enquiries') }}" @class(['active' => request()->routeIs('admin.verification-enquiries')])>
                    Employer enquiries
                    @if ($openEnquiries = \App\Models\VerificationEscalation::where('status', \App\Enums\EscalationStatus::Open)->count())
                        <span class="pill pending">{{ $openEnquiries }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.notifications') }}" @class(['active' => request()->routeIs('admin.notifications')])>Messages</a>
                <a href="{{ route('admin.import') }}" @class(['active' => request()->routeIs('admin.import')])>Import from spreadsheet</a>
            @endif
            @if ($user->hasRole(\App\Enums\RoleSlug::IctAdmin))
                <a href="{{ route('admin.staff') }}" @class(['active' => request()->routeIs('admin.staff')])>Staff accounts</a>
            @endif
        </nav>
        <div class="who">
            {{ $user->name }}<br>
            <span style="opacity:.75">{{ $user->role?->name }}</span>
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
