<?php

namespace Database\Seeders;

use App\Models\Camera;
use Illuminate\Database\Seeder;

class CameraSeeder extends Seeder
{
    public function run(): void
    {
        $zones = [
            ['name' => 'CAM-ESTE-01', 'zone' => 'East Fence', 'location' => 'Perímetro Este'],
            ['name' => 'CAM-OESTE-01', 'zone' => 'West Fence', 'location' => 'Perímetro Oeste'],
            ['name' => 'CAM-NORTE-01', 'zone' => 'North Fence', 'location' => 'Perímetro Norte'],
            ['name' => 'CAM-SUR-01', 'zone' => 'South Gate', 'location' => 'Entrada Sur'],
        ];

        foreach ($zones as $zone) {
            Camera::factory()->create($zone);
        }
    }
}
