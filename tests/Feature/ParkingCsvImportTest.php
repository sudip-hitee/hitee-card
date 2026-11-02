<?php

namespace Tests\Feature;

use App\Models\Parking;
use App\Services\ParkingCsvImportService;
use App\Services\ParkingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ParkingCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function googleRow(array $overrides = []): array
    {
        return array_merge([
            'place_id' => 'ChIJvz_MfwAZ6zkRSCjmsrrkpzo',
            'name' => 'Alfa Beta Parking',
            'address' => 'Alfa Beta Complex, Kathmandu, Bagmati Province 44600, Nepal',
            'neighborhood' => 'Shankhamul',
            'city' => 'Kathmandu',
            'lat' => '27.6888679',
            'lng' => '85.3312809',
            'phone' => '',
            'rating' => '4.0',
            'reviews_count' => '2',
            'image_url' => 'https://images.example/parking.jpg',
            'google_maps_url' => 'https://www.google.com/maps/place/?q=place_id:ChIJvz_MfwAZ6zkRSCjmsrrkpzo',
        ], $overrides);
    }

    private function osmRow(array $overrides = []): array
    {
        return array_merge([
            'osm_id' => 'way/172495780',
            'name' => 'Norvic Parking',
            'lat' => '27.6896889',
            'lon' => '85.3193681',
            'parking_type' => 'surface',
            'access' => 'customers',
            'fee' => 'yes',
            'capacity' => '40',
            'operator' => 'Norvic Hospital',
            'address' => '',
            'image_links' => '',
            'street_view_mapillary' => 'https://www.mapillary.com/app/?lat=27.6896889&lng=85.3193681&z=18',
            'osm_url' => 'https://www.openstreetmap.org/way/172495780',
            'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=27.6896889,85.3193681',
        ], $overrides);
    }

    private function merchant()
    {
        return app(ParkingImportService::class)->defaultMerchant();
    }

    public function test_new_lots_default_to_unverified_while_existing_default_to_verified(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/jpeg'])]);

        $existing = Parking::create([
            'name' => 'Existing Lot',
            'location' => 'Kathmandu',
            'status' => 'opened',
        ]);

        $this->assertTrue($existing->fresh()->is_hitee_verified);

        $service = app(ParkingCsvImportService::class);
        $service->import([$this->googleRow()], $this->merchant(), false);
        $service->import([$this->osmRow()], $this->merchant(), false);

        $this->assertFalse(Parking::where('name', 'Alfa Beta Parking')->sole()->is_hitee_verified);
        $this->assertFalse(Parking::where('name', 'Norvic Parking')->sole()->is_hitee_verified);
        $this->assertTrue($existing->fresh()->is_hitee_verified);
    }

    public function test_google_row_maps_fields_and_downloads_the_single_image(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/jpeg'])]);

        $result = app(ParkingCsvImportService::class)->import([$this->googleRow()], $this->merchant());

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['images']);

        $parking = Parking::sole();

        $this->assertNull($parking->external_id);
        $this->assertSame('Alfa Beta Parking', $parking->name);
        $this->assertSame('Alfa Beta Complex, Kathmandu, Bagmati Province 44600, Nepal', $parking->location);
        $this->assertSame(27.6888679, $parking->latitude);
        $this->assertSame(85.3312809, $parking->longitude);
        $this->assertSame('Shankhamul', $parking->owner_name);
        $this->assertStringContainsString('Google Maps:', $parking->notes);
        $this->assertFalse($parking->is_hitee_verified);
        $this->assertSame(1, $parking->media()->where('collection_name', 'featured')->count());
        $this->assertStringNotContainsString('no_image.png', $parking->featured_image_url);
    }

    public function test_google_reimport_matches_by_name_and_location_without_duplicating(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/jpeg'])]);

        $service = app(ParkingCsvImportService::class);
        $service->import([$this->googleRow()], $this->merchant());
        $result = $service->import([$this->googleRow(['phone' => '+977 981-0000000'])], $this->merchant());

        $this->assertSame(1, Parking::count());
        $this->assertSame(1, $result['updated']);
        $this->assertSame('+977 981-0000000', Parking::sole()->owner_phone);
        $this->assertSame(1, Parking::sole()->media()->count());
    }

    public function test_osm_row_maps_fields_and_encodes_osm_id_in_external_id(): void
    {
        $result = app(ParkingCsvImportService::class)->import([$this->osmRow()], $this->merchant(), false);

        $this->assertSame(1, $result['created']);

        $parking = Parking::sole();

        $this->assertSame(2172495780, $parking->external_id); // way → prefix 2
        $this->assertSame('Norvic Parking', $parking->name);
        $this->assertSame('27.6896889, 85.3193681', $parking->location);
        $this->assertSame(85.3193681, $parking->longitude);
        $this->assertSame(40, $parking->total_capacity);
        $this->assertSame('Norvic Hospital', $parking->owner_name);
        $this->assertStringContainsString('OSM element: way/172495780', $parking->notes);
        $this->assertFalse($parking->is_hitee_verified);
    }

    public function test_osm_reimport_matches_by_osm_id_and_unnamed_rows_get_deterministic_names(): void
    {
        $service = app(ParkingCsvImportService::class);

        $service->import([$this->osmRow(['name' => ''])], $this->merchant(), false);
        $result = $service->import([$this->osmRow(['name' => '', 'capacity' => '60'])], $this->merchant(), false);

        $this->assertSame(1, Parking::count());
        $this->assertSame(1, $result['updated']);
        $this->assertSame('Parking (OSM way/172495780)', Parking::sole()->name);
        $this->assertSame(60, Parking::sole()->total_capacity);
    }

    public function test_osm_node_and_way_ids_do_not_collide(): void
    {
        $service = app(ParkingCsvImportService::class);

        $service->import([
            $this->osmRow(['osm_id' => 'node/172495780']),
            $this->osmRow(['osm_id' => 'way/172495780']),
        ], $this->merchant(), false);

        $this->assertSame(2, Parking::count());
        $this->assertEqualsCanonicalizing(
            [1172495780, 2172495780],
            Parking::pluck('external_id')->all()
        );
    }
}
