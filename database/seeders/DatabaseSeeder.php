<?php

namespace Database\Seeders;

use App;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (App::environment('production')) {
            throw new RuntimeException(
                'DatabaseSeeder refuses to run in production — it would create a demo user with known credentials. '.
                'Seed the venue catalog explicitly instead: php artisan db:seed --class=VenueTemplateSeeder'
            );
        }

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(VenueTemplateSeeder::class);
    }
}
