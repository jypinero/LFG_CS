# Venue Analytics API Guide

## Overview

This guide provides comprehensive documentation for the Venue Analytics API endpoints, including filter parameters, request/response formats, and frontend integration patterns.

## Base URL

```
/api/venues
```

All endpoints require authentication via JWT token:
```
Authorization: Bearer {token}
```

---

## Analytics Endpoint

### GET `/api/venues/analytics/{venueId?}`

Get analytics data for venues managed by the authenticated user.

#### URL Parameters

- `venueId` (optional, integer): Legacy parameter - use `venue_id` query parameter instead

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `venue_id` | integer | No | Filter by specific venue ID |
| `facility_id` | integer | No | Filter by specific facility ID (requires `venue_id`) |
| `period` | string | No | Date range period. Options: `all`, `this_week`, `month`, `semi_annual`, `annual`, `custom` (default: `all`) |
| `start_date` | date (YYYY-MM-DD) | Yes* | Start date for custom period (required when `period=custom`) |
| `end_date` | date (YYYY-MM-DD) | Yes* | End date for custom period (required when `period=custom`) |

\* Required only when `period=custom`

#### Period Definitions

- **`all`** (default): All-time data, no date filtering
- **`this_week`**: Current week (Monday to Sunday)
- **`month`**: Current calendar month (1st to last day)
- **`semi_annual`**: Last 6 months from today
- **`annual`**: Last 12 months from today
- **`custom`**: Custom date range using `start_date` and `end_date`

#### Example Requests

```bash
# Get analytics for all venues (all-time)
GET /api/venues/analytics

# Get analytics for specific venue (current month)
GET /api/venues/analytics?venue_id=12&period=month

# Get analytics for specific facility (last 6 months)
GET /api/venues/analytics?venue_id=12&facility_id=5&period=semi_annual

# Get analytics with custom date range
GET /api/venues/analytics?venue_id=12&period=custom&start_date=2025-01-01&end_date=2025-01-31

# Get analytics for all venues (current week)
GET /api/venues/analytics?period=this_week
```

#### Response Format

```json
{
  "status": "success",
  "analytics": {
    "filters_applied": {
      "venue_id": 12,
      "facility_id": null,
      "period": "month",
      "date_range": {
        "period": "month",
        "start": "2025-01-01",
        "end": "2025-01-31"
      }
    },
    "summary": {
      "revenue": 125000.00,
      "events": 48,
      "participants": 245,
      "average_participants": 5.1
    },
    "weekly_revenue": [
      {
        "day": "Mon",
        "revenue": 15000.00,
        "date": "2025-01-06"
      },
      {
        "day": "Tue",
        "revenue": 12000.00,
        "date": "2025-01-07"
      }
      // ... more days
    ],
    "recent_events": [
      {
        "id": 123,
        "venue_id": 12,
        "name": "Basketball Match",
        "date": "2025-01-15",
        "facility_id": 5
      }
      // ... more events (up to 20)
    ],
    "venue_performance": [
      {
        "venue_id": 12,
        "venue_name": "Court Complex A",
        "address": "123 Sports Street",
        "events": 25,
        "participants": 125,
        "earnings": 65000.00
      }
      // ... more venues
    ],
    "facilities": [
      {
        "id": 5,
        "name": "Court 1",
        "type": "Professional Basketball Court",
        "price_per_hr": 500
      }
      // ... only included when venue_id is specified
    ],
    "revenue_by_facility": [
      {
        "facility_id": 5,
        "facility_name": "Court 1",
        "facility_type": "Professional Basketball Court",
        "events": 15,
        "revenue": 45000.00
      }
      // ... only included when venue_id is specified but facility_id is not
    ]
  }
}
```

#### Response Fields

**`filters_applied`**: Shows the active filters used in the query
- `venue_id`: Selected venue ID (null if all venues)
- `facility_id`: Selected facility ID (null if all facilities)
- `period`: Date period type
- `date_range`: Actual date range used for filtering

**`summary`**: Aggregate statistics
- `revenue`: Total revenue (float)
- `events`: Total number of events (integer)
- `participants`: Total number of participants (integer)
- `average_participants`: Average participants per event (float)

**`weekly_revenue`**: Revenue breakdown by day
- `day`: Day abbreviation (Mon, Tue, etc.)
- `revenue`: Revenue for that day (float)
- `date`: Actual date (YYYY-MM-DD)

**`recent_events`**: List of recent events (up to 20)
- `id`: Event ID
- `venue_id`: Venue ID
- `name`: Event name
- `date`: Event date
- `facility_id`: Facility ID (if applicable)

**`venue_performance`**: Performance breakdown by venue
- `venue_id`: Venue ID
- `venue_name`: Venue name
- `address`: Venue address
- `events`: Number of events
- `participants`: Number of participants
- `earnings`: Total revenue

**`facilities`**: (Only when `venue_id` is specified)
- List of facilities for the selected venue
- Used to populate facility dropdown

**`revenue_by_facility`**: (Only when `venue_id` is specified but `facility_id` is not)
- Revenue breakdown by facility within the venue

#### Error Responses

```json
// Unauthenticated
{
  "status": "error",
  "message": "Unauthenticated"
}

// Unauthorized venue access
{
  "status": "error",
  "message": "Unauthorized venue access"
}

// Invalid parameters
{
  "status": "error",
  "message": "facility_id requires venue_id"
}

// Invalid date range
{
  "status": "error",
  "message": "Invalid date range parameters"
}
```

---

## Facilities List Endpoint

### GET `/api/venues/{venueId}/facilities/list`

Get a lightweight list of facilities for a specific venue. Used to populate facility dropdowns in the frontend.

#### URL Parameters

- `venueId` (required, integer): Venue ID

#### Example Request

```bash
GET /api/venues/12/facilities/list
```

#### Response Format

```json
{
  "status": "success",
  "data": {
    "venue_id": 12,
    "facilities": [
      {
        "id": 5,
        "name": "Court 1",
        "type": "Professional Basketball Court",
        "price_per_hr": 500,
        "capacity": 20,
        "covered": true
      },
      {
        "id": 6,
        "name": "Court 2",
        "type": "Standard Basketball Court",
        "price_per_hr": 400,
        "capacity": 16,
        "covered": true
      }
    ]
  }
}
```

#### Response Fields

- `venue_id`: The venue ID
- `facilities`: Array of facility objects
  - `id`: Facility ID
  - `name`: Facility name (falls back to `type` if name is null)
  - `type`: Facility type
  - `price_per_hr`: Price per hour
  - `capacity`: Maximum capacity
  - `covered`: Whether facility is covered

---

## Frontend Integration Examples

### React/Vue/Angular Example

```javascript
// Fetch analytics with filters
async function fetchAnalytics(filters) {
  const params = new URLSearchParams();
  
  if (filters.venueId) {
    params.append('venue_id', filters.venueId);
  }
  
  if (filters.facilityId) {
    params.append('facility_id', filters.facilityId);
  }
  
  if (filters.period) {
    params.append('period', filters.period);
  }
  
  if (filters.period === 'custom' && filters.startDate && filters.endDate) {
    params.append('start_date', filters.startDate);
    params.append('end_date', filters.endDate);
  }
  
  const response = await fetch(`/api/venues/analytics?${params}`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  
  return await response.json();
}

// Usage examples
const allVenuesAnalytics = await fetchAnalytics({ period: 'month' });

const venueAnalytics = await fetchAnalytics({ 
  venueId: 12, 
  period: 'this_week' 
});

const facilityAnalytics = await fetchAnalytics({ 
  venueId: 12, 
  facilityId: 5, 
  period: 'semi_annual' 
});

const customRangeAnalytics = await fetchAnalytics({ 
  venueId: 12, 
  period: 'custom',
  startDate: '2025-01-01',
  endDate: '2025-01-31'
});
```

### Fetch Facilities List

```javascript
// Fetch facilities for a venue
async function fetchFacilities(venueId) {
  const response = await fetch(`/api/venues/${venueId}/facilities/list`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  
  const data = await response.json();
  return data.data.facilities;
}

// Usage: Populate facility dropdown when venue is selected
async function handleVenueChange(venueId) {
  const facilities = await fetchFacilities(venueId);
  // Update facility dropdown options
  setFacilityOptions(facilities);
  
  // Optionally, update analytics with new venue filter
  const analytics = await fetchAnalytics({ venueId, period: 'month' });
  updateAnalyticsDisplay(analytics);
}
```

### Debouncing for Real-time Updates

To prevent the date picker from closing during filter updates, implement debouncing:

```javascript
import { debounce } from 'lodash'; // or implement your own debounce

// Debounced analytics fetch
const debouncedFetchAnalytics = debounce(async (filters) => {
  const analytics = await fetchAnalytics(filters);
  updateAnalyticsDisplay(analytics);
}, 500); // 500ms delay

// Usage in filter change handler
function handleFilterChange(newFilters) {
  // Update local state immediately (for UI responsiveness)
  setFilters(newFilters);
  
  // Debounce the API call (prevents calendar picker from closing)
  debouncedFetchAnalytics(newFilters);
}
```

### URL Query Parameter Integration

Store filter state in URL for shareable links:

```javascript
// Read filters from URL on page load
function getFiltersFromURL() {
  const params = new URLSearchParams(window.location.search);
  return {
    venueId: params.get('venue_id') || null,
    facilityId: params.get('facility_id') || null,
    period: params.get('period') || 'all',
    startDate: params.get('start_date') || null,
    endDate: params.get('end_date') || null,
  };
}

// Update URL when filters change
function updateURL(filters) {
  const params = new URLSearchParams();
  
  if (filters.venueId) params.set('venue_id', filters.venueId);
  if (filters.facilityId) params.set('facility_id', filters.facilityId);
  if (filters.period) params.set('period', filters.period);
  if (filters.startDate) params.set('start_date', filters.startDate);
  if (filters.endDate) params.set('end_date', filters.endDate);
  
  window.history.pushState({}, '', `?${params.toString()}`);
}

// Initialize from URL on page load
useEffect(() => {
  const filters = getFiltersFromURL();
  setFilters(filters);
  fetchAnalytics(filters);
}, []);
```

---

## Additional Metrics Suggestions

Based on the current schema, the following metrics can be derived for future enhancements:

### 1. Event Types Breakdown
Count events grouped by `event_type` field:
- Tournament events
- Casual events  
- Team vs team events

**Potential Query:**
```sql
SELECT event_type, COUNT(*) as count 
FROM events 
WHERE venue_id = ? AND cancelled_at IS NULL
GROUP BY event_type
```

### 2. Peak Hours Analysis
Analyze revenue/events by hour of day using `start_time` field:
- Identify busiest hours
- Optimize pricing for peak hours
- Schedule maintenance during off-peak

**Potential Query:**
```sql
SELECT HOUR(start_time) as hour, COUNT(*) as events, SUM(price) as revenue
FROM events
WHERE venue_id = ? AND cancelled_at IS NULL
GROUP BY HOUR(start_time)
ORDER BY hour
```

### 3. Sport Preferences
Most popular sports by venue/facility using `sport` field:
- Understand customer preferences
- Allocate facilities accordingly

**Potential Query:**
```sql
SELECT sport, COUNT(*) as event_count, COUNT(DISTINCT facility_id) as facilities_used
FROM events
WHERE venue_id = ? AND cancelled_at IS NULL
GROUP BY sport
ORDER BY event_count DESC
```

### 4. Average Booking Duration
Calculate average event duration using `start_time` and `end_time`:
- Per venue
- Per facility
- Per sport type

**Potential Query:**
```sql
SELECT 
  venue_id,
  AVG(TIMESTAMPDIFF(HOUR, start_time, end_time)) as avg_duration_hours
FROM events
WHERE cancelled_at IS NULL
GROUP BY venue_id
```

### 5. Revenue Trends
Month-over-month growth rates:
- Calculate percentage change month-to-month
- Identify growth/decline trends

### 6. Facility Utilization
Usage percentage per facility:
- Based on bookings vs available time slots
- Requires operating hours data from `venue_operating_hours` table

### 7. Average Revenue per Event
Simple calculation: `total_revenue / total_events`
- Track pricing effectiveness
- Compare across venues/facilities

### 8. Busiest Days of Week
Which days have most events/revenue:
- Using `date` field or `DAYNAME(created_at)`
- Helps with staffing and scheduling

**Potential Query:**
```sql
SELECT 
  DAYNAME(date) as day_name,
  DAYOFWEEK(date) as day_number,
  COUNT(*) as events,
  SUM(price) as revenue
FROM events
WHERE venue_id = ? AND cancelled_at IS NULL
GROUP BY DAYNAME(date), DAYOFWEEK(date)
ORDER BY day_number
```

### 9. Booking Status Breakdown
Count bookings by status from `bookings` table:
- Pending bookings
- Approved bookings
- Denied bookings
- Cancelled bookings

**Potential Query:**
```sql
SELECT status, COUNT(*) as count
FROM bookings
WHERE venue_id = ?
GROUP BY status
```

---

## Best Practices

### 1. Caching
- Cache facility lists when venue doesn't change frequently
- Cache analytics data for non-real-time dashboards
- Implement cache invalidation on data updates

### 2. Error Handling
Always handle error responses:
```javascript
try {
  const response = await fetchAnalytics(filters);
  if (response.status === 'error') {
    console.error('Error:', response.message);
    // Show user-friendly error message
  }
} catch (error) {
  console.error('Network error:', error);
}
```

### 3. Loading States
Implement loading indicators during API calls:
```javascript
const [loading, setLoading] = useState(false);

async function loadAnalytics() {
  setLoading(true);
  try {
    const data = await fetchAnalytics(filters);
    setAnalytics(data);
  } finally {
    setLoading(false);
  }
}
```

### 4. Data Validation
Validate dates and IDs before making requests:
```javascript
function validateFilters(filters) {
  if (filters.period === 'custom') {
    if (!filters.startDate || !filters.endDate) {
      return 'Custom period requires start and end dates';
    }
    if (new Date(filters.startDate) > new Date(filters.endDate)) {
      return 'Start date must be before end date';
    }
  }
  if (filters.facilityId && !filters.venueId) {
    return 'Facility filter requires venue selection';
  }
  return null;
}
```

---

## Status Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 401 | Unauthenticated |
| 403 | Forbidden (unauthorized venue access) |
| 404 | Not Found (venue/facility not found) |
| 422 | Validation Error (invalid parameters) |
| 500 | Server Error |

---

## Notes

- All date values use `YYYY-MM-DD` format
- Revenue calculations automatically detect available pricing columns (`price`, `total_fee`, `amount`, `fee`, `price_per_booking`) or fall back to `facilities.price_per_hr`
- Date filtering uses `events.date` if available, otherwise falls back to `events.created_at`
- Cancelled events (where `cancelled_at` is not null) are always excluded from analytics
- Weekly revenue adapts to custom date ranges: if range is 7 days or less, it shows those days; otherwise shows current week (Mon-Sun)

---

**Last Updated:** January 2025
**API Version:** 1.0

