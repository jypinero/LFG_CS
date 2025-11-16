<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventPlayerRating extends Model
{
	use HasFactory;

	protected $fillable = [
		'event_id',
		'rater_id',
		'ratee_id',
		'stars',
		'comment',
	];
}


