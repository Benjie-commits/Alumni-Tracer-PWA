<x-layouts.public title="Graduates" :wide="true">
    <x-slot:head>
        <style>
            .find { display: flex; gap: 10px; max-width: 560px; margin: 14px 0 28px; }
            .find input { flex: 1; } .find button { width: auto; }
            .people { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
            .person { display: block; text-decoration: none; color: inherit; background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 18px; transition: transform .18s var(--ease), box-shadow .18s var(--ease), border-color .18s var(--ease); }
            .person:hover { transform: translateY(-2px); border-color: color-mix(in srgb, var(--brand) 45%, var(--line)); box-shadow: 0 8px 22px rgba(18,34,24,.10); }
            .person .nm { font-family: var(--display); font-weight: 600; font-size: 20px; line-height: 1.2; }
            .person .pr { color: var(--muted); font-size: 14px; margin-top: 4px; }
            .person .yr { display: inline-block; margin-top: 12px; font-size: 13px; font-weight: 600; color: var(--link); font-variant-numeric: tabular-nums; }
        </style>
    </x-slot:head>
    <h1>Soroti University graduates</h1>
    <p class="muted" style="margin:0;max-width:56ch">Graduates who chose to share a page. Each has been confirmed against the Registrar's records.</p>
    <form class="find" method="get" action="{{ route('alumni.public') }}" role="search">
        <input type="search" name="q" value="{{ $q }}" placeholder="Search by name" aria-label="Search by name" maxlength="80">
        <button type="submit">Search</button>
    </form>
    @if ($people->isEmpty())
        <p class="muted">{{ $q !== '' ? 'No published graduates match that name.' : 'No graduate has published a page yet.' }}</p>
    @else
        <div class="people">
            @foreach ($people as $p)
                <a class="person" href="{{ route('alumnus.show', $p->public_slug) }}">
                    <div class="nm">{{ $p->first_name }} {{ $p->last_name }}</div>
                    <div class="pr">{{ $p->programme?->name }}</div>
                    <span class="yr">Class of {{ $p->graduation_year }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-layouts.public>
