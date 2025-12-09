<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CompleteSocialRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = Auth::guard('api')->user();
        
        if (!$user) {
            return []; // Will fail authorization check anyway
        }
        
        $missingFields = $this->getMissingFields($user);
        
        $rules = [];
        
        // Only require fields that are actually missing
        if (in_array('birthday', $missingFields)) {
            $rules['birthday'] = 'required|date';
        }
        if (in_array('sex', $missingFields)) {
            $rules['sex'] = 'required|in:male,female,other';
        }
        if (in_array('contact_number', $missingFields)) {
            $rules['contact_number'] = 'required|string|max:255';
        }
        if (in_array('barangay', $missingFields)) {
            $rules['barangay'] = 'required|string|max:255';
        }
        if (in_array('city', $missingFields)) {
            $rules['city'] = 'required|string|max:255';
        }
        if (in_array('province', $missingFields)) {
            $rules['province'] = 'required|string|max:255';
        }
        if (in_array('zip_code', $missingFields)) {
            $rules['zip_code'] = 'required|string|max:255';
        }
        if (in_array('role_id', $missingFields)) {
            $rules['role_id'] = 'required|exists:roles,id';
        }
        if (in_array('sports', $missingFields)) {
            $rules['sports'] = 'required|array|min:1';
            $rules['sports.*.id'] = 'required|exists:sports,id';
            $rules['sports.*.level'] = 'required|in:beginner,competitive,professional';
        }
        
        return $rules;
    }

    /**
     * Get missing required fields for the authenticated user.
     */
    private function getMissingFields($user): array
    {
        $missingFields = [];

        if (!$user->birthday) {
            $missingFields[] = 'birthday';
        }
        if (!$user->sex) {
            $missingFields[] = 'sex';
        }
        if (!$user->contact_number) {
            $missingFields[] = 'contact_number';
        }
        if (!$user->barangay) {
            $missingFields[] = 'barangay';
        }
        if (!$user->city) {
            $missingFields[] = 'city';
        }
        if (!$user->province) {
            $missingFields[] = 'province';
        }
        if (!$user->zip_code) {
            $missingFields[] = 'zip_code';
        }
        if (!$user->role_id) {
            $missingFields[] = 'role_id';
        }
        if (!$user->userProfile || !$user->userProfile->main_sport_id) {
            $missingFields[] = 'sports';
        }

        return $missingFields;
    }

    public function messages(): array
    {
        return [
            'birthday.required' => 'Birthday is required.',
            'birthday.date' => 'Please provide a valid date.',
            'sex.required' => 'Sex is required.',
            'sex.in' => 'Sex must be male, female, or other.',
            'contact_number.required' => 'Contact number is required.',
            'barangay.required' => 'Barangay is required.',
            'city.required' => 'City is required.',
            'province.required' => 'Province is required.',
            'zip_code.required' => 'Zip code is required.',
            'role_id.required' => 'Role is required.',
            'role_id.exists' => 'Selected role is invalid.',
            'sports.required' => 'At least one sport is required.',
            'sports.array' => 'Sports must be an array.',
            'sports.min' => 'At least one sport is required.',
            'sports.*.id.required' => 'Sport ID is required.',
            'sports.*.id.exists' => 'Selected sport is invalid.',
            'sports.*.level.required' => 'Sport level is required.',
            'sports.*.level.in' => 'Sport level must be beginner, competitive, or professional.',
        ];
    }
}


