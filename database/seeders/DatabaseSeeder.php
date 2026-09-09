<?php

namespace Database\Seeders;

use App\Models\MoyenPaiement;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach ([
            'PVIT' => 'passerelle',
            'Airtel Money' => 'canal_pvit',
            'Moov Money' => 'canal_pvit',
            'Visa' => 'canal_pvit',
            'Mastercard' => 'canal_pvit',
            'GIMAC' => 'canal_pvit',
        ] as $nom => $type) {
            MoyenPaiement::query()->updateOrCreate(['nom' => $nom], ['type' => $type]);
        }
    }
}
