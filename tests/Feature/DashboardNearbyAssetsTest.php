<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Parking;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardNearbyAssetsTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN_LAT = 27.6939;
    private const ORIGIN_LNG = 85.2817;
    private const DEST_LAT   = 27.6940;
    private const DEST_LNG   = 85.2818;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin']);
        $admin = User::factory()->create();
        $admin->roles()->attach($role);

        return $admin;
    }

    private function enableRouting(array $distancesMeters, array $durationsSeconds = []): void
    {
        config(['services.osrm.base_url' => 'https://osrm.test']);
        config(['services.osrm.cache_ttl' => 0]);

        Http::preventStrayRequests();
        Http::fake([
            'osrm.test/*' => Http::response([
                'code'      => 'Ok',
                'distances' => [$distancesMeters],
                'durations' => [$durationsSeconds],
            ]),
        ]);
    }

    public function test_nearby_assets_return_driving_distance_and_duration(): void
    {
        Parking::create([
            'name' => 'Kalanki Parking', 'location' => 'Kalanki Chowk',
            'status' => 'opened', 'latitude' => self::DEST_LAT, 'longitude' => self::DEST_LNG,
        ]);
        Bus::create([
            'name' => 'Kalanki Express', 'bus_number' => 'BA 1 PA 1234', 'total_capacity' => 40,
            'hwid' => 'BUS-NAV-01', 'status' => 'active', 'latitude' => self::DEST_LAT, 'longitude' => self::DEST_LNG,
        ]);

        // buses are listed before parkings
        $this->enableRouting([900, 800], [360, 480]);

        $response = $this->actingAs($this->admin())->getJson(
            route('dashboard.nearby-assets', ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG, 'radius' => 1])
        );

        $response->assertOk();
        $assets = collect($response->json('assets'));

        $bus = $assets->firstWhere('type', 'bus');
        $parking = $assets->firstWhere('type', 'parking');

        $this->assertEquals(0.9, $bus['distance_km']);
        $this->assertEquals(6.0, $bus['duration_min']);
        $this->assertSame('road', $bus['distance_source']);
        $this->assertEquals(0.8, $parking['distance_km']);
        $this->assertSame('road', $parking['distance_source']);
    }

    public function test_assets_outside_road_radius_are_excluded(): void
    {
        Parking::create([
            'name' => 'Kalanki Parking', 'location' => 'Kalanki Chowk',
            'status' => 'opened', 'latitude' => self::DEST_LAT, 'longitude' => self::DEST_LNG,
        ]);

        // Straight-line ~0.13km is inside radius 0.5, but road distance 900m is not
        $this->enableRouting([900], [360]);

        $response = $this->actingAs($this->admin())->getJson(
            route('dashboard.nearby-assets', ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG, 'radius' => 0.5])
        );

        $response->assertOk();
        $this->assertCount(0, $response->json('assets'));
    }

    public function test_falls_back_to_straight_line_when_routing_unavailable(): void
    {
        Parking::create([
            'name' => 'Kalanki Parking', 'location' => 'Kalanki Chowk',
            'status' => 'opened', 'latitude' => self::DEST_LAT, 'longitude' => self::DEST_LNG,
        ]);

        config(['services.osrm.base_url' => 'https://osrm.test']);
        config(['services.osrm.cache_ttl' => 0]);
        Http::fake(['*' => Http::response('down', 503)]);

        $response = $this->actingAs($this->admin())->getJson(
            route('dashboard.nearby-assets', ['lat' => self::ORIGIN_LAT, 'lng' => self::ORIGIN_LNG, 'radius' => 1])
        );

        $response->assertOk();
        $asset = collect($response->json('assets'))->sole();
        $this->assertSame('straight_line', $asset['distance_source']);
        $this->assertLessThan(0.2, $asset['distance_km']);
        $this->assertNull($asset['duration_min']);
    }

    public function test_requires_authentication(): void
    {
        $this->get(route('dashboard.nearby-assets', ['lat' => 27.7, 'lng' => 85.3]))
            ->assertRedirect(route('login'));
    }
}
