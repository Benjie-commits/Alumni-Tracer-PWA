<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Graduate verification' }} · Soroti University</title>
    <style>
        :root {
            --bg: #f4f6f9; --panel: #fff; --ink: #1b2430; --muted: #5d6b7c; --line: #d3dae3; --brand: #12355b;
            --ok: #1b7a43; --ok-bg: #e4f4ea; --warn: #7a5200; --warn-bg: #fdf1d6; --bad: #a3271f;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #101720; --panel: #18212d; --ink: #e6ebf2; --muted: #9aa7b8; --line: #2f3a49; --brand: #4a86c8;
                    --ok: #62c48b; --ok-bg: #16301f; --warn: #e5b652; --warn-bg: #33290f; --bad: #f0827a; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); }
        header { background: #12355b; color: #fff; padding: 14px 16px; }
        header strong { letter-spacing: .02em; }
        header span { display: block; font-size: 13px; opacity: .8; }
        main { max-width: 640px; margin: 0 auto; padding: 20px 16px 60px; }
        h1 { font-size: 22px; margin: 6px 0 4px; }
        h2 { font-size: 17px; margin: 0 0 10px; }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 16px; margin: 16px 0; }
        .muted { color: var(--muted); }
        .small { font-size: 13px; }
        label { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; font-size: 14px; color: var(--muted); }
        input, select, textarea { font: inherit; color: var(--ink); background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; min-height: 46px; width: 100%; }
        input:focus, select:focus, textarea:focus, button:focus-visible { outline: 3px solid color-mix(in srgb, var(--brand) 55%, transparent); outline-offset: 1px; }
        button { font: inherit; font-weight: 600; min-height: 48px; padding: 10px 18px; border: 0; border-radius: 8px; background: var(--brand); color: #fff; cursor: pointer; width: 100%; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
        .result { border-radius: 10px; padding: 16px; margin: 16px 0; }
        .result.verified { background: var(--ok-bg); color: var(--ok); }
        .result.other { background: var(--warn-bg); color: var(--warn); }
        .result h2 { margin: 0 0 6px; }
        .result p { margin: 0 0 6px; }
        .error { color: var(--bad); font-size: 13px; }
        .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        @media (max-width: 420px) { .row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header>
    <strong>Soroti University</strong>
    <span>Graduate verification</span>
</header>
<main>
    {{ $slot }}
</main>
</body>
</html>
