<?php

namespace App\Http\Requests\Events;

use Illuminate\Foundation\Http\FormRequest;

class SubmitRatingRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'ratee_id' => 'required|integer',
			'stars' => 'required|integer|min:1|max:5',
			'comment' => 'nullable|string|max:1000',
		];
	}
}


