@extends('layouts.app')

@section('title', 'Dashboard')

@push('page-css')
<style>
    .map-container { border: 1px solid #dee2e6; }
    .nearby-badge { position: absolute; top: 10px; right: 10px; z-index: 1000; }
    .fullscreen-map {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        z-index: 1099;
        margin: 0 !important;
        background: white;
    }
    .fullscreen-map .card {
        height: 100%;
        border-radius: 0;
    }
    .fullscreen-map #fleet-map {
        height: calc(100vh - 120px) !important;
    }
    .dark-style .fullscreen-map {
        background: #232333;
    }
</style>
@endpush

@section('content')
<div class="row">
    <div class="col-lg-8 mb-6 order-0">
        <div class="card h-100">
            <div class="d-flex align-items-start row">
                <div class="col-sm-7">
                    <div class="card-body">
                        <h5 class="card-title text-primary mb-3">{{ __('messages.welcome') }} {{ auth()->user()->name }}! 🎉</h5>
                        <p class="mb-6">
                            Welcome back to Hitee Platform. Here is what is happening with your account today.
                        </p>

                        <div class="d-flex flex-wrap gap-2">
                            <a href="{{ route('profile.show') }}" class="btn btn-sm btn-primary">{{ __('messages.view') }} {{ __('messages.profile') }}</a>
                        </div>
                    </div>
                </div>
                <div class="col-sm-5 text-center text-sm-left">
                    <div class="card-body pb-0 px-0 px-md-6">
                        <img src="{{ asset('assets/img/illustrations/man-with-laptop.png') }}" height="175"
                            alt="View Badge User" />
                    </div>
                </div>
            </div>
            <div class="card-body border-top">
                <h6 class="text-muted mb-4">Quick Shortcuts</h6>
                <div class="row g-3">
                    @if(auth()->user()->hasRole('super-admin'))
                        <div class="col-md-3 col-6">
                            <a href="{{ route('users.create') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-user-plus fs-3 mb-2 text-primary"></i>
                                <span class="small fw-medium">{{ __('messages.add') }} {{ __('messages.users') }}</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#quickAddBalanceModal" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-wallet fs-3 mb-2 text-success"></i>
                                <span class="small fw-medium">Load Funds</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="{{ route('cards.create') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-credit-card fs-3 mb-2 text-warning"></i>
                                <span class="small fw-medium">Issue {{ __('messages.cards') }}</span>
                            </a>
                        </div>
                    @endif

                    @if(auth()->user()->hasRole('super-admin'))
                        <div class="col-md-3 col-6">
                            <a href="{{ route('buses.create') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-bus fs-3 mb-2 text-primary"></i>
                                <span class="small fw-medium">{{ __('messages.add') }} {{ __('messages.buses') }}</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="{{ route('parkings.create') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-car fs-3 mb-2 text-primary"></i>
                                <span class="small fw-medium">{{ __('messages.add') }} {{ __('messages.parkings') }}</span>
                            </a>
                        </div>
                    @endif

                    @if(auth()->user()->hasRole('merchant'))
                        @php
                            $merchantType = auth()->user()->merchant_type ?? 'bus_operator';
                        @endphp

                        @if($merchantType === 'bus_operator')
                        <div class="col-md-3 col-6">
                            <a href="{{ route('buses.index') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-bus fs-3 mb-2 text-primary"></i>
                                <span class="small fw-medium">My {{ __('messages.buses') }}</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="{{ route('routes.index') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-map-alt fs-3 mb-2 text-info"></i>
                                <span class="small fw-medium">Routes & Fares</span>
                            </a>
                        </div>
                        @elseif($merchantType === 'parking_operator')
                        <div class="col-md-3 col-6">
                            <a href="{{ route('parkings.index') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-car fs-3 mb-2 text-primary"></i>
                                <span class="small fw-medium">My {{ __('messages.parkings') }}</span>
                            </a>
                        </div>
                        @elseif($merchantType === 'service_partner')
                        <div class="col-md-3 col-6">
                            <a href="{{ route('service-partners.index') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-store fs-3 mb-2 text-primary"></i>
                                <span class="small fw-medium">My Services</span>
                            </a>
                        </div>
                        @endif
                        <div class="col-md-3 col-6">
                            <a href="{{ route('staff.index') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-group fs-3 mb-2 text-success"></i>
                                <span class="small fw-medium">Staff Mgmt</span>
                            </a>
                        </div>
                    @endif

                    @if(auth()->user()->hasRole('customers'))
                        <div class="col-md-3 col-6">
                            @if(auth()->user()->is_tourist)
                                <a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#stripeTopupModal" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                    <i class="bx bxl-stripe fs-3 mb-2 text-primary"></i>
                                    <span class="small fw-medium">Top up Balance</span>
                                </a>
                            @else
                                <a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#khaltiTopupModal" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                    <i class="bx bx-plus-circle fs-3 mb-2 text-primary"></i>
                                    <span class="small fw-medium">Top up Balance</span>
                                </a>
                            @endif
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="{{ route('transactions.logs') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-list-ul fs-3 mb-2 text-info"></i>
                                <span class="small fw-medium">{{ __('messages.transactions') }} Logs</span>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="{{ route('cards.index') }}" class="d-flex flex-column align-items-center text-center p-3 border rounded h-100 transition-all hover-light text-body">
                                <i class="bx bx-credit-card fs-3 mb-2 text-warning"></i>
                                <span class="small fw-medium">My {{ __('messages.cards') }}</span>
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4 col-md-4 order-1">
        <div class="row">
            <div class="col-12 mb-6">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="avatar flex-shrink-0">
                                <span class="avatar-initial rounded bg-white text-primary"><i class="bx bx-wallet"></i></span>
                            </div>
                            <h5 class="card-title text-white mb-0">{{ auth()->user()->hasRole('merchant') ? 'Available Income' : 'My Balance' }}</h5>
                        </div>
                        <h2 class="text-white mb-2">Rs. {{ number_format(auth()->user()->hasRole('merchant') ? auth()->user()->merchantBalance() : auth()->user()->balance(), 2) }}</h2>
                        <div class="d-flex gap-2">
                            @if(auth()->user()->hasRole('customers'))
                                @if(auth()->user()->is_tourist)
                                    <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#stripeTopupModal">Topup</button>
                                @else
                                    <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#khaltiTopupModal">Topup</button>
                                @endif
                                <a href="{{ route('transactions.logs') }}" class="btn btn-sm btn-outline-light">History</a>
                            @elseif(auth()->user()->hasRole('merchant'))
                                <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#withdrawModal">Withdraw</button>
                                <a href="{{ route('merchant.income') }}" class="btn btn-sm btn-outline-light">{{ __('messages.earnings') }}</a>
                            @else
                                <a href="{{ route('transactions.logs') }}" class="btn btn-sm btn-outline-light">History</a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            @if(auth()->user()->hasRole('super-admin'))
            <div class="col-lg-6 col-md-12 col-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center">
                            <div class="avatar flex-shrink-0 me-2">
                                <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-user"></i></span>
                            </div>
                            <div class="card-info">
                                <h6 class="mb-0">{{ $userCount }}</h6>
                                <small class="text-muted">{{ __('messages.users') }}</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 col-md-12 col-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center">
                            <div class="avatar flex-shrink-0 me-2">
                                <span class="avatar-initial rounded bg-label-warning"><i class="bx bx-credit-card"></i></span>
                            </div>
                            <div class="card-info">
                                <h6 class="mb-0">{{ $cardCount }}</h6>
                                <small class="text-muted">{{ __('messages.cards') }}</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 col-md-12 col-6 mb-4">
                <a href="{{ route('supports.index') }}" class="card hover-light">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center">
                            <div class="avatar flex-shrink-0 me-2">
                                <span class="avatar-initial rounded bg-label-danger"><i class="bx bx-support"></i></span>
                            </div>
                            <div class="card-info">
                                <h6 class="mb-0">{{ $openSupportCount ?? 0 }}</h6>
                                <small class="text-muted">Open Tickets</small>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            @endif

            @if(auth()->user()->hasRole('super-admin', 'merchant'))
            <div class="col-lg-6 col-md-12 col-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center">
                            <div class="avatar flex-shrink-0 me-2">
                                <span class="avatar-initial rounded bg-label-primary"><i class="bx bx-bus"></i></span>
                            </div>
                            <div class="card-info">
                                <h6 class="mb-0">{{ $busCount }}</h6>
                                <small class="text-muted">{{ __('messages.buses') }}</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 col-md-12 col-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center">
                            <div class="avatar flex-shrink-0 me-2">
                                <span class="avatar-initial rounded bg-label-info"><i class="bx bxs-parking"></i></span>
                            </div>
                            <div class="card-info">
                                <h6 class="mb-0">{{ $parkingCount }}</h6>
                                <small class="text-muted">{{ __('messages.parkings') }}</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 col-md-12 col-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center">
                            <div class="avatar flex-shrink-0 me-2">
                                <span class="avatar-initial rounded bg-label-success"><i class="bx bx-store"></i></span>
                            </div>
                            <div class="card-info">
                                <h6 class="mb-0">{{ $servicePartnerCount ?? 0 }}</h6>
                                <small class="text-muted">Partners</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

<div class="row">
    <!-- Map View for Admin and Merchant -->
    @if(auth()->user()->hasRole('super-admin', 'merchant'))
    <div class="col-12 mb-4" id="fleet-map-container">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">Fleet & Asset Locations</h5>
                <div class="d-flex gap-2">
                    <button id="centerOnMeFleet" class="btn btn-sm btn-outline-secondary"><i class="bx bx-target-lock"></i> Center on Me</button>
                    <button id="toggleMapSize" class="btn btn-sm btn-outline-primary"><i class="bx bx-fullscreen"></i> Toggle Fullscreen</button>
                </div>
            </div>
            <div class="card-body">
                <div id="fleet-map" style="height: 450px; border-radius: 8px;"></div>
            </div>
        </div>
    </div>
    @endif

    <!-- Nearby for Customers -->
    @if(auth()->user()->hasRole('customers'))
    <div class="col-md-6 mb-4">
        <div class="card h-100 bg-label-primary">
            <div class="card-body d-flex flex-column justify-content-center align-items-center text-center">
                <div class="avatar avatar-lg mb-3">
                    <span class="avatar-initial rounded bg-primary"><i class="bx bx-map-pin fs-2"></i></span>
                </div>
                <h5>Smart {{ __('messages.route_finder') }}</h5>
                <p>Plan your journey, find direct buses, or get smart connecting route suggestions across the city.</p>
                <a href="{{ route('route-finder.index') }}" class="btn btn-primary mt-2">
                    <i class="bx bx-search-alt me-1"></i> Start Planning
                </a>
            </div>
        </div>
    </div>

    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Nearby Assets (5km)</h5>
                <button id="refreshNearby" class="btn btn-sm btn-outline-primary"><i class="bx bx-refresh"></i> Refresh</button>
            </div>
            <div class="card-body">
                <div id="nearby-status" class="alert alert-info py-2 mb-3">
                    <i class="bx bx-loader-alt bx-spin me-2"></i> Detecting location...
                </div>
                <div class="row" id="nearby-assets-container">
                    <!-- Dynamic content -->
                    <div class="col-12 text-center py-3 text-muted">
                        Waiting for location access...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 mb-4">
        <div class="card">
            <h5 class="card-header">Local Area Map</h5>
            <div class="card-body">
                <div id="customer-map" style="height: 400px; border-radius: 8px;"></div>
            </div>
        </div>
    </div>
    @endif
</div>

@endsection

@push('page-js')
<script type="module">
    document.addEventListener('DOMContentLoaded', function() {
        const jQuery = window.jQuery;
        if (!jQuery) return;
        const $ = jQuery;

        // Common map logic helper
        const createMap = (id) => {
            const el = document.getElementById(id);
            if (!el) return null;
            // Default to Kathmandu but we will try to center on user location
            const m = L.map(id).setView([27.7172, 85.3240], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(m);
            return m;
        };

        const centerMapOnUser = (map, defaultZoom = 13, callback = null) => {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition((position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    map.setView([lat, lng], defaultZoom);

                    // Add/Update user marker
                    if (!window.mapUserMarkers) window.mapUserMarkers = new Map();
                    if (window.mapUserMarkers.has(map)) {
                        map.removeLayer(window.mapUserMarkers.get(map));
                    }

                    const marker = L.circleMarker([lat, lng], {
                        radius: 8,
                        fillColor: "#AF1D23",
                        color: "#fff",
                        weight: 3,
                        opacity: 1,
                        fillOpacity: 0.8
                    }).addTo(map).bindPopup("You are here");

                    window.mapUserMarkers.set(map, marker);

                    if (callback) callback(true, lat, lng);
                }, (error) => {
                    console.warn("Geolocation failed or denied:", error);
                    if (callback) callback(false);
                }, { timeout: 5000 });
            } else {
                if (callback) callback(false);
            }
        };

        // 1. Admin/Merchant Fleet Map
        @if(auth()->user()->hasRole('super-admin', 'merchant'))
        try {
            const fleetMap = createMap('fleet-map');
            if (fleetMap) {
                let userLocated = false;
                const buses = @json($buses);
                const parkings = @json($parkings);
                const servicePartners = @json($servicePartners ?? []);
                const fleetBounds = L.latLngBounds();

                // 1. Center on user by default
                centerMapOnUser(fleetMap, 12, (success) => {
                    userLocated = success;
                    // If geolocation fails, fallback to bounds
                    if (!success && fleetBounds.isValid()) {
                        fleetMap.fitBounds(fleetBounds, {padding: [50, 50]});
                    }
                });

                const busIcon = L.divIcon({
                    html: '<i class="bx bx-bus bg-primary text-white p-1 rounded-circle shadow" style="font-size: 20px; border: 2px solid white;"></i>',
                    className: 'custom-div-icon', iconSize: [26, 26], iconAnchor: [13, 13]
                });

                const parkingIcon = L.divIcon({
                    html: '<i class="bx bxs-parking bg-info text-white p-1 rounded-circle shadow" style="font-size: 20px; border: 2px solid white;"></i>',
                    className: 'custom-div-icon', iconSize: [26, 26], iconAnchor: [13, 13]
                });

                const serviceIcon = L.divIcon({
                    html: '<i class="bx bx-store bg-success text-white p-1 rounded-circle shadow" style="font-size: 20px; border: 2px solid white;"></i>',
                    className: 'custom-div-icon', iconSize: [26, 26], iconAnchor: [13, 13]
                });

                buses.forEach(bus => {
                    if (bus.latitude && bus.longitude) {
                        L.marker([bus.latitude, bus.longitude], {icon: busIcon})
                            .addTo(fleetMap)
                            .bindPopup(`<strong>${bus.name}</strong><br>Bus: ${bus.bus_number}<br><a href="/buses/${bus.id}" class="btn btn-xs btn-primary mt-1 text-white">View Details</a>`);
                        fleetBounds.extend([bus.latitude, bus.longitude]);
                    }
                });

                parkings.forEach(parking => {
                    if (parking.latitude && parking.longitude) {
                        L.marker([parking.latitude, parking.longitude], {icon: parkingIcon})
                            .addTo(fleetMap)
                            .bindPopup(`<strong>${parking.name}</strong><br>${parking.location}<br><a href="/parkings/${parking.id}" class="btn btn-xs btn-info mt-1 text-white">View Details</a>`);
                        fleetBounds.extend([parking.latitude, parking.longitude]);
                    }
                });

                servicePartners.forEach(sp => {
                    if (sp.latitude && sp.longitude) {
                        L.marker([sp.latitude, sp.longitude], {icon: serviceIcon})
                            .addTo(fleetMap)
                            .bindPopup(`<strong>${sp.name}</strong><br>${sp.service_type}<br><a href="/service-partners/${sp.id}" class="btn btn-xs btn-success mt-1 text-white">View Details</a>`);
                        fleetBounds.extend([sp.latitude, sp.longitude]);
                    }
                });

                $('#centerOnMeFleet').on('click', function() {
                    centerMapOnUser(fleetMap, 15);
                });

                $('#toggleMapSize').on('click', function() {
                    const wrapper = $('#fleet-map-container');
                    if (wrapper.hasClass('fullscreen-map')) {
                        wrapper.removeClass('fullscreen-map');
                        $(this).html('<i class="bx bx-fullscreen"></i> Toggle Fullscreen');
                    } else {
                        wrapper.addClass('fullscreen-map');
                        $(this).html('<i class="bx bx-exit-fullscreen"></i> Exit Fullscreen');
                    }
                    setTimeout(() => { fleetMap.invalidateSize(); }, 300);
                });
            }
        } catch (e) { console.error("Fleet map error:", e); }
        @endif

        // 2. Customer Nearby Logic
        @if(auth()->user()->hasRole('customers'))
        try {
            const customerMap = createMap('customer-map');
            let userMarker;
            const allBuses = @json($buses);
            const allParkings = @json($parkings);

            const detectLocation = () => {
                if (!navigator.geolocation) {
                    $('#nearby-status').removeClass('alert-info').addClass('alert-danger').html('<i class="bx bx-error-circle me-2"></i> Geolocation not supported.');
                    return;
                }

                $('#nearby-status').html('<i class="bx bx-loader-alt bx-spin me-2"></i> Requesting location access...');

                navigator.geolocation.getCurrentPosition((position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;

                    if (customerMap) {
                        customerMap.setView([lat, lng], 15);
                        if (userMarker) customerMap.removeLayer(userMarker);
                        userMarker = L.circleMarker([lat, lng], {
                            radius: 8, fillColor: "#AF1D23", color: "#fff", weight: 3, opacity: 1, fillOpacity: 0.8
                        }).addTo(customerMap).bindPopup("Your Location");
                    }

                    processNearby(lat, lng);
                }, (error) => {
                    let msg = 'Location access denied.';
                    if (error.code === error.TIMEOUT) msg = 'Location request timed out.';
                    if (error.code === error.POSITION_UNAVAILABLE) msg = 'Location unavailable.';

                    $('#nearby-status').removeClass('alert-info').addClass('alert-danger').html('<i class="bx bx-error-circle me-2"></i> ' + msg);
                    processNearby(27.7172, 85.3240); // Default to Kathmandu
                }, { timeout: 10000 });
            };

            const calculateDistance = (lat1, lon1, lat2, lon2) => {
                const R = 6371; // km
                const dLat = (lat2 - lat1) * Math.PI / 180;
                const dLon = (lon2 - lon1) * Math.PI / 180;
                const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                          Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                          Math.sin(dLon / 2) * Math.sin(dLon / 2);
                const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
                return R * c;
            };

            const processNearby = (userLat, userLng) => {
                $('#nearby-status').html('<i class="bx bx-loader-alt bx-spin me-2"></i> Finding nearby assets...');

                const url = `{{ route('dashboard.nearby-assets') }}?lat=${userLat}&lng=${userLng}&radius=5`;
                fetch(url)
                    .then(r => { if (!r.ok) throw new Error('nearby failed'); return r.json(); })
                    .then(data => renderNearby(data.assets))
                    .catch(() => {
                        const combined = [
                            ...allBuses.map(b => ({...b, type: 'bus'})),
                            ...allParkings.map(p => ({...p, type: 'parking'}))
                        ];
                        const nearby = combined.map(item => {
                            const dist = calculateDistance(userLat, userLng, item.latitude, item.longitude);
                            return { ...item, distance_km: dist.toFixed(2), duration_min: null, distance_source: 'straight_line' };
                        }).filter(item => item.distance_km <= 5.0);
                        renderNearby(nearby);
                    });
            };

            const renderNearby = (nearby) => {

                const busCount = nearby.filter(i => i.type === 'bus').length;
                const parkingCount = nearby.filter(i => i.type === 'parking').length;

                const container = $('#nearby-assets-container');
                container.empty();

                if (nearby.length === 0) {
                    $('#nearby-status').removeClass('alert-info alert-success alert-danger').addClass('alert-warning').html('<i class="bx bx-info-circle me-2"></i> No assets found within 5km.');
                    container.html('<div class="col-12 text-center py-3 text-muted">No assets found nearby.</div>');
                    return;
                }

                $('#nearby-status').removeClass('alert-info alert-danger alert-warning').addClass('alert-success')
                    .html(`<i class="bx bx-check-circle me-2"></i> Found ${nearby.length} assets nearby: ${busCount} Buses and ${parkingCount} Parkings.`);

                // Show a summary card instead of a long list
                container.append(`
                    <div class="col-12">
                        <div class="d-flex justify-content-around align-items-center p-4 border rounded bg-label-facebook">
                            <div class="text-center">
                                <div class="avatar avatar-md mx-auto mb-2">
                                    <span class="avatar-initial rounded bg-primary"><i class="bx bx-bus fs-3 text-white"></i></span>
                                </div>
                                <h4 class="mb-0 fw-bold text-primary">${busCount}</h4>
                                <small class="text-muted fw-medium">Buses</small>
                            </div>
                            <div class="vr mx-3"></div>
                            <div class="text-center">
                                <div class="avatar avatar-md mx-auto mb-2">
                                    <span class="avatar-initial rounded bg-info"><i class="bx bxs-parking fs-3 text-white"></i></span>
                                </div>
                                <h4 class="mb-0 fw-bold text-info">${parkingCount}</h4>
                                <small class="text-muted fw-medium">Parkings</small>
                            </div>
                        </div>
                        <p class="text-center mt-3 small text-muted">Explore the map markers below for exact locations.</p>
                    </div>
                `);

                const busIcon = L.divIcon({
                    html: '<i class="bx bx-bus bg-primary text-white p-1 rounded-circle shadow" style="font-size: 18px; border: 2px solid white;"></i>',
                    className: 'custom-div-icon', iconSize: [24, 24], iconAnchor: [12, 12]
                });

                const parkingIcon = L.divIcon({
                    html: '<i class="bx bxs-parking bg-info text-white p-1 rounded-circle shadow" style="font-size: 18px; border: 2px solid white;"></i>',
                    className: 'custom-div-icon', iconSize: [24, 24], iconAnchor: [12, 12]
                });

                nearby.forEach(asset => {
                    if (customerMap) {
                        L.marker([asset.latitude, asset.longitude], {icon: asset.type === 'bus' ? busIcon : parkingIcon})
                            .addTo(customerMap)
                            .bindPopup(`<strong>${asset.name}</strong><br>${asset.distance_source === 'road' && asset.duration_min != null
                                ? `${asset.distance_km} km · ${Math.round(asset.duration_min)} min drive`
                                : `~${asset.distance_km} km away`}`);
                    }
                });
            };

            // Initial detection
            detectLocation();

            $('#refreshNearby').on('click', detectLocation);

        } catch (e) { console.error("Customer map error:", e); }
        @endif
    });
</script>
@endpush
