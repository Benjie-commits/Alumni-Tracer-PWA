<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed the fixed role set (idempotent, safe to run in production).
     */
    public function run(): void
    {
        foreach (RoleSlug::cases() as $slug) {
            Role::query()->updateOrCreate(['slug' => $slug->value], ['name' => $slug->label()]);
        }
    }
}
