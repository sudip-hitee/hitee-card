<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\HasMedia;

class Parking extends Model
{
    use SoftDeletes, HasMedia;
    protected $fillable = ['name', 'location', 'total_capacity', 'status', 'merchant_id', 'latitude', 'longitude', 'external_id', 'owner_name', 'owner_phone', 'notes', 'is_hitee_verified'];

    protected $appends = ['featured_image_url'];

    protected $casts = [
        'merchant_id' => 'integer',
        'total_capacity' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'is_hitee_verified' => 'boolean',
    ];

    public function ongoingRides()
    {
        return $this->morphMany(Ride::class, 'reference')->where('status', 'ongoing');
    }

    public function getFeaturedImageUrlAttribute()
    {
        return $this->getFirstMediaUrl('featured', asset('assets/img/no_image.png'));
    }

    public function merchant()
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function assignedStaff()
    {
        return $this->belongsToMany(User::class, 'parking_user', 'parking_id', 'user_id')->withTimestamps();
    }

    public function fees()
    {
        return $this->hasMany(ParkingFee::class)->orderBy('order');
    }

    public function attributes()
    {
        return $this->belongsToMany(ParkingAttribute::class);
    }

    public function merchantIncomes()
    {
        return $this->morphMany(MerchantIncome::class, 'reference');
    }

    public function totalIncome()
    {
        return $this->merchantIncomes()->sum('amount');
    }
}
