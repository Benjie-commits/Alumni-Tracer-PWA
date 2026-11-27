<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesStaff;
use App\Models\Setting;
use App\Services\AlumniRegistrationService;
use App\Support\Audit;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Settings')]
class Settings extends Component
{
    use AuthorizesStaff;

    public function mount(): void
    {
        $this->authorizeIctAdmin();
    }

    public function toggleOtp(): void
    {
        $this->authorizeIctAdmin();

        $on = ! AlumniRegistrationService::otpEnabled();
        Setting::put('registration.otp', $on);
        Audit::record('settings.registration_otp', meta: ['enabled' => $on]);
    }

    public function render()
    {
        return view('livewire.admin.settings', ['otp' => AlumniRegistrationService::otpEnabled()]);
    }
}
