@extends('layouts.app')

@section('title', 'Parkings')

@section('content')
<h4 class="py-3 mb-4">
    <span class="text-muted fw-light">{{ __('messages.fleet_management') }} /</span> {{ __('messages.parkings') }}
</h4>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">{{ __('messages.parkings') }} {{ __('messages.list') }}</h5>
        @if(auth()->user()->hasRole('super-admin'))
        <div class="d-flex gap-2">
            <a href="{{ route('parkings.import') }}" class="btn btn-label-primary">
                <i class="bx bx-import me-1"></i> Import
            </a>
            <a href="{{ route('parkings.create') }}" class="btn btn-primary">
                <i class="bx bx-plus me-1"></i> {{ __('messages.add') }} {{ __('messages.parkings') }}
            </a>
        </div>
        @endif
    </div>
    <div class="card-body">
        @if(session('import_errors'))
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <strong>Some lots couldn't be imported:</strong>
            <ul class="mb-0 mt-1">
                @foreach(session('import_errors') as $importError)
                    <li>{{ $importError }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        @endif
        <div class="table-responsive text-nowrap">
            <style>
                table.data-table {
                    table-layout: fixed !important;
                    width: 100% !important;
                    margin: 0 !important;
                }
                table.data-table th, table.data-table td {
                    overflow: hidden;
                    white-space: nowrap;
                    text-overflow: ellipsis;
                }
                table.data-table th:nth-child(1) { width: 50px; }
                table.data-table th:nth-child(2) { width: 220px; }
                table.data-table th:nth-child(3) { width: 90px; text-align: center; }
                table.data-table th:nth-child(4) { width: 150px; }
                table.data-table th:nth-child(5) { width: 150px; }
                table.data-table th:nth-child(6) { width: 150px; }

                table.data-table td:nth-child(3) { text-align: center; }
                .dark-style table.data-table td { border-color: rgba(255,255,255,0.05) !important; }
            </style>
            <table class="table table-hover data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Parking Name</th>
                        <th>Location</th>
                        <th>Entry Fee</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
    </div>

    {{-- Google Maps location modal --}}
    <div class="modal fade" id="parkingMapModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="parkingMapModalLabel">Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <iframe id="parkingMapFrame" src="" width="100%" height="450" style="border:0;" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
                <div class="modal-footer">
                    <a id="parkingMapLink" href="#" target="_blank" rel="noopener" class="btn btn-label-primary btn-sm">
                        <i class="bx bx-link-external me-1"></i> Open in Google Maps
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    @endsection

    @push('page-js')
    <script type="module">
    $(function () {
        var table = $('.data-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: false,
            autoWidth: false,
            
            order: [[5, 'desc']],
            ajax: "{{ route('parkings.index') }}",
            columns: [
                {data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false},
                {data: 'name', name: 'name'},
                {data: 'location', name: 'location', orderable: false},
                {data: 'entry_fee', name: 'entry_fee', orderable: false, searchable: false},
                {data: 'status', name: 'status'},
                {data: 'created_at', name: 'created_at'},
                {data: 'action', name: 'action', orderable: false, searchable: false},
            ]
        });

        var mapModalEl = document.getElementById('parkingMapModal');
        var mapModal = new bootstrap.Modal(mapModalEl);

        $('.data-table').on('click', '.parking-map-btn', function () {
            var $btn = $(this);
            var query = $btn.data('query');
            if (!query) return;

            $('#parkingMapModalLabel').text($btn.data('name') || 'Location');
            $('#parkingMapFrame').attr('src', 'https://www.google.com/maps?q=' + encodeURIComponent(query) + '&z=17&output=embed');
            $('#parkingMapLink').attr('href', 'https://www.google.com/maps?q=' + encodeURIComponent(query));
            mapModal.show();
        });

        mapModalEl.addEventListener('hidden.bs.modal', function () {
            $('#parkingMapFrame').attr('src', '');
        });
    });
    </script>
    @endpush
