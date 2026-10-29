<?php

namespace Database\Seeders;

use App\Models\ContributionType;
use Illuminate\Database\Seeder;

class ContributionTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'contribution_code' => 'SSS',
                'contribution_name' => 'Social Security System',
                'provider' => 'SSS',
                'is_status' => true,
            ],
            [
                'contribution_code' => 'PHIC',
                'contribution_name' => 'PhilHealth Contribution',
                'provider' => 'PhilHealth',
                'is_status' => true,
            ],
            [
                'contribution_code' => 'HDMF',
                'contribution_name' => 'Pag-IBIG Fund Contribution',
                'provider' => 'Pag-IBIG',
                'is_status' => true,
            ],
        ];

        foreach ($types as $type) {
            ContributionType::updateOrCreate(
                ['contribution_code' => $type['contribution_code']],
                [
                    'contribution_name' => $type['contribution_name'],
                    'provider' => $type['provider'],
                    'is_status' => $type['is_status'],
                ]
            );
        }
    }
}
