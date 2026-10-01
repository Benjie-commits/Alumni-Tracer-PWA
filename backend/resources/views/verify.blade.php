<x-layouts.public title="Verify a graduate">
    <h1>Verify a graduate</h1>
    <p class="muted">Check that someone graduated from Soroti University. We confirm graduation, programme and year of graduation only, and we never share contact details.</p>

    @if ($enquirySent)
        <div class="result verified" role="status">
            <h2>Thank you, the Registrar's office has your request</h2>
            <p>A member of staff will check the records by hand and reply to the email address you gave.</p>
        </div>
    @endif

    @if ($outcome)
        <div class="result {{ $outcome->result === \App\Enums\VerificationResult::Verified ? 'verified' : 'other' }}" role="status">
            <h2>{{ $outcome->result->label() }}</h2>
            <p>{{ $outcome->message() }}</p>
            <p class="small">Reference: <strong>{{ $outcome->reference }}</strong> (quote this if you contact us)</p>
        </div>

        @if ($outcome->canEscalate())
            <form class="card" method="post" action="{{ route('verify.enquiry') }}">
                @csrf
                <h2>Ask the Registrar's office to check</h2>
                <p class="muted small" style="margin-top:0">A member of staff will look the records up by hand. Use this if you are sure the person graduated here.</p>
                <input type="hidden" name="reference" value="{{ $outcome->reference }}">
                <div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                <label>Your name <input type="text" name="requester_name" value="{{ old('requester_name') }}" required maxlength="120" autocomplete="name"></label>
                @error('requester_name')<div class="error">{{ $message }}</div>@enderror
                <label>Your email, so we can reply <input type="email" name="requester_email" value="{{ old('requester_email', old('email')) }}" required maxlength="255" autocomplete="email"></label>
                @error('requester_email')<div class="error">{{ $message }}</div>@enderror
                <label>Phone (optional) <input type="tel" name="requester_phone" value="{{ old('requester_phone') }}" maxlength="32" autocomplete="tel"></label>
                <label>Anything that would help us find them (optional) <textarea name="message" rows="3" maxlength="1000">{{ old('message') }}</textarea></label>
                <button type="submit">Send to the Registrar</button>
            </form>
        @endif
    @endif

    <form class="card" method="post" action="{{ route('verify.lookup') }}">
        @csrf
        <h2>{{ $outcome ? 'Check someone else, or try again' : 'Who would you like to check?' }}</h2>

        @if ($errors->any() && ! $errors->has('requester_name') && ! $errors->has('requester_email'))
            <div class="error" role="alert" style="margin-bottom:12px">{{ $errors->first() }}</div>
        @endif

        <label>Your organisation <input type="text" name="organisation" value="{{ old('organisation') }}" required maxlength="255" autocomplete="organization"></label>
        <label>Your email (optional) <input type="email" name="email" value="{{ old('email') }}" maxlength="255" autocomplete="email"></label>
        <label>Graduate's full name <input type="text" name="name" value="{{ old('name') }}" required maxlength="120" placeholder="e.g. Amina Okello"></label>
        <div class="row">
            <label>Programme (optional)
                <select name="programme_id">
                    <option value="">Any programme</option>
                    @foreach ($programmes as $group => $items)
                        <optgroup label="{{ $group }}">
                            @foreach ($items as $programme)
                                <option value="{{ $programme->id }}" @selected((string) old('programme_id') === (string) $programme->id)>{{ $programme->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>
            <label>Year of graduation (optional)
                <input type="number" name="graduation_year" value="{{ old('graduation_year') }}" min="1950" max="{{ date('Y') + 1 }}" inputmode="numeric">
            </label>
        </div>
        <button type="submit">Check</button>
        <p class="muted small" style="margin-bottom:0">Adding the programme and year helps when several graduates share a name. Lookups are recorded.</p>
    </form>
</x-layouts.public>
