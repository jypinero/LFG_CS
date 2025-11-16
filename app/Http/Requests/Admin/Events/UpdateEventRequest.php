<?php

namespace App\Http\Requests\Admin\Events;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
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
			'date' => 'sometimes|date',
			'start_time' => 'sometimes|string',
			'end_time' => 'sometimes|string',
			'is_approved' => 'sometimes|boolean',
		];
	}
}


