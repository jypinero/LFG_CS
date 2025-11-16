<?php

namespace App\Http\Requests\Admin\Venues;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVenueRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'name' => 'sometimes|string|max:255',
			'description' => 'sometimes|nullable|string',
			'address' => 'sometimes|nullable|string|max:500',
			'phone_number' => 'sometimes|nullable|string|max:50',
			'email' => 'sometimes|nullable|email',
			'is_closed' => 'sometimes|boolean',
			'closed_reason' => 'sometimes|nullable|string|max:1000',
		];
	}
}


