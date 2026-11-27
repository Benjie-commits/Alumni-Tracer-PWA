@props(['year' => null, 'size' => 200])
{{-- The class seal: a ring of lettering around the graduation year. Pure geometry, no image. --}}
<svg viewBox="0 0 200 200" width="{{ $size }}" height="{{ $size }}" role="img" aria-label="Class of {{ $year }}" class="seal">
    <defs><path id="seal-ring-{{ $year }}" d="M100,100 m-76,0 a76,76 0 1,1 152,0 a76,76 0 1,1 -152,0"/></defs>
    <circle cx="100" cy="100" r="96" fill="none" stroke="currentColor" stroke-width="1.5"/>
    <circle cx="100" cy="100" r="58" fill="none" stroke="currentColor" stroke-width="1" opacity=".55"/>
    <text font-size="12" font-weight="600" fill="currentColor" font-family="'Instrument Sans', system-ui, sans-serif">
        <textPath href="#seal-ring-{{ $year }}" startOffset="0" textLength="470" lengthAdjust="spacing">SOROTI UNIVERSITY  ·  ALUMNI  ·  SOROTI UNIVERSITY  ·  ALUMNI  ·</textPath>
    </text>
    <text x="100" y="92" text-anchor="middle" font-size="13" letter-spacing="2.5" fill="currentColor" opacity=".8" font-family="'Instrument Sans', system-ui, sans-serif">CLASS OF</text>
    <text x="100" y="128" text-anchor="middle" font-size="40" font-weight="600" fill="currentColor" font-family="'Fraunces', Georgia, serif">{{ $year }}</text>
</svg>
