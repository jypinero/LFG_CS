# Missing Validations for MarketingController::createpost()

## 🔴 Critical Missing Validations

### 1. **User Authentication & Authorization**
```php
// MISSING: Check if user is authenticated
if (!auth()->check()) {
    return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
}

// MISSING: Verify user has permission to post for venue
if (!empty($validated['venue_id'])) {
    $venueUser = VenueUser::where('venue_id', $validated['venue_id'])
        ->where('user_id', $userId)
        ->first();
    
    if (!$venueUser || !in_array($venueUser->role, ['Owner', 'Manager', 'Staff'])) {
        return response()->json([
            'status' => 'error', 
            'message' => 'You do not have permission to post for this venue'
        ], 403);
    }
}
```

### 2. **Venue Existence & Status**
```php
// MISSING: Validate venue exists and is active
if (!empty($validated['venue_id'])) {
    $venue = Venue::find($validated['venue_id']);
    if (!$venue) {
        return response()->json(['status' => 'error', 'message' => 'Venue not found'], 404);
    }
    
    // MISSING: Check if venue is closed
    if ($venue->is_closed) {
        return response()->json([
            'status' => 'error', 
            'message' => 'This venue is closed and not accepting new posts/events'
        ], 403);
    }
}
```

### 3. **Facility Belongs to Venue**
```php
// MISSING: Validate facility belongs to the specified venue
if (!empty($validated['create_event']) && $validated['create_event']) {
    if (!empty($validated['event']['facility_id']) && !empty($validated['venue_id'])) {
        $facility = Facilities::where('id', $validated['event']['facility_id'])
            ->where('venue_id', $validated['venue_id'])
            ->first();
            
        if (!$facility) {
            return response()->json([
                'status' => 'error',
                'message' => 'Facility does not belong to the specified venue'
            ], 422);
        }
    }
}
```

### 4. **Date/Time Validations**
```php
// MISSING: Event date should not be in the past
if (!empty($validated['create_event']) && $validated['create_event']) {
    $eventDate = $validated['event']['date'] ?? null;
    if ($eventDate && Carbon::parse($eventDate)->isPast()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Event date cannot be in the past'
        ], 422);
    }
    
    // MISSING: Event date should not be too far in future (e.g., max 1 year)
    if ($eventDate && Carbon::parse($eventDate)->gt(now()->addYear())) {
        return response()->json([
            'status' => 'error',
            'message' => 'Event date cannot be more than 1 year in the future'
        ], 422);
    }
    
    // MISSING: Validate time format more flexibly (H:i or H:i:s)
    // Current: 'date_format:H:i:s' - too strict, should accept H:i as well
}
```

### 5. **Slots Validation**
```php
// MISSING: Maximum slots limit
'slots' => 'required_if:create_event,1|integer|min:1|max:1000', // Add max limit

// MISSING: Validate slots against facility capacity
if (!empty($validated['create_event']) && $validated['create_event']) {
    $facility = Facilities::find($validated['event']['facility_id']);
    if ($facility && $facility->capacity && $validated['event']['slots'] > $facility->capacity) {
        return response()->json([
            'status' => 'error',
            'message' => 'Slots cannot exceed facility capacity (' . $facility->capacity . ')'
        ], 422);
    }
}
```

### 6. **Event Type Validation**
```php
// MISSING: Validate event_type when provided
'event.event_type' => 'nullable|in:free for all,team vs team,tournament,multisport',

// MISSING: If event_type is 'team vs team', validate team_ids properly
if (!empty($validated['event']['event_type']) && $validated['event']['event_type'] === 'team vs team') {
    if (empty($validated['event']['team_ids']) || !is_array($validated['event']['team_ids'])) {
        return response()->json([
            'status' => 'error',
            'message' => 'team_ids is required for team vs team events'
        ], 422);
    }
    
    // MISSING: Validate user is member of at least one team
    $isMemberOfAnyTeam = TeamMember::where('user_id', $userId)
        ->whereIn('team_id', $validated['event']['team_ids'])
        ->exists();
        
    if (!$isMemberOfAnyTeam) {
        return response()->json([
            'status' => 'error',
            'message' => 'You must be a member of at least one of the specified teams'
        ], 403);
    }
    
    // MISSING: Validate teams exist and are active
    $teamsCount = Team::whereIn('id', $validated['event']['team_ids'])->count();
    if ($teamsCount !== count($validated['event']['team_ids'])) {
        return response()->json([
            'status' => 'error',
            'message' => 'One or more teams do not exist'
        ], 422);
    }
}
```

### 7. **Image Validation**
```php
// MISSING: Image dimension validation
'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120|dimensions:min_width=100,min_height=100,max_width=5000,max_height=5000',

// MISSING: Image aspect ratio validation (optional)
// Could add custom validation for aspect ratio if needed
```

### 8. **Content Validation**
```php
// MISSING: Caption length validation (currently max:2000, but should check if reasonable)
'caption' => 'nullable|string|max:2000|min:1', // Add min length

// MISSING: Location format validation
'location' => 'nullable|string|max:255|regex:/^[a-zA-Z0-9\s,.-]+$/', // Add format validation
```

### 9. **Marketing Post Uniqueness**
```php
// MISSING: Prevent duplicate posts (same user, same venue, same caption within short time)
if (!empty($validated['venue_id'])) {
    $recentDuplicate = MarketingPost::where('author_id', $userId)
        ->where('venue_id', $validated['venue_id'])
        ->where('caption', $validated['caption'] ?? '')
        ->where('created_at', '>', now()->subMinutes(5))
        ->exists();
        
    if ($recentDuplicate) {
        return response()->json([
            'status' => 'error',
            'message' => 'Duplicate post detected. Please wait before posting again.'
        ], 409);
    }
}
```

### 10. **Booking Conflict Check Enhancement**
```php
// MISSING: More comprehensive conflict check
// Current code checks for conflicts but should also check:
// - Cancelled events should be excluded (already done)
// - Check against bookings table as well, not just events
// - Check venue operating hours
// - Check facility availability

// MISSING: Check venue operating hours
if (!empty($validated['create_event']) && $validated['create_event']) {
    $venue = Venue::find($validated['venue_id']);
    if ($venue) {
        // Check if event time is within venue operating hours
        $eventDay = strtolower(Carbon::parse($validated['event']['date'])->format('l'));
        $operatingHours = $venue->operatingHours()->where('day_of_week', $eventDay)->first();
        
        if ($operatingHours) {
            $eventStart = Carbon::parse($validated['event']['start_time']);
            $eventEnd = Carbon::parse($validated['event']['end_time']);
            $venueOpen = Carbon::parse($operatingHours->open_time);
            $venueClose = Carbon::parse($operatingHours->close_time);
            
            if ($eventStart->lt($venueOpen) || $eventEnd->gt($venueClose)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Event time is outside venue operating hours'
                ], 422);
            }
        }
    }
}
```

### 11. **Sport Validation**
```php
// MISSING: Validate sport exists in sports table (already has exists:sports,name, but should verify)
// Current validation is good, but add check:
if (!empty($validated['event']['sport'])) {
    $sport = \App\Models\Sport::where('name', $validated['event']['sport'])->first();
    if (!$sport) {
        return response()->json([
            'status' => 'error',
            'message' => 'Invalid sport specified'
        ], 422);
    }
}
```

### 12. **Purpose Field Validation**
```php
// MISSING: Purpose field validation
'event.purpose' => 'nullable|string|max:500', // Add max length
```

### 13. **Created At Validation**
```php
// MISSING: Validate created_at is not in the future
'created_at' => 'nullable|date|before_or_equal:now',

// MISSING: Validate created_at is not too far in the past (e.g., max 30 days)
if (!empty($validated['created_at'])) {
    $createdAt = Carbon::parse($validated['created_at']);
    if ($createdAt->lt(now()->subDays(30))) {
        return response()->json([
            'status' => 'error',
            'message' => 'created_at cannot be more than 30 days in the past'
        ], 422);
    }
}
```

### 14. **Transaction Safety**
```php
// MISSING: Wrap entire operation in DB transaction
// Current code only wraps event/booking creation, but post and marketing post creation should be included

DB::beginTransaction();
try {
    // Create post
    // Create event/booking (if applicable)
    // Create marketing post
    DB::commit();
} catch (\Throwable $e) {
    DB::rollBack();
    return response()->json([
        'status' => 'error',
        'message' => 'Failed to create post',
        'error' => $e->getMessage()
    ], 500);
}
```

### 15. **Venue User Role Validation**
```php
// MISSING: More specific role validation
// Current code checks for 'owner','staff','manager' but should match exact case
// VenueUser roles are: 'Owner', 'Manager', 'Staff' (capitalized)

$venueRole = null;
if (!empty($validated['venue_id'])) {
    $vu = VenueUser::where('venue_id', $validated['venue_id'])
        ->where('user_id', $userId)
        ->first();
    if ($vu) {
        // MISSING: Case-sensitive role check
        if (!in_array($vu->role, ['Owner', 'Manager', 'Staff'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid role for venue posting'
            ], 403);
        }
        $venueRole = $vu->role;
    } else {
        // MISSING: If venue_id provided but user is not venue staff
        return response()->json([
            'status' => 'error',
            'message' => 'You are not authorized to post for this venue'
        ], 403);
    }
}
```

### 16. **Post Content Requirements**
```php
// MISSING: More specific content requirement validation
// Current: "at least one content: image OR caption OR location"
// Should also validate:
// - If only location provided, should it be a valid venue name?
// - If caption is empty but image provided, should caption be optional?
// - Minimum content quality check

if (empty($validated['caption']) && !$request->hasFile('image') && empty($validated['location'])) {
    return response()->json([
        'status' => 'error', 
        'message' => 'Please provide at least one of: image, caption, or location'
    ], 422);
}

// MISSING: If location is provided but not venue_id, validate location format
if (!empty($validated['location']) && empty($validated['venue_id'])) {
    // Location should be a valid string format
    if (strlen($validated['location']) < 3) {
        return response()->json([
            'status' => 'error',
            'message' => 'Location must be at least 3 characters'
        ], 422);
    }
}
```

### 17. **Event Name Generation**
```php
// MISSING: Validate event name if provided, or ensure generated name is valid
if (!empty($validated['create_event']) && $validated['create_event']) {
    // Current code: 'name' => $validated['event']['sport'] ?? ('Event by ' . auth()->user()->username)
    // MISSING: Should validate that generated name is not too long
    $eventName = $validated['event']['sport'] ?? ('Event by ' . auth()->user()->username);
    if (strlen($eventName) > 255) {
        return response()->json([
            'status' => 'error',
            'message' => 'Generated event name exceeds maximum length'
        ], 422);
    }
}
```

### 18. **Marketing Post Model Validation**
```php
// MISSING: Validate MarketingPost model exists and has required fields
// Should check if MarketingPost model and table exist before creating

if (!class_exists(\App\Models\MarketingPost::class)) {
    return response()->json([
        'status' => 'error',
        'message' => 'MarketingPost model not found'
    ], 500);
}
```

### 19. **Rate Limiting**
```php
// MISSING: Rate limiting for post creation
// Prevent spam posting

$recentPostsCount = MarketingPost::where('author_id', $userId)
    ->where('created_at', '>', now()->subHour())
    ->count();
    
if ($recentPostsCount >= 10) {
    return response()->json([
        'status' => 'error',
        'message' => 'Too many posts created recently. Please wait before posting again.'
    ], 429);
}
```

### 20. **File Upload Security**
```php
// MISSING: Additional file validation
'image' => [
    'nullable',
    'image',
    'mimes:jpeg,png,jpg,gif',
    'max:5120',
    function ($attribute, $value, $fail) {
        // Check file is actually an image (not just extension)
        if ($value && !@getimagesize($value->getRealPath())) {
            $fail('The ' . $attribute . ' must be a valid image file.');
        }
    },
],
```

---

## 📋 Summary Checklist

### Authentication & Authorization
- [ ] User authentication check
- [ ] Venue staff/owner permission check
- [ ] Role validation (case-sensitive)

### Data Validation
- [ ] Venue exists and is active
- [ ] Venue is not closed
- [ ] Facility belongs to venue
- [ ] Facility exists and is available
- [ ] Sport exists in database
- [ ] Teams exist and user is member (for team vs team)

### Date/Time Validation
- [ ] Event date not in past
- [ ] Event date not too far in future
- [ ] Time format validation (flexible)
- [ ] Time within venue operating hours
- [ ] created_at validation

### Business Logic
- [ ] Slots within facility capacity
- [ ] Maximum slots limit
- [ ] Booking conflict check (comprehensive)
- [ ] Duplicate post prevention
- [ ] Rate limiting

### Content Validation
- [ ] Image dimensions
- [ ] Image file type verification
- [ ] Caption length (min/max)
- [ ] Location format
- [ ] Content quality requirements

### Transaction Safety
- [ ] Entire operation in transaction
- [ ] Proper error handling and rollback

### Security
- [ ] File upload security
- [ ] SQL injection prevention (Laravel handles, but verify)
- [ ] XSS prevention (sanitize user input)

---

## 🔧 Recommended Implementation Order

1. **Critical Security** (Do First):
   - User authentication
   - Venue permission check
   - Facility-venue relationship

2. **Data Integrity** (Do Second):
   - Venue/facility existence
   - Date/time validations
   - Conflict checks

3. **Business Rules** (Do Third):
   - Slots validation
   - Operating hours
   - Rate limiting

4. **Content Quality** (Do Fourth):
   - Image validation
   - Content requirements
   - Duplicate prevention

5. **Enhancement** (Do Last):
   - Transaction wrapping
   - Better error messages
   - Logging

---

**Note:** Some validations may already be handled by Laravel's built-in validation rules, but explicit checks in code provide better error messages and security.







