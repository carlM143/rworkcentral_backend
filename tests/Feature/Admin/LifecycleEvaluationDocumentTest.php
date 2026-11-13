<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LifecycleEvaluationDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LifecycleEvaluationDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach ([
            '0001_01_00_000000_create_roles_table.php',
            '0001_01_01_000000_create_users_table.php',
            '2026_10_21_024400_create_companies_table.php',
            '2026_10_21_024404_create_departments_table.php',
            '2026_10_21_024848_create_employees_table.php',
            '2026_11_12_120000_create_lifecycle_evaluation_documents_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    public function test_documents_are_private_validated_and_limited_to_administrators(): void
    {
        Storage::fake('local');
        $role = Role::firstOrCreate(['name' => 'Administrator']);
        $admin = User::factory()->create(['role_id' => $role->id]);
        $company = Company::create(['admin_id' => $admin->id, 'company_name' => 'Example']);
        $department = Department::create(['admin_id' => $admin->id, 'company_id' => $company->id, 'department_name' => 'Operations', 'department_code' => 'OPS', 'status' => true]);
        $user = User::factory()->create();
        $employee = Employee::create(['user_id' => $user->id, 'department_id' => $department->id, 'emp_id' => 12345678, 'firstname' => 'First', 'lastname' => 'Employee', 'birthday' => '1990-01-01', 'status' => true]);
        $url = "/api/admin/employees/{$employee->id}/lifecycle-evaluations";

        Sanctum::actingAs($user);
        $this->getJson($url)->assertForbidden();
        $this->postJson($url, ['document' => UploadedFile::fake()->create('result.pdf', 10, 'application/pdf')])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson($url, ['document' => UploadedFile::fake()->create('result.txt', 10, 'text/plain')])->assertUnprocessable();
        $id = $this->postJson($url, ['document' => UploadedFile::fake()->create('result.pdf', 10, 'application/pdf')])
            ->assertCreated()->assertJsonMissingPath('data.path')->json('data.id');
        $document = LifecycleEvaluationDocument::findOrFail($id);
        Storage::disk('local')->assertExists($document->path);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.path');
        $this->get("{$url}/{$id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        Sanctum::actingAs($user);
        $this->get("{$url}/{$id}")->assertForbidden();
    }
}
