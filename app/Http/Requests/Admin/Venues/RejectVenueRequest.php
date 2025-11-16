<?php

namespace App\Http\Requests\Admin\Venues;

use Illuminate\Foundation\Http\FormRequest;

class RejectVenueRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'reason' => 'sometimes|nullable|string|max:1000',
		];
	}
}


