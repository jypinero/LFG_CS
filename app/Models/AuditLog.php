<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
	use HasFactory;

	protected $fillable = [
		'actor_id',
		'actor_type',
		'action',
		'entity_type',
		'entity_id',
		'metadata',
		'ip',
		'user_agent',
	];

	protected $casts = [
		'metadata' => 'array',
	];

	public function actor()
	{
		return $this->belongsTo(User::class, 'actor_id');
	}
}


