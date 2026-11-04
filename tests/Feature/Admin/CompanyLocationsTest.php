<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_can_have_multiple_locations_but_no_second_company(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $response = $this->postJson('/api/admin/companies', [
            'company_name' => 'Example',
            'locations' => [
                ['location_name' => 'Main', 'city' => 'Manila'],
                ['location_name' => 'Branch', 'city' => 'Cebu'],
            ],
        ])->assertCreated()->assertJsonCount(2, 'data.locations');

        $this->postJson('/api/admin/companies', ['company_name' => 'Other'])
            ->assertStatus(409);

        $id = $response->json('data.id');
        $this->putJson("/api/admin/companies/{$id}", [
            'company_name' => 'Example',
            'locations' => [['location_name' => 'North', 'city' => 'Baguio']],
        ])->assertOk()->assertJsonCount(3, 'data.locations');

        $this->assertSame(1, Company::count());
        $this->assertDatabaseHas('locations', ['company_id' => $id, 'location_name' => 'Main']);
    }

    public function test_invalid_and_duplicate_locations_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/admin/companies', [
            'company_name' => 'Example',
            'locations' => [['location_name' => 'Main', 'city' => '']],
        ])->assertUnprocessable()->assertJsonValidationErrors('locations.0.city');

        $response = $this->postJson('/api/admin/companies', [
            'company_name' => 'Example',
            'locations' => [['location_name' => 'Main', 'city' => 'Manila']],
        ])->assertCreated();
        $id = $response->json('data.id');

        $this->putJson("/api/admin/companies/{$id}", [
            'company_name' => 'Example',
            'locations' => [['location_name' => 'Main', 'city' => 'Cebu']],
        ])->assertUnprocessable()->assertJsonValidationErrors('locations.0.location_name');

        $this->putJson("/api/admin/companies/{$id}", [
            'company_name' => 'Renamed',
            'locations' => [],
        ])->assertOk()->assertJsonCount(1, 'data.locations');
        $this->assertDatabaseHas('locations', ['company_id' => $id, 'location_name' => 'Main', 'city' => 'Manila']);
    }
}
