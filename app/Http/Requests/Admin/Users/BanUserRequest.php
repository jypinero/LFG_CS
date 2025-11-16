<?php

namespace App\Http\Requests\Admin\Users;

use Illuminate\Foundation\Http\FormRequest;

class BanUserRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'reason' => 'required|string|max:1000',
			'ban_type' => 'required|string|max:100',
			'start_date' => 'nullable|date',
			'end_date' => 'nullable|date|after_or_equal:start_date',
			'event_id' => 'nullable|integer|exists:events,id',
		];
	}
}


