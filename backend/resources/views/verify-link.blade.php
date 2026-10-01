<x-layouts.public title="Verified graduate">
    <div class="result verified" role="status" style="margin-top:20px">
        <h2>Verified</h2>
        <p style="font-size:18px"><strong>{{ $outcome->name }}</strong> graduated from Soroti University{{ $outcome->programme ? ' with '.$outcome->programme : '' }}{{ $outcome->graduationYear ? ' in '.$outcome->graduationYear : '' }}.</p>
        <p class="small">Confirmed against the Registrar's records. Reference: <strong>{{ $outcome->reference }}</strong></p>
    </div>
    <p class="muted small">This link was shared by the graduate. It confirms graduation, programme and year of graduation only, and may expire or be withdrawn at any time. To check someone else, use the <a href="{{ route('verify.show') }}">verification page</a>.</p>
</x-layouts.public>
