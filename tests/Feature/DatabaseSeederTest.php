<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_uses_valid_departments_and_positions(): void
    {
        $this->seed();

        $this->assertDatabaseHas('departments', [
            'department_code' => 'RWS',
            'department_name' => 'R Web Solutions',
        ]);
        $this->assertDatabaseHas('departments', [
            'department_code' => 'TECH',
            'department_name' => 'Technicians',
        ]);
        $this->assertDatabaseHas('positions', [
            'position' => 'Web Developer',
        ]);
        $this->assertDatabaseHas('positions', [
            'position' => 'Lead Technician',
        ]);
        $this->assertDatabaseCount('employees', 4);
        $this->assertDatabaseHas('employees', [
            'emp_id' => 1001,
        ]);
        $this->assertDatabaseHas('employees', [
            'emp_id' => 1004,
        ]);
    }
}
