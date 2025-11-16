<?php

namespace App\Http\Requests\Admin\Tickets;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'subject' => 'sometimes|string|max:255',
			'description' => 'sometimes|string',
			'assigned_to' => 'sometimes|nullable|integer|exists:users,id',
			'status' => 'sometimes|string|in:open,pending,resolved,closed',
		];
	}
}


