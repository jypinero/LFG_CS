<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FacilityPhoto extends Model
{
    use HasFactory;

    // you store uploaded_at manually, not Eloquent timestamps
    public $timestamps = false;

    protected $table = 'facility_photos';

    protected $fillable = [
        'facility_id',
        'image_path',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    // make sure this refers to your Facilities model (you use plural 'Facilities' elsewhere)
    public function facility()
    {
        return $this->belongsTo(Facilities::class, 'facility_id');
    }

    public function photos()
    {
        return $this->hasMany(FacilityPhoto::class, 'facility_id');
    }
}