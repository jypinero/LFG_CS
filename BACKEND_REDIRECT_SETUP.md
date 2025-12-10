# Backend Redirect Setup - Google OAuth

## ✅ Changes Applied

### 1. Controller Updated
**File**: `app/Http/Controllers/Auth/SocialiteController.php`

- Added browser request detection
- Redirects browser requests to frontend with URL parameters
- Still returns JSON for API requests
- Handles all scenarios: incomplete, success, and error

### 2. Configuration Added
**File**: `config/app.php`

- Added `frontend_url` configuration
- Reads from `FRONTEND_URL` environment variable
- Defaults to `http://localhost:3000`

## Required Configuration

### Add to `.env` file:

```env
FRONTEND_URL=http://localhost:3000
```

**For production**, update to:
```env
FRONTEND_URL=https://yourdomain.com
```

### Clear Config Cache

After updating `.env`, run:
```bash
php artisan config:clear
```

## How It Works

### Browser Request Flow:
1. User clicks "Login with Google"
2. Google redirects to: `http://127.0.0.1:8000/api/auth/google/callback?code=...`
3. Backend detects browser request
4. Backend processes OAuth and redirects to: `http://localhost:3000/auth/google/callback?status=incomplete&token=...&missing_fields=...`
5. Frontend callback page handles URL parameters
6. Frontend stores data and redirects to completion page

### API Request Flow:
1. API client calls: `GET /api/auth/google/callback?code=...`
2. Backend detects API request (wantsJson/expectsJson)
3. Backend returns JSON response
4. API client handles JSON response

## URL Parameters Passed to Frontend

### Incomplete Registration:
```
/auth/google/callback?status=incomplete&token=...&missing_fields=birthday,sex,sports&user_id=13&first_name=John&last_name=Doe&email=john@example.com&role_id=2
```

### Success:
```
/auth/google/callback?status=success&token=...
```

### Error:
```
/auth/google/callback?error=Authentication%20failed
```

## Frontend Implementation

The frontend callback page (`/auth/google/callback`) should:
1. Read URL parameters
2. Store `token` as `temp_auth_token` in localStorage
3. Parse `missing_fields` (comma-separated) into array
4. Store user data in localStorage
5. Redirect to `/auth/social/complete` for incomplete registrations
6. Redirect to `/dashboard` for successful logins

See `FRONTEND_INTEGRATION_GUIDE.md` for complete frontend implementation.


