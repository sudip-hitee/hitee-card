<?php

namespace App\Services;

use App\Models\Parking;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ParkingCsvImportService
{
    /**
     * OSM element type → external_id prefix so node/way/relation ids
     * can share the numeric external_id column without colliding.
     */
    private const OSM_TYPE_PREFIX = ['node' => 1, 'way' => 2, 'relation' => 3];

    /**
     * Import parking lots from a decoded CSV export. Rows are auto-detected:
     * Google exports carry a `place_id` column, OSM exports an `osm_id` column.
     *
     * @return array{created:int, updated:int, failed:int, images:int, errors:array}
     */
    public function import(array $rows, User $merchant, bool $withImages = true): array
    {
        $result = ['created' => 0, 'updated' => 0, 'failed' => 0, 'images' => 0, 'errors' => []];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $result['failed']++;
                $result['errors'][] = "#{$index}: invalid row";
                continue;
            }

            try {
                $parking = DB::transaction(fn () => $this->importRow($row, $merchant));

                $result[$parking->wasRecentlyCreated ? 'created' : 'updated']++;

                if ($withImages) {
                    $result['images'] += $this->importImage($parking, $this->imageUrl($row));
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = (trim((string) Arr::get($row, 'name', '')) ?: Arr::get($row, 'osm_id', "#{$index}")) . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    private function importRow(array $row, User $merchant): Parking
    {
        return Arr::get($row, 'osm_id') !== null
            ? $this->importOsmRow($row, $merchant)
            : $this->importGoogleRow($row, $merchant);
    }

    private function importGoogleRow(array $row, User $merchant): Parking
    {
        $name = trim((string) Arr::get($row, 'name', ''));
        if ($name === '') {
            throw new \InvalidArgumentException('missing name');
        }

        return Parking::updateOrCreate(
            ['name' => $name, 'location' => $this->googleLocation($row)],
            [
                'name' => $name,
                'location' => $this->googleLocation($row),
                'latitude' => $this->nullableNumeric(Arr::get($row, 'lat')),
                'longitude' => $this->nullableNumeric(Arr::get($row, 'lng')),
                'total_capacity' => 0,
                'merchant_id' => $merchant->id,
                'owner_name' => $this->nullableString(Arr::get($row, 'neighborhood')),
                'owner_phone' => $this->nullableString(Arr::get($row, 'phone')),
                'notes' => $this->googleNotes($row),
                'external_id' => null,
                'status' => 'opened',
                'is_hitee_verified' => false,
            ]
        );
    }

    private function importOsmRow(array $row, User $merchant): Parking
    {
        $osmId = trim((string) Arr::get($row, 'osm_id', ''));

        // Unnamed OSM lots get a deterministic name so re-imports stay idempotent.
        $name = trim((string) Arr::get($row, 'name', '')) ?: "Parking (OSM {$osmId})";

        $externalId = $this->encodeOsmId($osmId);
        $match = $externalId !== null
            ? ['external_id' => $externalId]
            : ['name' => $name, 'location' => $this->osmLocation($row)];

        return Parking::updateOrCreate(
            $match,
            [
                'name' => $name,
                'location' => $this->osmLocation($row),
                'latitude' => $this->nullableNumeric(Arr::get($row, 'lat')),
                'longitude' => $this->nullableNumeric(Arr::get($row, 'lon')),
                'total_capacity' => $this->nullableInt(Arr::get($row, 'capacity')) ?? 0,
                'merchant_id' => $merchant->id,
                'owner_name' => $this->nullableString(Arr::get($row, 'operator')),
                'owner_phone' => $this->nullableString(Arr::get($row, 'phone')),
                'notes' => $this->osmNotes($row),
                'status' => 'opened',
                'is_hitee_verified' => false,
            ]
        );
    }

    private function encodeOsmId(string $osmId): ?int
    {
        if (!preg_match('#^(node|way|relation)/(\d+)$#', $osmId, $m)) {
            return null;
        }

        return (int) (self::OSM_TYPE_PREFIX[$m[1]] . $m[2]);
    }

    private function googleLocation(array $row): string
    {
        return trim((string) (Arr::get($row, 'address') ?: Arr::get($row, 'neighborhood') ?: Arr::get($row, 'city') ?: 'Unknown'));
    }

    private function osmLocation(array $row): string
    {
        $address = trim((string) Arr::get($row, 'address', ''));

        return $address !== ''
            ? $address
            : trim((string) Arr::get($row, 'lat', '')) . ', ' . trim((string) Arr::get($row, 'lon', ''));
    }

    private function googleNotes(array $row): ?string
    {
        return $this->notes($row, [
            'place_id' => 'Google place ID',
            'category' => 'Category',
            'all_categories' => 'All categories',
            'opening_hours' => 'Opening hours',
            'rating' => 'Rating',
            'reviews_count' => 'Reviews',
            'website' => 'Website',
            'google_maps_url' => 'Google Maps',
            'search_term' => 'Search term',
        ]);
    }

    private function osmNotes(array $row): ?string
    {
        return $this->notes($row, [
            'osm_id' => 'OSM element',
            'parking_type' => 'Parking type',
            'access' => 'Access',
            'fee' => 'Fee',
            'opening_hours' => 'Opening hours',
            'supervised' => 'Supervised',
            'surface' => 'Surface',
            'osm_url' => 'OSM',
            'google_maps_url' => 'Google Maps',
            'street_view_mapillary' => 'Street view',
        ]);
    }

    private function notes(array $row, array $fields): ?string
    {
        $notes = [];

        foreach ($fields as $key => $label) {
            $value = trim((string) Arr::get($row, $key, ''));
            if ($value !== '') {
                $notes[] = "{$label}: {$value}";
            }
        }

        return $notes === [] ? null : implode(PHP_EOL, $notes);
    }

    private function imageUrl(array $row): string
    {
        // Google exports: `image_url`. OSM exports: `image_links`
        // (`street_view_mapillary` points at pages, not images — skipped).
        foreach (['image_url', 'image_links'] as $key) {
            $value = trim((string) Arr::get($row, $key, ''));
            if ($value !== '') {
                return strtok($value, " \t") ?: $value;
            }
        }

        return '';
    }

    private function nullableNumeric(mixed $value): ?float
    {
        $value = trim((string) $value);

        return $value === '' ? null : (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' || !is_numeric($value) ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * The single CSV image becomes the featured image.
     * Existing media is left untouched on re-imports.
     */
    private function importImage(Parking $parking, string $url): int
    {
        if ($url === '' || $parking->media()->exists()) {
            return 0;
        }

        try {
            return $parking->addMediaFromUrl($url, 'featured') ? 1 : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
