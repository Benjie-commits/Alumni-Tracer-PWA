<!DOCTYPE html>
<html lang="en" data-page="home">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SUN Alumni · Soroti University Alumni</title>
    <meta name="description" content="Soroti University's alumni and student network: stay connected, find opportunities and help shape the university.">
    <meta name="theme-color" content="#1e5b3c">
    <link rel="icon" href="/app/favicon.svg" type="image/svg+xml">
    <link rel="preload" as="image" href="{{ asset('images/campus.jpg') }}">
    <link rel="stylesheet" href="{{ asset('css/palette.css') }}">
    @include('partials.theme')
    <link rel="preload" href="/fonts/Fraunces-normal.woff2" as="font" type="font/woff2" crossorigin>
    <style>
        @font-face { font-family: 'Fraunces'; font-style: normal; font-weight: 300 700; font-display: swap; src: url('/fonts/Fraunces-normal.woff2') format('woff2'); }
        @font-face { font-family: 'Instrument Sans'; font-style: normal; font-weight: 400 700; font-display: swap; src: url('/fonts/InstrumentSans-normal.woff2') format('woff2'); }
        :root {
            --navy: #1e5b3c; --navy-deep: #0c2c1d; --gold: #d9a520; --bar: #1e5b3c;
            --ink: #13211a; --muted: #58665c; --bg: #f3f5ef; --panel: #ffffff; --line: #dbe3d6;
            --accent: var(--navy); --accent-ink: #fff; --bar-ink: #ffffff;
            color-scheme: light;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) { --ink: #e6ece6; --muted: #9aab9f; --bg: #0c1511; --panel: #121f19; --line: #26382e; --accent: #2f8a5a; --accent-ink: #ffffff; --bar: #1e5b3c; --bar-ink: #ffffff; color-scheme: dark; }
        }
        :root[data-theme="dark"] { --ink: #e6ece6; --muted: #9aab9f; --bg: #0c1511; --panel: #121f19; --line: #26382e; --accent: #2f8a5a; --accent-ink: #ffffff; --bar: #1e5b3c; --bar-ink: #ffffff; color-scheme: dark; }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; font-family: 'Instrument Sans', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); line-height: 1.55; -webkit-font-smoothing: antialiased; }
        .big, .page2 h2, .bar .logo { font-family: 'Fraunces', Georgia, 'Times New Roman', serif; }
        a { color: inherit; }
        .skip { position: absolute; left: -999px; top: 8px; background: var(--panel); padding: 8px 12px; border-radius: 8px; z-index: 50; }
        .skip:focus { left: 8px; }

        /* Top bar: university navy */
        .bar { position: fixed; inset: 0 0 auto 0; z-index: 20; display: flex; align-items: center; justify-content: space-between;
               padding: 12px clamp(16px, 4vw, 40px); background: color-mix(in srgb, var(--bar) 92%, transparent); color: var(--bar-ink);
               backdrop-filter: blur(10px); box-shadow: 0 1px 0 var(--line); }
        .bar .logo { font-weight: 600; font-size: 19px; letter-spacing: .005em; text-decoration: none; }
        .bar .logo small { font-weight: 500; opacity: .8; letter-spacing: 0; margin-left: 6px; }
        .bar nav { display: flex; gap: 8px; align-items: center; }
        .pill-btn { display: inline-flex; align-items: center; min-height: 44px; padding: 0 18px; border-radius: 999px; font-weight: 600; text-decoration: none;
                    border: 1.5px solid currentColor; background: transparent; color: inherit; font: inherit; cursor: pointer; }
        .pill-btn.fill { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
        .bar .pill-btn.fill, .cta .pill-btn.fill { background: #fff; border-color: #fff; color: var(--navy); }
        .pill-btn:focus-visible, a:focus-visible, button:focus-visible { outline: 3px solid var(--gold); outline-offset: 3px; }

        /* Page 1: the campus photo with lines that slide sideways by themselves */
        .hero { position: relative; height: 100vh; height: 100svh; min-height: 520px; overflow: hidden; color: #fff; }
        .photo { position: absolute; inset: 0; background: url('{{ asset('images/campus.jpg') }}') center 35% / cover no-repeat; animation: drift 24s ease-in-out infinite alternate; }
        @keyframes drift { from { transform: scale(1.03) translateX(-1%); } to { transform: scale(1.08) translateX(1%); } }
        .shade { position: absolute; inset: 0; background: radial-gradient(ellipse 62% 48% at 50% 50%, rgba(4,24,14,.66), rgba(4,24,14,0) 100%), linear-gradient(180deg, rgba(5,34,20,.52) 0%, rgba(14,58,36,.46) 55%, rgba(3,16,9,.9) 100%); }
        .viewport { position: absolute; inset: 0; overflow: hidden; touch-action: pan-y; }
        .track { display: flex; height: 100%; transition: transform .9s cubic-bezier(.65,0,.35,1); will-change: transform; }
        .slide { flex: 0 0 100%; display: grid; place-items: center; text-align: center; padding: 60px clamp(20px, 8vw, 110px) 0; }
        .slide > div { max-width: 900px; opacity: .2; transform: scale(.94); transition: opacity .9s ease, transform .9s ease; }
        .slide.on > div { opacity: 1; transform: none; }
        .eyebrow { text-transform: uppercase; letter-spacing: .18em; font-size: clamp(12px, 1.4vw, 14px); opacity: .85; margin: 0 0 14px; }
        .big { text-shadow: 0 2px 24px rgba(2,16,8,.45); font-size: clamp(40px, 8vw, 104px); line-height: 1.02; font-weight: 600; letter-spacing: -.025em; margin: 0; text-wrap: balance; }
        .mid { font-size: clamp(28px, 5vw, 64px); line-height: 1.08; font-weight: 750; letter-spacing: -.015em; margin: 0; text-wrap: balance; }
        .sub { font-size: clamp(16px, 2vw, 21px); opacity: .92; margin: 18px auto 0; max-width: 640px; }
        .accent { color: #f3c95c; }
        .controls { position: absolute; left: 0; right: 0; bottom: clamp(20px, 5vh, 44px); display: flex; justify-content: center; align-items: center; gap: 10px; z-index: 3; }
        .dots { display: flex; }
        .dot { width: 40px; height: 44px; padding: 0; border: 0; background: transparent; cursor: pointer; display: grid; place-items: center; }
        .dot span { display: block; width: 28px; height: 4px; border-radius: 4px; background: rgba(255,255,255,.35); overflow: hidden; }
        .dot span i { display: block; height: 100%; width: 0; background: #fff; }
        .dot.done span i { width: 100%; }
        .dot.on span i { animation: fill var(--dur, 5s) linear forwards; }
        .hero.paused .dot.on span i { animation-play-state: paused; }
        @keyframes fill { to { width: 100%; } }
        .round { width: 44px; height: 44px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,.6); background: rgba(0,0,0,.2); color: #fff; cursor: pointer;
                 display: grid; place-items: center; font: 600 14px/1 system-ui, sans-serif; }
        .round:hover { background: rgba(0,0,0,.4); }
        .down { position: absolute; right: clamp(16px, 4vw, 40px); bottom: clamp(28px, 5vh, 52px); z-index: 3; color: #fff; text-decoration: none; font-size: 13px; letter-spacing: .1em; text-transform: uppercase; opacity: .9; }

        /* Page 2: what you can do + the way in */
        .page2 { position: relative; z-index: 2; background: var(--bg); padding: clamp(56px, 9vw, 120px) clamp(16px, 4vw, 40px); }
        .wrap { max-width: 1080px; margin: 0 auto; }
        .page2 h2 { font-size: clamp(30px, 4.4vw, 52px); line-height: 1.08; letter-spacing: -.015em; margin: 0 0 12px; text-wrap: balance; }
        .lead { color: var(--muted); font-size: clamp(16px, 1.8vw, 19px); max-width: 640px; margin: 0 0 40px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; }
        .tile { background: var(--panel); border: 1px solid var(--line); border-radius: 18px; padding: 22px; opacity: 0; transform: translateY(24px); transition: opacity .7s ease, transform .7s ease; }
        .tile.in { opacity: 1; transform: none; }
        .tile .ic { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; font-size: 22px; background: color-mix(in srgb, var(--accent) 14%, transparent); margin-bottom: 14px; }
        .tile h3 { margin: 0 0 6px; font-size: 18px; }
        .tile p { margin: 0; color: var(--muted); font-size: 15px; }
        .who { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--accent); margin-bottom: 4px; }
        .cta { margin-top: 48px; padding: clamp(24px, 4vw, 40px); border-radius: 22px; background: linear-gradient(135deg, var(--navy) 0%, var(--navy-deep) 100%); color: #fff;
               display: flex; flex-wrap: wrap; gap: 20px; align-items: center; justify-content: space-between; }
        .cta h3 { margin: 0 0 4px; font-size: clamp(22px, 2.6vw, 30px); }
        .cta p { margin: 0; opacity: .85; }
        .cta .btns { display: flex; flex-wrap: wrap; gap: 10px; }
        .navlink { color: inherit; text-decoration: none; font-size: 15px; padding: 0 10px; opacity: .85; } .navlink:hover { opacity: 1; text-decoration: underline; text-underline-offset: 4px; }
        footer .credit { font-size: 12px; opacity: .8; }
        footer { padding: 28px 16px 40px; text-align: center; color: var(--muted); font-size: 14px; }
        footer a { color: var(--accent); }

        /* No motion wanted: no drifting photo or slide animation, and the slides wait for the visitor. */
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .photo { animation: none; }
            .track, .slide > div, .tile { transition: none; }
            .tile { opacity: 1; transform: none; }
        }
        @media (max-width: 560px) { .bar .logo small, .bar nav .hide-sm { display: none; } .down { display: none; } }
    
/* Texture: fine paper grain on the page, a faint woven diagonal on the dark bars */
body { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='2' stitchTiles='stitch'/%3E%3CfeColorMatrix values='0 0 0 0 .1 0 0 0 0 .25 0 0 0 0 .15 0 0 0 .07 0'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E"); }
.bar { background-image: repeating-linear-gradient(45deg, rgba(232,187,69,.07) 0 1px, transparent 1px 12px), repeating-linear-gradient(-45deg, rgba(232,187,69,.04) 0 1px, transparent 1px 12px); }
    </style>
</head>
<body>
<a class="skip" href="#start">Skip to sign in</a>

<header class="bar" id="bar">
    <a class="logo" href="/">SUN Alumni<small>Soroti University</small></a>
    <nav aria-label="Main">
        <a class="navlink hide-sm" href="{{ route('alumni.public') }}">Graduates</a>
        <a class="navlink hide-sm" href="{{ route('verify.show') }}">Verify a credential</a>
        <button type="button" class="pill-btn hide-sm" data-palette-toggle onclick="sunatesTogglePalette()">Palette</button>
        <button type="button" class="pill-btn hide-sm" data-theme-toggle onclick="sunatesToggleTheme()">Light / dark</button>
        <a class="pill-btn fill" href="/app/login">Sign in</a>
    </nav>
</header>

<main>
    <section class="hero" id="hero" aria-roledescription="carousel" aria-label="About SUN Alumni">
        <div class="photo" role="img" aria-label="The main building of Soroti University"></div>
        <div class="shade"></div>

        <div class="viewport">
            <div class="track" id="track">
                <div class="slide on" role="group" aria-roledescription="slide" aria-label="1 of 5">
                    <div>
                        <p class="eyebrow">Soroti University · Alumni &amp; students</p>
                        <h1 class="big">Once Soroti.<br><span class="accent">Always Soroti.</span></h1>
                        <p class="sub">The home of the Soroti University community after the lecture halls.</p>
                    </div>
                </div>
                <div class="slide" role="group" aria-roledescription="slide" aria-label="2 of 5" aria-hidden="true">
                    <div>
                        <p class="mid">Stay connected to the university that shaped you.</p>
                        <p class="sub">Keep your record current in minutes, from any phone, even on a slow connection.</p>
                    </div>
                </div>
                <div class="slide" role="group" aria-roledescription="slide" aria-label="3 of 5" aria-hidden="true">
                    <div>
                        <p class="mid">Find your next step.</p>
                        <p class="sub">Jobs, internships and university events, chosen for your programme.</p>
                    </div>
                </div>
                <div class="slide" role="group" aria-roledescription="slide" aria-label="4 of 5" aria-hidden="true">
                    <div>
                        <p class="mid">Mentor the next generation.<br><span class="accent">Or find a mentor.</span></p>
                        <p class="sub">Graduates guide continuing students into the careers they want.</p>
                    </div>
                </div>
                <div class="slide" role="group" aria-roledescription="slide" aria-label="5 of 5" aria-hidden="true">
                    <div>
                        <p class="mid">Your story helps the next class.</p>
                        <p class="sub">Short tracer surveys show the university what its graduates go on to do.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="controls">
            <button type="button" class="round" id="prev" aria-label="Previous slide">‹</button>
            <div class="dots" role="group" aria-label="Choose a slide">
                <button type="button" class="dot" aria-label="Slide 1"><span><i></i></span></button>
                <button type="button" class="dot" aria-label="Slide 2"><span><i></i></span></button>
                <button type="button" class="dot" aria-label="Slide 3"><span><i></i></span></button>
                <button type="button" class="dot" aria-label="Slide 4"><span><i></i></span></button>
                <button type="button" class="dot" aria-label="Slide 5"><span><i></i></span></button>
            </div>
            <button type="button" class="round" id="next" aria-label="Next slide">›</button>
            <button type="button" class="round" id="pause" aria-label="Pause the slides">❚❚</button>
        </div>
        <a class="down" href="#start">More ↓</a>
    </section>

    <section class="page2" id="start" aria-labelledby="start-title">
        <div class="wrap">
            <h2 id="start-title">One place for the whole Soroti family.</h2>
            <p class="lead">Graduates, continuing students and university staff all sign in at the same door. You'll be taken to the right place for you.</p>

            <div class="grid">
                <article class="tile"><div class="ic" aria-hidden="true">🎓</div><div class="who">Graduates</div><h3>Your alumni record</h3><p>Update where you work and how to reach you. Verify your degree for employers with a link.</p></article>
                <article class="tile"><div class="ic" aria-hidden="true">📚</div><div class="who">Continuing students</div><h3>Internships &amp; mentors</h3><p>See internships and events for your programme, and ask for an alumni mentor.</p></article>
                <article class="tile"><div class="ic" aria-hidden="true">💼</div><div class="who">Everyone</div><h3>Jobs &amp; events</h3><p>Opportunities shared by the university, aimed at people like you.</p></article>
                <article class="tile"><div class="ic" aria-hidden="true">🏛️</div><div class="who">University staff</div><h3>The staff dashboard</h3><p>Registrar, ICT and QA: records, surveys and graduate outcomes.</p></article>
            </div>

            <div class="cta">
                <div>
                    <h3>Ready? Come on in.</h3>
                    <p>New here? Creating an account takes about two minutes.</p>
                </div>
                <div class="btns">
                    <a class="pill-btn fill" href="/app/login">Sign in</a>
                    <a class="pill-btn" href="/app/register">Create an account</a>
                </div>
            </div>
        </div>
    </section>
</main>

<footer>
    © {{ date('Y') }} Soroti University · Arapai, Soroti
    <br><span class="credit">Photo: <a href="https://commons.wikimedia.org/wiki/File:Soroti_University_07.jpg" rel="noopener">Ssemmanda will</a>, <a href="https://creativecommons.org/licenses/by-sa/4.0/" rel="noopener">CC BY-SA 4.0</a></span>
</footer>

<script>
(function () {
    var hero = document.getElementById('hero');
    var track = document.getElementById('track');
    var slides = Array.prototype.slice.call(track.children);
    var dots = Array.prototype.slice.call(document.querySelectorAll('.dot'));
    var pauseBtn = document.getElementById('pause');
    var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var DURATION = 5000; // long enough to read each line comfortably
    var current = 0, timer = null, startedAt = 0, remaining = DURATION;
    var userPaused = still, hoverPaused = false; // reduced motion: slides only move when the visitor asks
    hero.style.setProperty('--dur', DURATION + 'ms');

    function show(i) {
        current = (i + slides.length) % slides.length;
        track.style.transform = 'translateX(' + (-100 * current) + '%)';
        slides.forEach(function (s, k) {
            s.classList.toggle('on', k === current);
            s.setAttribute('aria-hidden', k === current ? 'false' : 'true');
        });
        dots.forEach(function (d, k) {
            d.classList.remove('on');
            d.classList.toggle('done', k < current);
            d.removeAttribute('aria-current');
            if (k === current) { void d.offsetWidth; d.classList.add('on'); d.setAttribute('aria-current', 'true'); }
        });
        remaining = DURATION;
        schedule();
    }
    function schedule() {
        clearTimeout(timer);
        if (userPaused || hoverPaused || document.hidden) return;
        startedAt = Date.now();
        timer = setTimeout(function () { show(current + 1); }, remaining);
    }
    function hold() {
        clearTimeout(timer);
        if (startedAt) remaining = Math.max(400, remaining - (Date.now() - startedAt));
        startedAt = 0;
    }
    function refreshPauseButton() {
        hero.classList.toggle('paused', userPaused || hoverPaused);
        pauseBtn.textContent = userPaused ? '▶' : '❚❚';
        pauseBtn.setAttribute('aria-label', userPaused ? 'Play the slides' : 'Pause the slides');
    }

    pauseBtn.addEventListener('click', function () { userPaused = !userPaused; userPaused ? hold() : schedule(); refreshPauseButton(); });
    document.getElementById('prev').addEventListener('click', function () { show(current - 1); });
    document.getElementById('next').addEventListener('click', function () { show(current + 1); });
    dots.forEach(function (d, k) { d.addEventListener('click', function () { show(k); }); });

    // Pause while the mouse or keyboard focus is on the slides, so nobody is rushed mid-sentence.
    hero.addEventListener('mouseenter', function () { hoverPaused = true; hold(); refreshPauseButton(); });
    hero.addEventListener('mouseleave', function () { hoverPaused = false; schedule(); refreshPauseButton(); });
    hero.addEventListener('focusin', function () { hoverPaused = true; hold(); refreshPauseButton(); });
    hero.addEventListener('focusout', function () { hoverPaused = false; schedule(); refreshPauseButton(); });
    document.addEventListener('visibilitychange', function () { document.hidden ? hold() : schedule(); });
    hero.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight') show(current + 1);
        else if (e.key === 'ArrowLeft') show(current - 1);
    });

    // Swipe left / right on phones.
    var x0 = null;
    hero.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    hero.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var dx = e.changedTouches[0].clientX - x0;
        x0 = null;
        if (Math.abs(dx) > 40) show(current + (dx < 0 ? 1 : -1));
    });

    refreshPauseButton();
    show(0);

    // Page-two tiles rise in as they arrive.
    var tiles = document.querySelectorAll('.tile');
    if ('IntersectionObserver' in window && !still) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e, k) {
                if (e.isIntersecting) { setTimeout(function () { e.target.classList.add('in'); }, k * 90); io.unobserve(e.target); }
            });
        }, { threshold: 0.2 });
        tiles.forEach(function (t) { io.observe(t); });
    } else {
        tiles.forEach(function (t) { t.classList.add('in'); });
    }
})();
</script>
</body>
</html>
