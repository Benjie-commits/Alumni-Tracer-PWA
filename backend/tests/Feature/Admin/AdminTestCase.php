<?php

namespace Tests\Feature\Admin;

use App\Models\AlumniProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    protected function registrar(): User
    {
        return User::factory()->registrar()->create();
    }

    protected function ictAdmin(): User
    {
        return User::factory()->ictAdmin()->create();
    }

    protected function qaViewer(): User
    {
        return User::factory()->qaViewer()->create();
    }

    protected function profile(array $attributes = []): AlumniProfile
    {
        return AlumniProfile::factory()->create($attributes);
    }
}
