<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Plan::upsert([
            ['name' => 'Inicial', 'slug' => 'inicial', 'price' => 25000, 'max_branches' => 1, 'max_users' => 3, 'max_products' => 5000, 'is_active' => true],
            ['name' => 'Pro', 'slug' => 'pro', 'price' => 45000, 'max_branches' => 3, 'max_users' => 10, 'max_products' => null, 'is_active' => true],
            ['name' => 'Multisucursal', 'slug' => 'multi', 'price' => 85000, 'max_branches' => 20, 'max_users' => 50, 'max_products' => null, 'is_active' => true],
        ], ['slug'], ['name', 'price', 'max_branches', 'max_users', 'max_products']);

        User::updateOrCreate(['email' => 'admin@ferreterosoft.com'], [
            'name' => 'Administrador SaaS',
            'password' => 'password',
            'is_super_admin' => true,
        ]);

        $this->call(DemoTenantSeeder::class);
    }
}
