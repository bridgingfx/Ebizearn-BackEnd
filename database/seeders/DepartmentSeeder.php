<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Seeds the standard departments (idempotent).
 */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Operations', 'label' => 'Platform operations & campaign oversight', 'sort_order' => 0],
            ['name' => 'Finance', 'label' => 'Deposits, payouts & wallet operations', 'sort_order' => 1],
            ['name' => 'Support', 'label' => 'Customer support & disputes', 'sort_order' => 2],
            ['name' => 'Compliance', 'label' => 'KYC, fraud & verification', 'sort_order' => 3],
            ['name' => 'Marketing', 'label' => 'Campaigns, content & growth', 'sort_order' => 4],
        ];

        foreach ($departments as $dept) {
            Department::updateOrCreate(
                ['name' => $dept['name']],
                $dept + ['is_active' => true]
            );
        }
    }
}
