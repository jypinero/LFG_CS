<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserOtp extends Model
{
	use HasFactory;

	const TYPE_LOGIN = 'login';
	const TYPE_VERIFICATION = 'verification';

	protected $fillable = [
		'user_id',
		'type',
		'code',
		'expires_at',
		'consumed_at',
		'attempts',
		'ip',
		'user_agent',
	];

	protected $casts = [
		'expires_at' => 'datetime',
		'consumed_at' => 'datetime',
	];

	public function user()
	{
		return $this->belongsTo(User::class);
	}
}


