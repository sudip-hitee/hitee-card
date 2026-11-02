<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\Parking;
use App\Models\Route;
use App\Models\Stop;
use App\Services\RoutingService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @group CustomerApi
 * @subgroup Misc
 */
class MiscController extends Controller
{
    /**
     * GET /api/misc/nearby
     *
     * Find nearby buses and/or parkings based on lat, long, and radius.
     * Items are filtered and sorted by road-network distance (OSRM) when the
     * routing backend is reachable; distance_source is 'straight_line' when
     * it falls back. Returns a combined or filtered list.
     *
     * @queryParam lat float required Latitude for proximity search.
     * @queryParam long float required Longitude for proximity search.
     * @queryParam radius float (optional) Search radius in km. Defaults to 10.
     * @queryParam type string (optional) Filter by type: 'bus', 'parking', 'service_partner' or 'stop'.
     */
    public function nearby(Request $request)
    {
        $request->validate([
            'lat'    => 'required|numeric',
            'long'   => 'required|numeric',
            'radius' => 'nullable|numeric',
            'type'   => 'nullable|string|in:bus,parking,service_partner,stop',
        ]);

        $lat = (float) $request->lat;
        $lng = (float) $request->long;
        $radius = (float) $request->get('radius', 10);
        $type = $request->get('type');
        $perPage = 25;

        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))";
        $haversineBindings = [$lat, $lng, $lat];

        // Candidate sets — haversine pre-filter is safe because road distance
        // is never shorter than straight-line distance.
        $sets = [];

        if (!$type || $type === 'bus') {
            $sets['buses'] = Bus::with(['merchant:id,name', 'route:id,name,direction'])
                ->where('status', 'active')
                ->select('*')
                ->selectRaw("{$haversine} AS straight_distance", $haversineBindings)
                ->whereRaw("{$haversine} <= ?", [...$haversineBindings, $radius])
                ->get();
        }

        if (!$type || $type === 'parking') {
            $sets['parkings'] = Parking::with(['merchant:id,name', 'attributes:id,name,icon', 'media'])
                ->where('status', 'opened')
                ->where('is_hitee_verified', true)
                ->select('*')
                ->selectRaw("{$haversine} AS straight_distance", $haversineBindings)
                ->whereRaw("{$haversine} <= ?", [...$haversineBindings, $radius])
                ->get();
        }

        if (!$type || $type === 'service_partner') {
            $sets['service_partners'] = \App\Models\ServicePartner::with(['merchant:id,name'])
                ->where('status', 'active')
                ->select('*')
                ->selectRaw("{$haversine} AS straight_distance", $haversineBindings)
                ->whereRaw("{$haversine} <= ?", [...$haversineBindings, $radius])
                ->get();
        }

        if (!$type || $type === 'stop') {
            $sets['stops'] = Stop::select('*')
                ->selectRaw("{$haversine} AS straight_distance", $haversineBindings)
                ->whereRaw("{$haversine} <= ?", [...$haversineBindings, $radius])
                ->get();
        }

        // One routing call covers every candidate type.
        $destinations = [];
        foreach ($sets as $items) {
            foreach ($items as $m) {
                $destinations[] = ['lat' => (float) $m->latitude, 'lng' => (float) $m->longitude];
            }
        }
        $road = app(RoutingService::class)->distancesFrom($lat, $lng, $destinations);

        $results = [];
        $pagination = [];
        $pageParams = [
            'buses' => 'bus_page',
            'parkings' => 'parking_page',
            'service_partners' => 'sp_page',
            'stops' => 'stop_page',
        ];

        $cursor = 0;
        foreach ($sets as $key => $items) {
            $items = $items->map(function ($m) use (&$cursor, $road) {
                $leg = $road[$cursor++] ?? null;
                $m->distance = $leg['distance_km'] ?? $m->straight_distance;
                $m->duration_min = $leg['duration_min'] ?? null;
                $m->distance_source = $leg === null ? 'straight_line' : 'road';
                return $m;
            })->filter(fn ($m) => $m->distance <= $radius)->sortBy('distance')->values();

            $pageParam = $pageParams[$key];
            $page = (int) $request->input($pageParam, 1);
            $paginator = new LengthAwarePaginator(
                $items->forPage($page, $perPage)->values(),
                $items->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'pageName' => $pageParam]
            );

            $results[$key] = collect($paginator->items())->map($this->nearbyMapper($key));
            $pagination[$key] = [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ];
        }

        return apiResponse(true, 'Nearby items fetched successfully', $results, 200, [], $pagination);
    }

    private function nearbyMapper(string $type): callable
    {
        $distance = fn ($m) => [
            'distance_km'     => isset($m->distance) ? round((float) $m->distance, 2) : null,
            'duration_min'    => isset($m->duration_min) && $m->duration_min !== null ? round((float) $m->duration_min, 1) : null,
            'distance_source' => $m->distance_source ?? null,
        ];

        return match ($type) {
            'buses' => fn ($bus) => [
                'id'          => $bus->id,
                'name'        => $bus->name,
                'bus_number'  => $bus->bus_number,
                'status'      => $bus->status,
                'latitude'    => $bus->latitude,
                'longitude'   => $bus->longitude,
                ...$distance($bus),
                'merchant'    => $bus->merchant?->name,
                'route'       => $bus->route ? [
                    'id'        => $bus->route->id,
                    'name'      => $bus->route->name,
                    'direction' => $bus->route->direction,
                ] : null,
                'image_url'   => $bus->featured_image_url,
                'type'        => 'bus',
            ],
            'parkings' => fn ($p) => [
                'id'               => $p->id,
                'name'             => $p->name,
                'location'         => $p->location,
                'status'           => $p->status,
                'latitude'         => $p->latitude,
                'longitude'        => $p->longitude,
                ...$distance($p),
                'merchant'         => $p->merchant?->name,
                'attributes'       => $p->attributes->map(fn ($a) => [
                    'name' => $a->name,
                    'icon' => $a->icon_url,
                ]),
                'image_url'        => $p->featured_image_url,
                'type'             => 'parking',
            ],
            'service_partners' => fn ($sp) => [
                'id'           => $sp->id,
                'name'         => $sp->name,
                'service_type' => $sp->service_type,
                'address'      => $sp->address,
                'latitude'     => $sp->latitude,
                'longitude'    => $sp->longitude,
                ...$distance($sp),
                'merchant'     => $sp->merchant?->name,
                'type'         => 'service_partner',
            ],
            'stops' => fn ($s) => [
                'id'          => $s->id,
                'name'        => $s->name,
                'latitude'    => $s->latitude,
                'longitude'   => $s->longitude,
                ...$distance($s),
                'type'        => 'stop',
            ],
        };
    }

    /**
     * GET /api/misc/stops
     * 
     * Search for stops by name.
     */
    public function searchStops(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|min:2',
        ]);

        $query = Stop::query();

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $stops = $query->limit(20)->get()->map(fn($s) => [
            'id'        => $s->id,
            'name'      => $s->name,
            'latitude'  => $s->latitude,
            'longitude' => $s->longitude,
        ]);

        return apiResponse(true, 'Stops fetched successfully', $stops);
    }

    /**
     * GET /api/misc/route-finder
     * 
     * Search for routes connecting two specific stop IDs using smart routing logic.
     */
    public function routeFinder(Request $request)
    {
        $request->validate([
            'from_stop_id' => 'required|exists:stops,id',
            'to_stop_id'   => 'required|exists:stops,id',
        ]);

        $fromId = $request->from_stop_id;
        $toId   = $request->to_stop_id;

        if ($fromId == $toId) {
            return apiResponse(false, 'Start and end stops cannot be the same', '', 400);
        }

        $options = $this->findJourneyOptions($fromId, $toId);

        $mapped = $options->map(function($option) {
            return [
                'total_fare_pts' => (float) collect($option)->sum('fare'),
                'total_distance_km' => round((float) collect($option)->sum('distance'), 2),
                'legs' => collect($option)->map(fn($leg) => [
                    'route_id'   => $leg['route']->id,
                    'route_name' => $leg['route']->name,
                    'direction'  => $leg['route']->direction,
                    'fare_pts'   => (float) $leg['fare'],
                    'distance_km' => round((float) $leg['distance'], 2),
                    'from_stop'  => $leg['from']->name,
                    'to_stop'    => $leg['to']->name,
                    'intermediates' => $leg['intermediates']->map(fn($s) => $s->name),
                ]),
            ];
        });

        return apiResponse(true, 'Routes found successfully', $mapped);
    }

    private function findJourneyOptions($startStopId, $endStopId): Collection
    {
        $allStops = Stop::all();
        $allRoutes = Route::with(['stops', 'fares.matrices'])->get();

        // 1. Find DIRECT paths
        $directPaths = [];
        $directRoutes = $allRoutes->filter(function($r) use ($startStopId, $endStopId) {
            $sIds = $r->stops->pluck('stop_id')->toArray();
            $idxStart = array_search($startStopId, $sIds);
            $idxEnd = array_search($endStopId, $sIds);
            return ($idxStart !== false && $idxEnd !== false && $idxStart < $idxEnd);
        });

        foreach ($directRoutes as $r) {
            $leg = [
                'route' => $r,
                'fare' => $this->getFareForLeg($r, $startStopId, $endStopId),
                'from' => Stop::find($startStopId),
                'to' => Stop::find($endStopId),
                'distance' => $this->getDistForLeg($r, $startStopId, $endStopId),
                'intermediates' => $this->getIntermediatesForLeg($r, $startStopId, $endStopId),
            ];
            $directPaths[] = [$leg];
        }

        // 2. Dijkstra with penalty for transfers
        $transferPenalty = 5.0; 
        $distances = [];
        $predecessors = []; 

        foreach ($allStops as $stop) { $distances[$stop->id] = INF; }
        $distances[$startStopId] = 0;
        
        $queue = new \SplPriorityQueue();
        $queue->insert($startStopId, 0);

        while (!$queue->isEmpty()) {
            $u = $queue->extract();
            if ($u == $endStopId) break;

            $availableRoutes = $allRoutes->filter(fn($r) => $r->stops->contains('stop_id', $u));
            foreach ($availableRoutes as $route) {
                $stops = $route->stops->values();
                $uIdx = $stops->search(fn($s) => $s->stop_id == $u);
                
                $accumulatedDist = 0;
                for ($i = $uIdx + 1; $i < $stops->count(); $i++) {
                    $segmentDist = $this->haversine($stops[$i-1]->latitude, $stops[$i-1]->longitude, $stops[$i]->latitude, $stops[$i]->longitude);
                    $accumulatedDist += $segmentDist;
                    $cost = $accumulatedDist + $transferPenalty; 
                    $alt = $distances[$u] + $cost;

                    if ($alt < $distances[$stops[$i]->stop_id]) {
                        $distances[$stops[$i]->stop_id] = $alt;
                        $predecessors[$stops[$i]->stop_id] = ['from' => $u, 'dist' => $accumulatedDist, 'route' => $route];
                        $queue->insert($stops[$i]->stop_id, -$alt);
                    }
                }
            }
        }

        // 3. Reconstruct transfer path
        $transferPath = [];
        $curr = $endStopId;
        while (isset($predecessors[$curr])) {
            $step = $predecessors[$curr];
            array_unshift($transferPath, [
                'from' => Stop::find($step['from']),
                'to' => Stop::find($curr),
                'route' => $step['route'],
                'fare' => $this->getFareForLeg($step['route'], $step['from'], $curr),
                'distance' => $step['dist'],
                'intermediates' => $this->getIntermediatesForLeg($step['route'], $step['from'], $curr),
            ]);
            $curr = $step['from'];
        }

        $allOptions = collect($directPaths);
        if (!empty($transferPath) && count($transferPath) > 1) {
            $allOptions->push($transferPath);
        }

        return $allOptions->unique(function($opt) {
            return collect($opt)->map(fn($leg) => $leg['route']->id)->implode('-');
        })->take(5);
    }

    private function getIntermediatesForLeg($route, $fromId, $toId) {
        $stops = $route->stops->values();
        $startIdx = $stops->search(fn($s) => $s->stop_id == $fromId);
        $endIdx = $stops->search(fn($s) => $s->stop_id == $toId);
        
        $intermediateStops = [];
        for ($i = $startIdx + 1; $i < $endIdx; $i++) {
            $intermediateStops[] = Stop::find($stops[$i]->stop_id);
        }
        return collect($intermediateStops);
    }

    private function getFareForLeg($route, $fromId, $toId) {
        $fare = $route->fares->where('status', 'approved')->first();
        if ($fare) {
            $matrix = $fare->matrices->where('from_stop_id', $fromId)->where('to_stop_id', $toId)->first();
            return $matrix ? $matrix->amount : 0;
        }
        return 0;
    }

    private function getDistForLeg($route, $fromId, $toId) {
        $stops = $route->stops->values();
        $startIdx = $stops->search(fn($s) => $s->stop_id == $fromId);
        $endIdx = $stops->search(fn($s) => $s->stop_id == $toId);
        $dist = 0;
        for ($i = $startIdx + 1; $i <= $endIdx; $i++) {
            $dist += $this->haversine($stops[$i-1]->latitude, $stops[$i-1]->longitude, $stops[$i]->latitude, $stops[$i]->longitude);
        }
        return $dist;
    }

    private function haversine($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
