<?php

namespace Tests\Feature\Api;

use App\Models\Bus;
use App\Models\Parking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test listing buses.
     */
    public function test_can_list_buses()
    {
        Bus::create([
            'name' => 'KTM-01',
            'bus_number' => 'BA 1 PA 1234',
            'total_capacity' => 40,
            'hwid' => 'BUS-HWID-01',
            'status' => 'active',
            'latitude' => 27.7172,
            'longitude' => 85.3240,
        ]);

        $response = $this->getJson('/api/buses');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Buses fetched successfully',
            ]);
        
        $this->assertCount(1, $response->json('content'));
    }

    /**
     * Test searching buses.
     */
    public function test_can_search_buses()
    {
        Bus::create([
            'name' => 'KTM-01',
            'bus_number' => 'BA 1 PA 1234',
            'total_capacity' => 40,
            'hwid' => 'BUS-HWID-01',
            'status' => 'active',
        ]);

        Bus::create([
            'name' => 'LTP-02',
            'bus_number' => 'BA 2 PA 5678',
            'total_capacity' => 40,
            'hwid' => 'BUS-HWID-02',
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/buses?search=KTM');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('content'));
        $this->assertEquals('KTM-01', $response->json('content.0.name'));
    }

    /**
     * Test showing a bus.
     */
    public function test_can_show_bus()
    {
        $bus = Bus::create([
            'name' => 'KTM-01',
            'bus_number' => 'BA 1 PA 1234',
            'total_capacity' => 40,
            'hwid' => 'BUS-HWID-01',
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/buses/' . $bus->id);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'content' => [
                    'id' => $bus->id,
                    'name' => 'KTM-01',
                ]
            ]);
    }

    /**
     * Test listing parkings.
     */
    public function test_can_list_parkings()
    {
        $parking = Parking::create([
            'name' => 'Durbar Square Parking',
            'location' => 'Kathmandu',
            'total_capacity' => 100,
            'status' => 'opened',
            'latitude' => 27.7045,
            'longitude' => 85.3068,
        ]);

        $parking->fees()->create([
            'title' => 'First Hour',
            'price_rs' => 20,
            'price_pts' => 20,
        ]);

        $response = $this->getJson('/api/parkings');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Parkings fetched successfully',
            ]);
        
        $this->assertCount(1, $response->json('content'));
        $this->assertArrayHasKey('fees', $response->json('content.0'));
    }

    /**
     * Test showing a parking.
     */
    public function test_can_show_parking()
    {
        $parking = Parking::create([
            'name' => 'Durbar Square Parking',
            'location' => 'Kathmandu',
            'total_capacity' => 100,
            'status' => 'opened',
        ]);

        $parking->fees()->create([
            'title' => 'First Hour',
            'price_rs' => 20,
            'price_pts' => 20,
        ]);

        $response = $this->getJson('/api/parkings/' . $parking->id);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'content' => [
                    'id' => $parking->id,
                    'name' => 'Durbar Square Parking',
                ]
            ]);
        $this->assertArrayHasKey('fees', $response->json('content'));
    }

    /**
     * Unverified parkings are hidden from customer-facing endpoints.
     */
    public function test_unverified_parkings_are_hidden_from_api()
    {
        Parking::create([
            'name' => 'Verified Parking',
            'location' => 'Kathmandu',
            'status' => 'opened',
            'is_hitee_verified' => true,
        ]);

        $unverified = Parking::create([
            'name' => 'Unverified Parking',
            'location' => 'Kathmandu',
            'status' => 'opened',
            'is_hitee_verified' => false,
        ]);

        $response = $this->getJson('/api/parkings');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('content'));
        $this->assertEquals('Verified Parking', $response->json('content.0.name'));

        $this->getJson('/api/parkings/' . $unverified->id)->assertStatus(404);
    }
}
