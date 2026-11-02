<?php

namespace App\Http\Controllers;

use App\Models\Parking;
use App\Models\User;
use App\Models\ParkingAttribute;
use App\Http\Requests\StoreParkingRequest;
use App\Http\Requests\UpdateParkingRequest;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\DB;

class ParkingController extends Controller
{
    /**
     * Display a listing of the resource.
     * 
     * Handles DataTable AJAX requests and initial page load.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $query = Parking::with(['fees'])->latest();
            
            // Limit to own parkings if merchant
            if (auth()->user()->hasRole('merchant')) {
                $query->where('merchant_id', auth()->id());
            } elseif (auth()->user()->hasRole('staff')) {
                $user = auth()->user();
                $query->where(function($q) use ($user) {
                    $q->whereIn('merchant_id', $user->merchants->pluck('id'))
                      ->orWhereHas('assignedStaff', function($sq) use ($user) {
                          $sq->where('user_id', $user->id);
                      });
                });
            }

            return DataTables::of($query)
                ->addIndexColumn()
                ->editColumn('name', function($row) {
                    return '<div class="d-flex align-items-center">
                                <img src="' . $row->featured_image_url . '" alt="Parking" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                <span>' . $row->name . '</span>
                            </div>';
                })
                ->editColumn('location', function($row) {
                    $mapQuery = $row->latitude && $row->longitude
                        ? $row->latitude . ',' . $row->longitude
                        : $row->location;

                    if (!$mapQuery) {
                        return '<span class="text-muted">N/A</span>';
                    }

                    return '<button type="button" class="btn btn-icon btn-sm btn-label-primary parking-map-btn"'
                        . ' title="' . e($row->location ?: 'View on map') . '"'
                        . ' data-name="' . e($row->name) . '"'
                        . ' data-query="' . e($mapQuery) . '">'
                        . '<i class="bx bxs-map-pin"></i></button>';
                })
                ->addColumn('entry_fee', function($row) {
                    $firstFee = $row->fees->first();
                    return $firstFee ? 'Rs. ' . number_format($firstFee->price_rs, 2) : 'N/A';
                })
                ->editColumn('status', function($row) {
                    $class = $row->status === 'active' ? 'bg-label-success' : 'bg-label-secondary';
                    return '<span class="badge ' . $class . '">' . __('messages.' . $row->status) . '</span>';
                })
                ->editColumn('created_at', function($row){
                    return formatDate($row->created_at);
                })
                ->addColumn('action', function($row){
                    $canEdit = auth()->user()->hasRole('super-admin', 'merchant', 'staff');
                    $canDelete = auth()->user()->hasRole('super-admin');
                    
                    $actions = '';
                    // View Button
                    $actions .= '<a href="'.route('parkings.show', $row->id).'" class="btn btn-icon btn-sm btn-dark me-1" title="View"><i class="bx bx-show"></i></a>';

                    // Toggle Status
                    if ($canEdit) {
                        $icon = $row->status === 'opened' ? 'bx-lock-alt' : 'bx-lock-open-alt';
                        $btnClass = $row->status === 'opened' ? 'btn-warning' : 'btn-success';
                        $title = $row->status === 'opened' ? 'Close Parking' : 'Open Parking';
                        
                        $actions .= '<form action="'.route('parkings.toggle-status', $row->id).'" method="POST" style="display:inline-block">
                                        '.csrf_field().'
                                        <button type="submit" class="btn btn-icon btn-sm '.$btnClass.' me-1" title="'.$title.'"><i class="bx '.$icon.'"></i></button>
                                    </form>';
                    }

                    // Edit Button
                    if ($canEdit) {
                        $actions .= '<a href="'.route('parkings.edit', $row->id).'" class="btn btn-icon btn-sm btn-primary me-1" title="Edit"><i class="bx bx-edit-alt"></i></a>';
                    }
                    // Delete Button
                    if ($canDelete) {
                        $actions .= '<form action="'.route('parkings.destroy', $row->id).'" method="POST" style="display:inline-block">
                                        '.csrf_field().'
                                        '.method_field('DELETE').'
                                        <button type="submit" class="btn btn-icon btn-sm btn-danger delete-btn" title="Delete"><i class="bx bx-trash"></i></button>
                                    </form>';
                    }
                    return $actions;
                })
                ->rawColumns(['name', 'location', 'status', 'action'])
                ->make(true);
        }

        return view('modules.parkings.index');
    }

    /**
     * Toggle the status of the parking.
     */
    public function toggleStatus(Parking $parking)
    {
        $user = auth()->user();
        $isOwner = $user->hasRole('merchant') && $parking->merchant_id == $user->id;
        $isMerchantStaff = $user->hasRole('staff') && $user->merchants()->where('merchant_id', $parking->merchant_id)->exists();
        $isAssignedStaff = $user->hasRole('staff') && $parking->assignedStaff()->where('user_id', $user->id)->exists();
        $isSuperAdmin = $user->hasRole('super-admin');

        if (!$isOwner && !$isMerchantStaff && !$isAssignedStaff && !$isSuperAdmin) abort(403);

        $parking->status = $parking->status === 'opened' ? 'closed' : 'opened';
        $parking->save();

        return redirect()->back()->with('success', 'Parking status updated successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Parking $parking)
    {
        $user = auth()->user();
        $isOwner = $user->hasRole('merchant') && $parking->merchant_id == $user->id;
        $isMerchantStaff = $user->hasRole('staff') && $user->merchants()->where('merchant_id', $parking->merchant_id)->exists();
        $isAssignedStaff = $user->hasRole('staff') && $parking->assignedStaff()->where('user_id', $user->id)->exists();
        $isSuperAdmin = $user->hasRole('super-admin');

        if (!$isOwner && !$isMerchantStaff && !$isAssignedStaff && !$isSuperAdmin) abort(403);
        
        $parking->load(['attributes', 'merchant']);
        $parking->loadCount('ongoingRides');
        return view('modules.parkings.show', compact('parking'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        if (!auth()->user()->hasRole('super-admin')) abort(403);
        $parking = new Parking();
        $merchants = User::whereHas('roles', function($q){ $q->where('slug', 'merchant'); })->get();
        $allAttributes = ParkingAttribute::all();
        return view('modules.parkings.create', compact('parking', 'merchants', 'allAttributes'));
    }

    /**
     * Store a newly created resource in storage.
     * 
     * Uses StoreParkingRequest for validation.
     */
    public function store(StoreParkingRequest $request)
    {
        if (!auth()->user()->hasRole('super-admin')) abort(403);

        DB::transaction(function() use ($request) {
            $parking = Parking::create($request->except('fees', 'attributes'));
            
            if ($request->has('fees')) {
                foreach ($request->input('fees') as $index => $feeData) {
                    $parking->fees()->create(array_merge($feeData, ['order' => $index]));
                }
            }

            if ($request->has('attributes')) {
                $parking->attributes()->sync($request->input('attributes'));
            }

            // Handle Featured Image
            if ($request->hasFile('featured_image')) {
                $parking->addMedia($request->file('featured_image'), 'featured');
            }

            // Handle Gallery Images
            if ($request->hasFile('gallery_images')) {
                foreach ($request->file('gallery_images') as $file) {
                    $parking->addMedia($file, 'gallery');
                }
            }
        });

        return redirect()->route('parkings.index')->with('success', 'Parking created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Parking $parking)
    {
        $user = auth()->user();
        $isOwner = $user->hasRole('merchant') && $parking->merchant_id == $user->id;
        $isMerchantStaff = $user->hasRole('staff') && $user->merchants()->where('merchant_id', $parking->merchant_id)->exists();
        $isAssignedStaff = $user->hasRole('staff') && $parking->assignedStaff()->where('user_id', $user->id)->exists();
        $isSuperAdmin = $user->hasRole('super-admin');

        if (!$isOwner && !$isMerchantStaff && !$isAssignedStaff && !$isSuperAdmin) abort(403);
        
        $merchants = User::whereHas('roles', function($q){ $q->where('slug', 'merchant'); })->get();
        $allAttributes = ParkingAttribute::all();
        $parking->load('attributes');
        
        return view('modules.parkings.edit', compact('parking', 'merchants', 'allAttributes'));
    }

    /**
     * Update the specified resource in storage.
     * 
     * Uses UpdateParkingRequest for validation.
     */
    public function update(UpdateParkingRequest $request, Parking $parking)
    {
        $user = auth()->user();
        $isOwner = $user->hasRole('merchant') && $parking->merchant_id == $user->id;
        $isMerchantStaff = $user->hasRole('staff') && $user->merchants()->where('merchant_id', $parking->merchant_id)->exists();
        $isAssignedStaff = $user->hasRole('staff') && $parking->assignedStaff()->where('user_id', $user->id)->exists();
        $isSuperAdmin = $user->hasRole('super-admin');

        if (!$isOwner && !$isMerchantStaff && !$isAssignedStaff && !$isSuperAdmin) abort(403);
        
        DB::transaction(function() use ($request, $parking) {
            $parking->update($request->except('fees', 'attributes'));
            
            $parking->fees()->delete();
            if ($request->has('fees')) {
                foreach ($request->input('fees') as $index => $feeData) {
                    $parking->fees()->create(array_merge($feeData, ['order' => $index]));
                }
            }

            if ($request->has('attributes')) {
                $parking->attributes()->sync($request->input('attributes'));
            } else {
                $parking->attributes()->detach();
            }

            // Handle Featured Image
            if ($request->hasFile('featured_image')) {
                $parking->clearMediaCollection('featured');
                $parking->addMedia($request->file('featured_image'), 'featured');
            }

            // Handle Gallery Images
            if ($request->hasFile('gallery_images')) {
                foreach ($request->file('gallery_images') as $file) {
                    $parking->addMedia($file, 'gallery');
                }
            }
        });

        return redirect()->route('parkings.index')->with('success', 'Parking updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Parking $parking)
    {
        if (!auth()->user()->hasRole('super-admin')) abort(403);

        // Check for associated rides or taps (using morphMany if applicable, but rides/taps use morphTo)
        $hasRides = \App\Models\Ride::where('reference_type', Parking::class)->where('reference_id', $parking->id)->exists();
        $hasTaps = \App\Models\Tap::where('reference_type', Parking::class)->where('reference_id', $parking->id)->exists();
        
        if ($hasRides || $hasTaps || $parking->merchantIncomes()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete parking because it has associated transaction history or active rides.');
        }

        $parking->delete();
        return redirect()->route('parkings.index')->with('success', 'Parking deleted successfully.');
    }
}
