<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Stop messages · Soroti University alumni</title>
    <style>
        :root { color-scheme: light dark; --brand: #12355b; }
        body { font: 16px/1.5 system-ui, sans-serif; margin: 0; padding: 24px 16px; background: Canvas; color: CanvasText; }
        main { max-width: 420px; margin: 8vh auto; }
        h1 { font-size: 22px; }
        button { font: inherit; font-weight: 600; width: 100%; min-height: 48px; border: 0; border-radius: 8px; background: var(--brand); color: #fff; cursor: pointer; }
        .muted { opacity: .75; font-size: 14px; }
    </style>
</head>
<body>
<main>
    @if ($done)
        <h1>You won't get any more messages</h1>
        <p>We've stopped SMS and WhatsApp messages from the Soroti University alumni system to this number.</p>
        <p class="muted">You can turn messages back on any time in the alumni app, under your profile. Your record is unchanged.</p>
    @else
        <h1>Stop messages from Soroti University alumni?</h1>
        <p>Hi {{ $profile->first_name }}. Press the button to stop SMS and WhatsApp messages (surveys and reminders).</p>
        <form method="post" action="{{ url('/u/'.$token) }}">
            @csrf
            <button type="submit">Yes, stop messages</button>
        </form>
        <p class="muted">Nothing happens unless you press the button.</p>
    @endif
</main>
</body>
</html>
