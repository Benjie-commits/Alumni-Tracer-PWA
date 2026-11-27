<div>
    <div class="head">
        <div>
            <h1>Settings</h1>
            <span class="muted">Switches that change how the alumni app behaves.</span>
        </div>
    </div>

    <div class="panel">
        <h2>Registration code (OTP)</h2>
        <p>
            Status: <span class="pill {{ $otp ? 'verified' : 'rejected' }}">{{ $otp ? 'On' : 'Off' }}</span>
        </p>
        <p class="muted" style="max-width:70ch">
            <strong>On:</strong> when an alumnus's details match a Registrar record, a 6-digit code is sent to the phone or email on that record, and typing it back claims the record.<br>
            <strong>Off:</strong> no code is sent. Nobody is verified automatically; every registration waits in the Verification queue for the Registrar to approve.
        </p>
        <button type="button" class="secondary" wire:click="toggleOtp"
                wire:confirm="{{ $otp ? 'Turn registration codes off? New registrations will wait for Registrar review.' : 'Turn registration codes on?' }}">
            {{ $otp ? 'Turn off' : 'Turn on' }}
        </button>
    </div>
</div>
