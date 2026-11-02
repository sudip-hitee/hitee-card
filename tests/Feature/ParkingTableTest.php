<?php

namespace Tests\Feature;

use App\Models\Parking;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParkingTableTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
        $admin = User::factory()->create();
        $admin->roles()->attach($role);

        return $admin;
    }

    private function rowFor(array $data, string $name): array
    {
        return collect($data)->first(fn ($row) => str_contains($row['name'], $name));
    }

    public function test_location_column_renders_map_pin_button_with_coordinates(): void
    {
        Parking::create([
            'name' => 'Pin Lot',
            'location' => 'Chabahil chowk',
            'latitude' => 27.7172576,
            'longitude' => 85.3470913,
            'status' => 'opened',
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('parkings.index'), ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertOk();
        $row = $this->rowFor($response->json('data'), 'Pin Lot');

        $this->assertStringContainsString('parking-map-btn', $row['location']);
        $this->assertStringContainsString('data-query="27.7172576,85.3470913"', $row['location']);
        $this->assertArrayNotHasKey('merchant', $row);
    }

    public function test_location_pin_falls_back_to_location_text_when_no_coordinates(): void
    {
        Parking::create([
            'name' => 'Address Lot',
            'location' => 'Sundhara, Kathmandu',
            'status' => 'opened',
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('parkings.index'), ['X-Requested-With' => 'XMLHttpRequest']);

        $row = $this->rowFor($response->json('data'), 'Address Lot');

        $this->assertStringContainsString('parking-map-btn', $row['location']);
        $this->assertStringContainsString('data-query="Sundhara, Kathmandu"', $row['location']);
    }

    public function test_location_shows_na_when_nothing_to_map(): void
    {
        Parking::create([
            'name' => 'Lost Lot',
            'location' => '',
            'status' => 'opened',
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('parkings.index'), ['X-Requested-With' => 'XMLHttpRequest']);

        $row = $this->rowFor($response->json('data'), 'Lost Lot');

        $this->assertStringContainsString('N/A', $row['location']);
        $this->assertStringNotContainsString('parking-map-btn', $row['location']);
    }

    public function test_index_page_has_no_merchant_column_and_has_map_modal(): void
    {
        $response = $this->actingAs($this->admin())->get(route('parkings.index'));

        $response->assertOk();
        $response->assertDontSee('<th>Merchant</th>', false);
        $response->assertSee('parkingMapModal', false);
        $response->assertSee('parkingMapFrame', false);
    }
}
