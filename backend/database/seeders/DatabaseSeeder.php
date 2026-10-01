<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only fixed reference data lives here so it is safe in production. Fake demo alumni are opt-in:
     * `php artisan db:seed --class=DemoDataSeeder` (refuses to run outside local/testing).
     */
    public function run(): void
    {
        $this->call([RoleSeeder::class, SurveyCycleSeeder::class]);
    }
}
