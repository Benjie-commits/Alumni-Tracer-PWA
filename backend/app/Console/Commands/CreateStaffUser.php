<?php

namespace App\Console\Commands;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

#[Signature('sunates:create-staff {email : Sign-in email} {--name= : Full name} {--role=ict_admin : registrar, ict_admin or qa_viewer}')]
#[Description('Create a staff account for the admin console (used to bootstrap the first ICT admin)')]
class CreateStaffUser extends Command
{
    public function handle(): int
    {
        // Roles are fixed reference data; make sure they exist on a fresh install.
        $this->callSilently('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);

        $name = $this->option('name') ?: $this->ask('Full name');
        $password = $this->secret('Password (10+ characters)');

        $validator = Validator::make([
            'name' => $name,
            'email' => $this->argument('email'),
            'role' => $this->option('role'),
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(array_map(fn (RoleSlug $r) => $r->value, RoleSlug::staff()))],
            'password' => ['required', 'string', Password::min(10)->max(128)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name,
            'email' => $this->argument('email'),
            'password' => $password,
            'role_id' => Role::idFor(RoleSlug::from($this->option('role'))),
        ]);

        $this->info("Created {$this->option('role')} account for {$this->argument('email')}.");

        return self::SUCCESS;
    }
}
