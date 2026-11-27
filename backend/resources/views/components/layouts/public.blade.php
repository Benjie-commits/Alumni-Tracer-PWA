@props(['title' => null, 'wide' => false, 'robots' => 'noindex, nofollow', 'hero' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ $robots }}">
    <meta name="theme-color" content="#123d29">
    <title>{{ $title ?? 'Graduate verification' }} · Soroti University</title>
    <link rel="icon" href="/app/favicon.svg" type="image/svg+xml">
    <link rel="preload" href="/fonts/Fraunces-normal.woff2" as="font" type="font/woff2" crossorigin>
    <style>
        @font-face { font-family: 'Fraunces'; font-style: normal; font-weight: 300 700; font-display: swap; src: url('/fonts/Fraunces-normal.woff2') format('woff2'); }
        @font-face { font-family: 'Instrument Sans'; font-style: normal; font-weight: 400 700; font-display: swap; src: url('/fonts/InstrumentSans-normal.woff2') format('woff2'); }
        :root {
            --bg: #f3f5ef; --panel: #ffffff; --ink: #13211a; --muted: #58665c; --line: #dbe3d6; --brand: #1e5b3c; --link: #1e5b3c;
            --gold: #d9a520; --ok: #1b7a43; --ok-bg: #e4f4ea; --warn: #7a5200; --warn-bg: #fbefd0; --bad: #a3271f;
            --bar: #123d29; --bar-ink: #eef4ee; --ease: cubic-bezier(.22, 1, .36, 1);
            --display: 'Fraunces', Georgia, 'Times New Roman', serif;
            color-scheme: light;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0c1511; --panel: #121f19; --ink: #e6ece6; --muted: #9aab9f; --line: #26382e; --brand: #2f8a5a; --link: #86d3a6;
                    --gold: #e8bb45; --ok: #62c48b; --ok-bg: #16301f; --warn: #e5b652; --warn-bg: #33290f; --bad: #f0827a; --bar: #08120d; color-scheme: dark; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 16px/1.6 'Instrument Sans', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); -webkit-font-smoothing: antialiased; }
        ::selection { background: color-mix(in srgb, var(--gold) 35%, transparent); }
        a { color: var(--link); text-underline-offset: 3px; }
        .site-head { background: var(--bar); color: var(--bar-ink); }
        .site-head .in { max-width: {{ $wide ? '1040px' : '680px' }}; margin: 0 auto; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .site-head a { color: inherit; text-decoration: none; }
        .site-head .brand { font-family: var(--display); font-weight: 600; font-size: 19px; display: inline-flex; align-items: center; gap: 9px; }
        .site-head .brand::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--gold); }
        .site-head .brand small { font: 400 13px/1 'Instrument Sans', system-ui, sans-serif; opacity: .7; }
        .site-head nav { display: flex; gap: 18px; font-size: 14px; }
        .site-head nav a { opacity: .8; transition: opacity .15s var(--ease); }
        .site-head nav a:hover { opacity: 1; text-decoration: underline; text-underline-offset: 4px; }
        main { max-width: {{ $wide ? '1040px' : '680px' }}; margin: 0 auto; padding: 28px 20px 72px; }
        h1 { font-family: var(--display); font-weight: 600; font-size: 30px; line-height: 1.12; letter-spacing: -.012em; margin: 6px 0 6px; text-wrap: balance; }
        h2 { font-size: 17px; font-weight: 650; margin: 0 0 10px; text-wrap: balance; }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 20px; margin: 18px 0; box-shadow: 0 1px 2px rgba(18,34,24,.05), 0 2px 8px rgba(18,34,24,.04); }
        .muted { color: var(--muted); }
        .small { font-size: 13px; }
        label { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; font-size: 14px; font-weight: 500; color: var(--muted); }
        input, select, textarea { font: inherit; color: var(--ink); background: var(--bg); border: 1px solid color-mix(in srgb, var(--line) 65%, var(--muted)); border-radius: 10px; padding: 10px 14px; min-height: 48px; width: 100%; transition: border-color .15s var(--ease), box-shadow .15s var(--ease); }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 25%, transparent); }
        button:focus-visible, a:focus-visible { outline: 3px solid color-mix(in srgb, var(--brand) 55%, transparent); outline-offset: 2px; }
        button, .btn { font: inherit; font-weight: 600; min-height: 50px; padding: 10px 20px; border: 0; border-radius: 12px; background: var(--brand); color: #fff; cursor: pointer; width: 100%; transition: transform .12s var(--ease); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
        button:active, .btn:active { transform: scale(.97); }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
        .result { border-radius: 14px; padding: 18px 20px; margin: 18px 0; }
        .result.verified { background: var(--ok-bg); color: var(--ok); }
        .result.other { background: var(--warn-bg); color: var(--warn); }
        .result h2 { margin: 0 0 6px; }
        .result p { margin: 0 0 6px; }
        .error { color: var(--bad); font-size: 13px; }
        .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        .site-foot { max-width: {{ $wide ? '1040px' : '680px' }}; margin: 0 auto; padding: 0 20px 40px; color: var(--muted); font-size: 13px; }
        @media (max-width: 420px) { .row { grid-template-columns: 1fr; } .site-head nav { gap: 12px; } .site-head .brand small { display: none; } }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } button:active { transform: none; } }
    
/* Texture: fine paper grain on the page, a faint woven diagonal on the dark bars */
body { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 .1 0 0 0 0 .25 0 0 0 0 .15 0 0 0 .07 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E"); }
.site-head { background-image: repeating-linear-gradient(45deg, rgba(232,187,69,.07) 0 1px, transparent 1px 12px), repeating-linear-gradient(-45deg, rgba(232,187,69,.04) 0 1px, transparent 1px 12px); }
    </style>
    <link rel="stylesheet" href="/css/palette.css">
    <script>try{if(localStorage.getItem("sunates.palette")==="coffee")document.documentElement.dataset.palette="coffee"}catch(e){}</script>
    {{ $head ?? '' }}
</head>
<body>
<header class="site-head">
    <div class="in">
        <a class="brand" href="{{ route('home') }}">Soroti University <small>Alumni</small></a>
        <nav aria-label="Site">
            <a href="{{ route('alumni.public') }}">Graduates</a>
            <a href="{{ route('verify.show') }}">Verify a graduate</a>
            <a href="/app/login">Sign in</a>
        </nav>
    </div>
</header>
{{ $hero ?? '' }}
<main>
    {{ $slot }}
</main>
<footer class="site-foot">Soroti University · Arapai, Soroti</footer>
</body>
</html>
