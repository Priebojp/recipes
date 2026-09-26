<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database: AI list prices, the support administrator and the fictional demo household.
     */
    public function run(): void
    {
        $this->call([
            AiCostRateSeeder::class,
            PlatformAdminSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
