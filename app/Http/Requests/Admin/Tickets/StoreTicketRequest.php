<?php

namespace App\Http\Requests\Admin\Tickets;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'submitted_by' => 'required|integer|exists:users,id',
			'subject' => 'required|string|max:255',
			'description' => 'required|string',
			'assigned_to' => 'nullable|integer|exists:users,id',
		];
	}
}


