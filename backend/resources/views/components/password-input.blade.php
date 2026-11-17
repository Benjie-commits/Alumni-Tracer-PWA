{{-- Password box with a show/hide eye. Pass any input attributes, e.g. wire:model="password". --}}
<span class="pw" x-data="{ shown: false }">
    <input {{ $attributes->merge(['autocomplete' => 'new-password', 'spellcheck' => 'false']) }} :type="shown ? 'text' : 'password'" type="password">
    <button type="button" class="pw-eye" @click="shown = !shown" :aria-pressed="shown"
            :aria-label="shown ? 'Hide password' : 'Show password'" :title="shown ? 'Hide password' : 'Show password'" aria-label="Show password">
        <svg x-show="!shown" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
        <svg x-show="shown" x-cloak viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-7 0-11-7-11-7a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
    </button>
</span>
