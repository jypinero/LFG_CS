<?php

namespace App\Http\Requests\Admin\Users;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		$id = $this->route('id');
		return [
			'first_name' => 'sometimes|string|max:255',
			'last_name' => 'sometimes|string|max:255',
			'username' => 'sometimes|nullable|string|max:255|unique:users,username,'.$id,
			'email' => 'sometimes|email|unique:users,email,'.$id,
			'password' => 'sometimes|string|min:8',
			'role' => 'sometimes|nullable|string',
			'role_id' => 'sometimes|nullable|integer|exists:roles,id',
		];
	}
}


