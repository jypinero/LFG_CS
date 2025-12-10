# Frontend Integration Guide: Google OAuth Complete Registration Page

This guide shows how to integrate the Google OAuth incomplete registration flow with a proper UI page instead of displaying raw JSON values.

## Table of Contents
1. [Overview](#overview)
2. [API Integration](#api-integration)
3. [Page Component Structure](#page-component-structure)
4. [Step-by-Step Implementation](#step-by-step-implementation)
5. [Complete Code Examples](#complete-code-examples)

---

## Overview

When a user authenticates with Google OAuth but has incomplete registration, the backend returns:
```json
{
  "status": "incomplete",
  "requires_completion": true,
  "missing_fields": ["sports", "birthday", "sex"],
  "temp_token": "jwt-token-here",
  "user": {
    "id": 6,
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "role_id": 6
  }
}
```

The frontend should:
1. Store the temp token and user data
2. Display a beautiful completion form
3. Only show fields that are actually missing
4. Submit only missing fields to the backend

---

## API Integration

### 1. Update API Functions (`src/lib/api.js` or similar)

```javascript
const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8000/api';

/**
 * Handle Google OAuth callback
 */
export async function handleGoogleCallback() {
  try {
    const urlParams = new URLSearchParams(window.location.search);
    const code = urlParams.get('code');
    
    if (!code) {
      throw new Error('No authorization code received');
    }

    const response = await fetch(`${API_BASE_URL}/auth/google/callback?code=${encodeURIComponent(code)}`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
      },
    });

    const data = await response.json();
    
    // Check for HTTP errors
    if (!response.ok) {
      throw new Error(data.message || `HTTP error! status: ${response.status}`);
    }
    
    return data;
  } catch (error) {
    console.error('Google callback error:', error);
    throw error;
  }
}

/**
 * Complete social registration
 */
export async function completeSocialRegistration(formData) {
  const tempToken = localStorage.getItem('temp_auth_token') || localStorage.getItem('temp_token');
  
  if (!tempToken) {
    throw new Error('No temporary token found. Please try logging in again.');
  }

  const response = await fetch(`${API_BASE_URL}/auth/google/complete`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${tempToken}`,
    },
    body: JSON.stringify(formData),
  });

  const data = await response.json();

  if (!response.ok) {
    if (response.status === 401) {
      throw new Error('Session expired. Please try logging in again.');
    }
    if (response.status === 422) {
      // Validation errors
      throw new Error(data.message || 'Validation failed');
    }
    throw new Error(data.message || 'Failed to complete registration');
  }

  return data;
}

/**
 * Get available sports
 */
export async function getSports() {
  const response = await fetch(`${API_BASE_URL}/sports`);
  const data = await response.json();
  return data.sports || [];
}

/**
 * Get available roles
 */
export async function getRoles() {
  const response = await fetch(`${API_BASE_URL}/roles`);
  const data = await response.json();
  return data.roles || [];
}
```

---

## Page Component Structure

### File Structure (Next.js App Router)
```
src/
├── app/
│   ├── auth/
│   │   ├── google/
│   │   │   └── callback/
│   │   │       └── page.js          # Handles OAuth redirect
│   │   └── social/
│   │       └── complete/
│   │           └── page.js          # Completion form page
├── components/
│   └── auth/
│       ├── CompleteRegistrationForm.js
│       ├── FormStep.js
│       └── ProgressIndicator.js
└── lib/
    └── api.js                       # API functions
```

---

## Step-by-Step Implementation

### Step 1: Create Callback Handler Page

**File: `src/app/auth/google/callback/page.js`**

```javascript
'use client';

import { useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { handleGoogleCallback } from '@/lib/api';

export default function GoogleCallbackPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);
  const [errorDetails, setErrorDetails] = useState(null);

  useEffect(() => {
    // Check URL parameters (backend redirects here with data)
    const statusParam = searchParams.get('status');
    const errorParam = searchParams.get('error');
    const token = searchParams.get('token');
    const missingFields = searchParams.get('missing_fields');

    // Handle error from backend redirect
    if (errorParam) {
      setError('Authentication was cancelled or failed');
      setErrorDetails(errorParam);
      setStatus('error');
      return;
    }

    // Handle incomplete registration (from backend redirect)
    if (statusParam === 'incomplete' && token) {
      const missingFieldsArray = missingFields ? missingFields.split(',') : [];
      
      // Store incomplete registration data
      localStorage.setItem('temp_auth_token', token);
      localStorage.setItem('pending_user', JSON.stringify({
        id: searchParams.get('user_id'),
        first_name: searchParams.get('first_name'),
        last_name: searchParams.get('last_name'),
        email: searchParams.get('email'),
        role_id: searchParams.get('role_id') || null,
      }));
      localStorage.setItem('missing_fields', JSON.stringify(missingFieldsArray));
      
      // Redirect to completion page
      router.push('/auth/social/complete');
      return;
    }

    // Handle successful login (from backend redirect)
    if (statusParam === 'success' && token) {
      // Complete registration - save token and redirect
      localStorage.setItem('auth_token', token);
      localStorage.removeItem('temp_auth_token');
      localStorage.removeItem('pending_user');
      localStorage.removeItem('missing_fields');
      
      // Redirect based on user role or to dashboard
      router.push('/dashboard');
      return;
    }

    // Fallback: If we have a code parameter, call API directly (for API-only flows)
    const code = searchParams.get('code');
    if (code) {
      async function processCallback() {
        try {
          const data = await handleGoogleCallback();

          if (data.status === 'incomplete') {
            // Store incomplete registration data
            localStorage.setItem('temp_auth_token', data.temp_token);
            localStorage.setItem('pending_user', JSON.stringify(data.user));
            localStorage.setItem('missing_fields', JSON.stringify(data.missing_fields));
            
            // Redirect to completion page
            router.push('/auth/social/complete');
          } else if (data.status === 'success') {
            // Complete registration - save token and redirect
            localStorage.setItem('auth_token', data.authorization.token);
            localStorage.removeItem('temp_auth_token');
            localStorage.removeItem('pending_user');
            localStorage.removeItem('missing_fields');
            
            // Redirect based on user role or to dashboard
            router.push('/dashboard');
          } else {
            setError(data.message || 'Authentication failed');
            setErrorDetails(data.error || null);
            setStatus('error');
          }
        } catch (err) {
          setError(err.message || 'Failed to process authentication');
          setErrorDetails(err.toString());
          setStatus('error');
        }
      }
      processCallback();
    } else {
      // No parameters - shouldn't be here
      setError('No authorization data received');
      setStatus('error');
    }
  }, [router, searchParams]);

  // Loading state - Beautiful loading UI
  if (status === 'loading') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-indigo-100">
        <div className="text-center">
          <div className="relative">
            <div className="animate-spin rounded-full h-16 w-16 border-b-4 border-blue-600 mx-auto"></div>
            <div className="absolute inset-0 flex items-center justify-center">
              <div className="h-8 w-8 bg-blue-600 rounded-full animate-pulse"></div>
            </div>
          </div>
          <p className="mt-6 text-lg font-medium text-gray-700">Processing your authentication...</p>
          <p className="mt-2 text-sm text-gray-500">Please wait while we verify your account</p>
        </div>
      </div>
    );
  }

  // Error state - Beautiful error UI (no raw JSON displayed)
  if (status === 'error') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-red-50 to-pink-100 px-4">
        <div className="max-w-md w-full bg-white shadow-xl rounded-lg overflow-hidden">
          <div className="bg-gradient-to-r from-red-600 to-pink-600 px-6 py-8">
            <div className="flex items-center justify-center">
              <svg className="h-16 w-16 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <h2 className="mt-4 text-2xl font-bold text-white text-center">Authentication Error</h2>
          </div>
          
          <div className="px-6 py-6">
            <div className="bg-red-50 border-l-4 border-red-500 p-4 mb-4">
              <p className="text-red-700 font-medium">{error}</p>
              {errorDetails && (
                <p className="text-red-600 text-sm mt-2 opacity-75">
                  {typeof errorDetails === 'string' && errorDetails.length < 100 
                    ? errorDetails 
                    : 'Please try again or contact support if the problem persists'}
                </p>
              )}
            </div>
            
            <div className="space-y-3">
              <button
                onClick={() => router.push('/login')}
                className="w-full px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium"
              >
                Return to Login
              </button>
              <button
                onClick={() => window.location.reload()}
                className="w-full px-4 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors"
              >
                Try Again
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return null;
}
```

### Step 2: Create Complete Registration Page

**File: `src/app/auth/social/complete/page.js`**

```javascript
'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import CompleteRegistrationForm from '@/components/auth/CompleteRegistrationForm';

export default function CompleteRegistrationPage() {
  const router = useRouter();
  const [missingFields, setMissingFields] = useState([]);
  const [pendingUser, setPendingUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Load data from localStorage
    const storedMissingFields = localStorage.getItem('missing_fields');
    const storedPendingUser = localStorage.getItem('pending_user');
    const tempToken = localStorage.getItem('temp_auth_token') || localStorage.getItem('temp_token');

    if (!tempToken) {
      // No temp token means user shouldn't be here
      router.push('/login');
      return;
    }

    if (storedMissingFields) {
      try {
        setMissingFields(JSON.parse(storedMissingFields));
      } catch (e) {
        console.error('Failed to parse missing fields:', e);
      }
    }

    if (storedPendingUser) {
      try {
        setPendingUser(JSON.parse(storedPendingUser));
      } catch (e) {
        console.error('Failed to parse pending user:', e);
      }
    }

    // If no missing fields, redirect to home
    if (storedMissingFields && JSON.parse(storedMissingFields).length === 0) {
      router.push('/');
      return;
    }

    setLoading(false);
  }, [router]);

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-3xl mx-auto">
        <div className="bg-white shadow-xl rounded-lg overflow-hidden">
          <div className="bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-8">
            <h1 className="text-3xl font-bold text-white">
              Complete Your Profile
            </h1>
            <p className="mt-2 text-blue-100">
              Welcome {pendingUser?.first_name}! Just a few more details to get you started.
            </p>
          </div>
          
          <div className="px-6 py-8">
            <CompleteRegistrationForm
              missingFields={missingFields}
              pendingUser={pendingUser}
            />
          </div>
        </div>
      </div>
    </div>
  );
}
```

### Step 3: Create Form Component

**File: `src/components/auth/CompleteRegistrationForm.js`**

```javascript
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { completeSocialRegistration, getSports, getRoles } from '@/lib/api';
import ProgressIndicator from './ProgressIndicator';
import FormStep from './FormStep';

export default function CompleteRegistrationForm({ missingFields, pendingUser }) {
  const router = useRouter();
  const [currentStep, setCurrentStep] = useState(0);
  const [sports, setSports] = useState([]);
  const [roles, setRoles] = useState([]);
  const [formData, setFormData] = useState({
    birthday: '',
    sex: '',
    contact_number: '',
    barangay: '',
    city: '',
    province: '',
    zip_code: '',
    role_id: pendingUser?.role_id || '',
    sports: [],
  });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [validationErrors, setValidationErrors] = useState({});

  // Get available steps based on missing fields
  const getAvailableSteps = () => {
    const stepOrder = [
      'birthday',
      'sex',
      'contact_number',
      'barangay',
      'city',
      'province',
      'zip_code',
      'role_id',
      'sports',
    ];
    return stepOrder.filter(step => missingFields.includes(step));
  };

  const availableSteps = getAvailableSteps();

  useEffect(() => {
    // Load sports and roles
    async function loadData() {
      try {
        const [sportsData, rolesData] = await Promise.all([
          getSports(),
          getRoles(),
        ]);
        setSports(sportsData);
        setRoles(rolesData);
      } catch (err) {
        console.error('Failed to load data:', err);
      }
    }
    loadData();
  }, []);

  const handleNext = () => {
    const currentField = availableSteps[currentStep];
    
    // Validate current step
    if (needsField(currentField) && !isFieldValid(currentField)) {
      setValidationErrors({
        [currentField]: `Please fill in ${getFieldLabel(currentField)}`,
      });
      return;
    }

    setValidationErrors({});
    
    if (currentStep < availableSteps.length - 1) {
      setCurrentStep(currentStep + 1);
    }
  };

  const handlePrevious = () => {
    if (currentStep > 0) {
      setCurrentStep(currentStep - 1);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    setValidationErrors({});

    try {
      // Build submission data - only include missing fields
      const submissionData = {};
      
      availableSteps.forEach(field => {
        if (field === 'sports') {
          if (formData.sports.length > 0) {
            submissionData.sports = formData.sports;
          }
        } else if (formData[field]) {
          submissionData[field] = formData[field];
        }
      });

      const result = await completeSocialRegistration(submissionData);

      if (result.status === 'success') {
        // Clear temporary data
        localStorage.removeItem('temp_auth_token');
        localStorage.removeItem('temp_token');
        localStorage.removeItem('pending_user');
        localStorage.removeItem('missing_fields');
        
        // Save final token
        localStorage.setItem('auth_token', result.authorization.token);
        
        // Redirect to dashboard
        router.push('/dashboard');
      }
    } catch (err) {
      setError(err.message || 'Failed to complete registration');
    } finally {
      setLoading(false);
    }
  };

  const needsField = (field) => {
    return missingFields.includes(field);
  };

  const isFieldValid = (field) => {
    if (field === 'sports') {
      return formData.sports.length > 0;
    }
    return formData[field] !== '' && formData[field] !== null;
  };

  const getFieldLabel = (field) => {
    const labels = {
      birthday: 'Birthday',
      sex: 'Gender',
      contact_number: 'Contact Number',
      barangay: 'Barangay',
      city: 'City',
      province: 'Province',
      zip_code: 'Zip Code',
      role_id: 'Role',
      sports: 'Sports',
    };
    return labels[field] || field;
  };

  const currentField = availableSteps[currentStep];

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      {/* Progress Indicator */}
      <ProgressIndicator
        currentStep={currentStep + 1}
        totalSteps={availableSteps.length}
      />

      {/* Error Message */}
      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
          {error}
        </div>
      )}

      {/* Form Step */}
      <FormStep
        field={currentField}
        formData={formData}
        setFormData={setFormData}
        sports={sports}
        roles={roles}
        validationError={validationErrors[currentField]}
        pendingUser={pendingUser}
      />

      {/* Navigation Buttons */}
      <div className="flex justify-between pt-6 border-t">
        <button
          type="button"
          onClick={handlePrevious}
          disabled={currentStep === 0}
          className="px-6 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Previous
        </button>
        
        {currentStep < availableSteps.length - 1 ? (
          <button
            type="button"
            onClick={handleNext}
            className="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
          >
            Next
          </button>
        ) : (
          <button
            type="submit"
            disabled={loading}
            className="px-6 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50"
          >
            {loading ? 'Completing...' : 'Complete Registration'}
          </button>
        )}
      </div>
    </form>
  );
}
```

### Step 4: Create Progress Indicator Component

**File: `src/components/auth/ProgressIndicator.js`**

```javascript
export default function ProgressIndicator({ currentStep, totalSteps }) {
  const percentage = (currentStep / totalSteps) * 100;

  return (
    <div className="mb-8">
      <div className="flex justify-between items-center mb-2">
        <span className="text-sm font-medium text-gray-700">
          Step {currentStep} of {totalSteps}
        </span>
        <span className="text-sm text-gray-500">
          {Math.round(percentage)}% Complete
        </span>
      </div>
      <div className="w-full bg-gray-200 rounded-full h-2">
        <div
          className="bg-blue-600 h-2 rounded-full transition-all duration-300"
          style={{ width: `${percentage}%` }}
        ></div>
      </div>
    </div>
  );
}
```

### Step 5: Create Form Step Component

**File: `src/components/auth/FormStep.js`**

```javascript
export default function FormStep({
  field,
  formData,
  setFormData,
  sports,
  roles,
  validationError,
  pendingUser,
}) {
  const renderField = () => {
    switch (field) {
      case 'birthday':
        return (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Birthday *
            </label>
            <input
              type="date"
              value={formData.birthday}
              onChange={(e) => setFormData({ ...formData, birthday: e.target.value })}
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
              required
            />
            {validationError && (
              <p className="mt-1 text-sm text-red-600">{validationError}</p>
            )}
          </div>
        );

      case 'sex':
        return (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Gender *
            </label>
            <select
              value={formData.sex}
              onChange={(e) => setFormData({ ...formData, sex: e.target.value })}
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
              required
            >
              <option value="">Select gender...</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
            </select>
            {validationError && (
              <p className="mt-1 text-sm text-red-600">{validationError}</p>
            )}
          </div>
        );

      case 'contact_number':
        return (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Contact Number *
            </label>
            <input
              type="tel"
              value={formData.contact_number}
              onChange={(e) => setFormData({ ...formData, contact_number: e.target.value })}
              placeholder="+1234567890"
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
              required
            />
            {validationError && (
              <p className="mt-1 text-sm text-red-600">{validationError}</p>
            )}
          </div>
        );

      case 'barangay':
        return (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Barangay *
            </label>
            <input
              type="text"
              value={formData.barangay}
              onChange={(e) => setFormData({ ...formData, barangay: e.target.value })}
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
              required
            />
            {validationError && (
              <p className="mt-1 text-sm text-red-600">{validationError}</p>
            )}
          </div>
        );

      case 'city':
        return (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              City *
            </label>
            <input
              type="text"
              value={formData.city}
              onChange={(e) => setFormData({ ...formData, city: e.target.value })}
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
              required
            />
            {validationError && (
              <p className="mt-1 text-sm text-red-600">{validationError}</p>
            )}
          </div>
        );

      case 'province':
        return (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Province *
            </label>
            <input
              type="text"
              value={formData.province}
              onChange={(e) => setFormData({ ...formData, province: e.target.value })}
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
              required
            />
            {validationError && (
              <p className="mt-1 text-sm text-red-600">{validationError}</p>
            )}
          </div>
        );

      case 'zip_code':
        return (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Zip Code *
            </label>
            <input
              type="text"
              value={formData.zip_code}
              onChange={(e) => setFormData({ ...formData, zip_code: e.target.value })}
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
              required
            />
            {validationError && (
              <p className="mt-1 text-sm text-red-600">{validationError}</p>
            )}
          </div>
        );

      case 'role_id':
        return (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Role *
            </label>
            <select
              value={formData.role_id || pendingUser?.role_id || ''}
              onChange={(e) => setFormData({ ...formData, role_id: parseInt(e.target.value) })}
              className="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"
              required
            >
              <option value="">Select role...</option>
              {roles.map((role) => (
                <option key={role.id} value={role.id}>
                  {role.name}
                </option>
              ))}
            </select>
            {validationError && (
              <p className="mt-1 text-sm text-red-600">{validationError}</p>
            )}
          </div>
        );

      case 'sports':
        return (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-4">
              Select Your Sports * (Select at least one)
            </label>
            <div className="space-y-3">
              {sports.map((sport) => {
                const isSelected = formData.sports.some((s) => s.id === sport.id);
                const selectedSport = formData.sports.find((s) => s.id === sport.id);

                return (
                  <div
                    key={sport.id}
                    className={`border rounded-lg p-4 ${
                      isSelected ? 'border-blue-500 bg-blue-50' : 'border-gray-200'
                    }`}
                  >
                    <div className="flex items-center justify-between">
                      <div className="flex items-center">
                        <input
                          type="checkbox"
                          id={`sport-${sport.id}`}
                          checked={isSelected}
                          onChange={(e) => {
                            const currentSports = [...formData.sports];
                            if (e.target.checked) {
                              currentSports.push({ id: sport.id, level: 'beginner' });
                            } else {
                              const index = currentSports.findIndex((s) => s.id === sport.id);
                              if (index > -1) currentSports.splice(index, 1);
                            }
                            setFormData({ ...formData, sports: currentSports });
                          }}
                          className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                        />
                        <label
                          htmlFor={`sport-${sport.id}`}
                          className="ml-3 text-sm font-medium text-gray-700"
                        >
                          {sport.name}
                        </label>
                      </div>
                      {isSelected && (
                        <select
                          value={selectedSport?.level || 'beginner'}
                          onChange={(e) => {
                            const updatedSports = formData.sports.map((s) =>
                              s.id === sport.id ? { ...s, level: e.target.value } : s
                            );
                            setFormData({ ...formData, sports: updatedSports });
                          }}
                          className="ml-4 px-3 py-1 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500"
                        >
                          <option value="beginner">Beginner</option>
                          <option value="competitive">Competitive</option>
                          <option value="professional">Professional</option>
                        </select>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
            {validationError && (
              <p className="mt-2 text-sm text-red-600">{validationError}</p>
            )}
          </div>
        );

      default:
        return null;
    }
  };

  return (
    <div className="space-y-4">
      <h2 className="text-2xl font-semibold text-gray-800 capitalize">
        {field.replace('_', ' ')}
      </h2>
      <p className="text-gray-600 mb-6">
        Please provide your {field.replace('_', ' ')} information.
      </p>
      {renderField()}
    </div>
  );
}
```

---

## Styling Requirements

### Install Tailwind CSS (if not already installed)

```bash
npm install -D tailwindcss postcss autoprefixer
npx tailwindcss init -p
```

### Update `tailwind.config.js`

```javascript
module.exports = {
  content: [
    './src/pages/**/*.{js,ts,jsx,tsx,mdx}',
    './src/components/**/*.{js,ts,jsx,tsx,mdx}',
    './src/app/**/*.{js,ts,jsx,tsx,mdx}',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
}
```

---

## Key Features

✅ **Dynamic Form Rendering**: Only shows fields that are missing  
✅ **Step-by-Step Navigation**: Multi-step form with progress indicator  
✅ **Pre-filled Data**: Uses existing user data from Google OAuth  
✅ **Validation**: Client-side validation before submission  
✅ **Error Handling**: Displays validation and network errors  
✅ **Beautiful UI**: Modern, responsive design with Tailwind CSS  
✅ **Token Management**: Properly handles temp tokens and final tokens  

---

## Testing Checklist

- [ ] OAuth callback redirects correctly
- [ ] Incomplete registration shows completion page
- [ ] Only missing fields are displayed
- [ ] Form validation works for each step
- [ ] Sports selection works correctly
- [ ] Submission only sends missing fields
- [ ] Success redirects to dashboard
- [ ] Error handling displays properly
- [ ] Token is saved correctly
- [ ] Temporary data is cleared on success

---

## Environment Variables

### Frontend (`.env.local`)

Make sure your `.env.local` has:

```env
NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api
```

### Backend (`.env`)

**IMPORTANT**: Add the frontend URL to your Laravel `.env` file:

```env
FRONTEND_URL=http://localhost:3000
```

This tells the backend where to redirect browser requests after OAuth callbacks. Update this to your production frontend URL when deploying.

**Note**: The backend will automatically redirect browser requests to this frontend URL with the authentication data as URL parameters. The frontend callback page handles these parameters and stores them in localStorage.

---

## Notes

- The backend now only validates fields that are actually missing
- The frontend only sends fields that need to be completed
- The form adapts dynamically based on `missing_fields` array
- All temporary data is cleared after successful completion

