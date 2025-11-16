<?php

namespace App\Http\Requests\Admin\Users;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'first_name' => 'required|string|max:255',
			'last_name' => 'required|string|max:255',
			'username' => 'nullable|string|max:255|unique:users,username',
			'email' => 'required|email|unique:users,email',
			'password' => 'required|string|min:8',
			'role' => 'nullable|string',
			'role_id' => 'nullable|integer|exists:roles,id',
		];
	}
}


