<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VenueSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan',
        'amount',
        'status',
        'paymongo_intent_id',
        'paymongo_payment_id',
        'starts_at',
        'ends_at',
    ];
}
