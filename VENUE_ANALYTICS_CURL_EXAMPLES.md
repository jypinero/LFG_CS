# Venue Analytics API - Curl Examples

## Verified Routes

All routes require JWT authentication via Bearer token in the Authorization header.

### Base URL
```
http://127.0.0.1:8000/api
```

---

## Authentication

All endpoints require a JWT token. Get your token by logging in:

```bash
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "password123"
  }'
```

Response:
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "token_type": "bearer",
  "expires_in": 3600
}
```

---

## Analytics Endpoints

### 1. Get Analytics for All Venues (All-Time)

**Bash/Unix:**
```bash
curl -X GET "http://127.0.0.1:8000/api/venues/analytics" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**PowerShell:**
```powershell
$token = "YOUR_TOKEN_HERE"
$headers = @{ "Authorization" = "Bearer $token"; "Accept" = "application/json" }
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/venues/analytics" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

**Response:**
```json
{
  "status": "success",
  "analytics": {
    "filters_applied": {
      "venue_id": null,
      "facility_id": null,
      "period": "all",
      "date_range": {
        "period": "all",
        "start": null,
        "end": null
      }
    },
    "summary": {
      "revenue": 2050,
      "events": 14,
      "participants": 28,
      "average_participants": 2
    },
    "weekly_revenue": [...],
    "recent_events": [...],
    "venue_performance": [...]
  }
}
```

---

### 2. Get Analytics for All Venues (This Month)

**Bash/Unix:**
```bash
curl -X GET "http://127.0.0.1:8000/api/venues/analytics?period=month" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**PowerShell:**
```powershell
$token = "YOUR_TOKEN_HERE"
$headers = @{ "Authorization" = "Bearer $token"; "Accept" = "application/json" }
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/venues/analytics?period=month" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

---

### 3. Get Analytics for Specific Venue (Current Month)

**Bash/Unix:**
```bash
curl -X GET "http://127.0.0.1:8000/api/venues/analytics?venue_id=1&period=month" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**PowerShell:**
```powershell
$token = "YOUR_TOKEN_HERE"
$headers = @{ "Authorization" = "Bearer $token"; "Accept" = "application/json" }
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/venues/analytics?venue_id=1&period=month" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

**Response includes:**
- `facilities` array - List of facilities for the venue
- `revenue_by_facility` array - Revenue breakdown by facility

---

### 4. Get Analytics for Specific Venue (This Week)

**Bash/Unix:**
```bash
curl -X GET "http://127.0.0.1:8000/api/venues/analytics?venue_id=12&period=this_week" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**PowerShell:**
```powershell
$token = "YOUR_TOKEN_HERE"
$headers = @{ "Authorization" = "Bearer $token"; "Accept" = "application/json" }
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/venues/analytics?venue_id=12&period=this_week" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

---

### 5. Get Analytics for Specific Facility (Last 6 Months)

**Bash/Unix:**
```bash
curl -X GET "http://127.0.0.1:8000/api/venues/analytics?venue_id=1&facility_id=1&period=semi_annual" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**PowerShell:**
```powershell
$token = "YOUR_TOKEN_HERE"
$headers = @{ "Authorization" = "Bearer $token"; "Accept" = "application/json" }
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/venues/analytics?venue_id=1&facility_id=1&period=semi_annual" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

---

### 6. Get Analytics with Custom Date Range

**Bash/Unix:**
```bash
curl -X GET "http://127.0.0.1:8000/api/venues/analytics?venue_id=1&period=custom&start_date=2025-01-01&end_date=2025-01-31" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**PowerShell:**
```powershell
$token = "YOUR_TOKEN_HERE"
$headers = @{ "Authorization" = "Bearer $token"; "Accept" = "application/json" }
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/venues/analytics?venue_id=1&period=custom&start_date=2025-01-01&end_date=2025-01-31" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

---

### 7. Get Analytics (Last 12 Months)

**Bash/Unix:**
```bash
curl -X GET "http://127.0.0.1:8000/api/venues/analytics?venue_id=1&period=annual" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**PowerShell:**
```powershell
$token = "YOUR_TOKEN_HERE"
$headers = @{ "Authorization" = "Bearer $token"; "Accept" = "application/json" }
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/venues/analytics?venue_id=1&period=annual" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

---

## Supporting Endpoints

### 8. Get User's Owned Venues (for Dropdown)

**Bash/Unix:**
```bash
curl -X GET "http://127.0.0.1:8000/api/venues/owner" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**PowerShell:**
```powershell
$token = "YOUR_TOKEN_HERE"
$headers = @{ "Authorization" = "Bearer $token"; "Accept" = "application/json" }
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/venues/owner" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "venues": [
      {
        "id": 1,
        "name": "Olongapo City Sports Complex",
        "description": "Main sports complex for various events and tournaments",
        "address": "Rizal Ave., East Tapinac, Olongapo City",
        "latitude": "14.83330000",
        "longitude": "120.28330000",
        "photos": [...],
        "facilities": [...]
      }
    ]
  }
}
```

---

### 9. Get Facilities List for a Venue

**Bash/Unix:**
```bash
curl -X GET "http://127.0.0.1:8000/api/venues/1/facilities/list" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**PowerShell:**
```powershell
$token = "YOUR_TOKEN_HERE"
$headers = @{ "Authorization" = "Bearer $token"; "Accept" = "application/json" }
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/venues/1/facilities/list" -Method Get -Headers $headers | ConvertTo-Json -Depth 5
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "venue_id": "1",
    "facilities": [
      {
        "id": 1,
        "name": "volleyball court",
        "type": "volleyball court",
        "price_per_hr": "250.00",
        "capacity": null,
        "covered": false
      }
    ]
  }
}
```

---

### 10. Get Venue Details

**Bash/Unix:**
```bash
curl -X GET "http://127.0.0.1:8000/api/venues/show/1" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

**PowerShell:**
```powershell
$token = "YOUR_TOKEN_HERE"
$headers = @{ "Authorization" = "Bearer $token"; "Accept" = "application/json" }
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/venues/show/1" -Method Get -Headers $headers | ConvertTo-Json -Depth 10
```

---

## Period Options

| Period Value | Description |
|--------------|-------------|
| `all` | All-time data (default) |
| `this_week` | Current week (Monday to Sunday) |
| `month` | Current calendar month |
| `semi_annual` | Last 6 months from today |
| `annual` | Last 12 months from today |
| `custom` | Custom range (requires `start_date` and `end_date`) |

---

## Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `venue_id` | integer | No | Filter by specific venue ID |
| `facility_id` | integer | No | Filter by specific facility ID (requires `venue_id`) |
| `period` | string | No | Date range period (default: `all`) |
| `start_date` | date (YYYY-MM-DD) | Yes* | Start date for custom period |
| `end_date` | date (YYYY-MM-DD) | Yes* | End date for custom period |

\* Required only when `period=custom`

---

## Response Structure

### Analytics Response
```json
{
  "status": "success",
  "analytics": {
    "filters_applied": {
      "venue_id": 1,
      "facility_id": null,
      "period": "month",
      "date_range": {
        "period": "month",
        "start": "2025-11-01",
        "end": "2025-11-30"
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
        "date": "2025-11-03"
      }
    ],
    "recent_events": [
      {
        "id": 123,
        "venue_id": 1,
        "name": "Basketball Match",
        "date": "2025-11-15",
        "facility_id": 1
      }
    ],
    "venue_performance": [
      {
        "venue_id": 1,
        "venue_name": "Olongapo City Sports Complex",
        "address": "Rizal Ave., East Tapinac, Olongapo City",
        "events": 25,
        "participants": 125,
        "earnings": 65000.00
      }
    ],
    "facilities": [
      {
        "id": 1,
        "name": "Court 1",
        "type": "Professional Basketball Court",
        "price_per_hr": 500
      }
    ],
    "revenue_by_facility": [
      {
        "facility_id": 1,
        "facility_name": "Court 1",
        "facility_type": "Professional Basketball Court",
        "events": 15,
        "revenue": 45000.00
      }
    ]
  }
}
```

---

## Testing Script (PowerShell)

Save as `test-analytics.ps1`:

```powershell
# Configuration
$API_URL = "http://127.0.0.1:8000/api"
$TOKEN = "YOUR_TOKEN_HERE"

# Headers
$headers = @{
    "Authorization" = "Bearer $TOKEN"
    "Accept" = "application/json"
}

Write-Host "=== Testing Analytics Endpoints ===" -ForegroundColor Green

# Test 1: All venues (all-time)
Write-Host "`n1. All venues (all-time):" -ForegroundColor Yellow
$response = Invoke-RestMethod -Uri "$API_URL/venues/analytics" -Method Get -Headers $headers
Write-Host "Revenue: $($response.analytics.summary.revenue)"
Write-Host "Events: $($response.analytics.summary.events)"
Write-Host "Participants: $($response.analytics.summary.participants)"

# Test 2: Specific venue (this month)
Write-Host "`n2. Venue 1 (this month):" -ForegroundColor Yellow
$response = Invoke-RestMethod -Uri "$API_URL/venues/analytics?venue_id=1&period=month" -Method Get -Headers $headers
Write-Host "Revenue: $($response.analytics.summary.revenue)"
Write-Host "Events: $($response.analytics.summary.events)"

# Test 3: Get owned venues
Write-Host "`n3. Get owned venues:" -ForegroundColor Yellow
$response = Invoke-RestMethod -Uri "$API_URL/venues/owner" -Method Get -Headers $headers
Write-Host "Total venues: $($response.data.venues.Count)"
foreach ($venue in $response.data.venues) {
    Write-Host "  - $($venue.name) (ID: $($venue.id))"
}

# Test 4: Get facilities
Write-Host "`n4. Get facilities for venue 1:" -ForegroundColor Yellow
$response = Invoke-RestMethod -Uri "$API_URL/venues/1/facilities/list" -Method Get -Headers $headers
Write-Host "Total facilities: $($response.data.facilities.Count)"
foreach ($facility in $response.data.facilities) {
    Write-Host "  - $($facility.name) - $($facility.price_per_hr) PHP/hr"
}

Write-Host "`n=== Tests Complete ===" -ForegroundColor Green
```

Run with:
```powershell
.\test-analytics.ps1
```

---

## Testing Script (Bash)

Save as `test-analytics.sh`:

```bash
#!/bin/bash

# Configuration
API_URL="http://127.0.0.1:8000/api"
TOKEN="YOUR_TOKEN_HERE"

echo "=== Testing Analytics Endpoints ==="

# Test 1: All venues (all-time)
echo -e "\n1. All venues (all-time):"
curl -s -X GET "$API_URL/venues/analytics" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq '.analytics.summary'

# Test 2: Specific venue (this month)
echo -e "\n2. Venue 1 (this month):"
curl -s -X GET "$API_URL/venues/analytics?venue_id=1&period=month" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq '.analytics.summary'

# Test 3: Get owned venues
echo -e "\n3. Get owned venues:"
curl -s -X GET "$API_URL/venues/owner" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq '.data.venues[] | {id, name}'

# Test 4: Get facilities
echo -e "\n4. Get facilities for venue 1:"
curl -s -X GET "$API_URL/venues/1/facilities/list" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq '.data.facilities[] | {id, name, price_per_hr}'

echo -e "\n=== Tests Complete ==="
```

Make executable and run:
```bash
chmod +x test-analytics.sh
./test-analytics.sh
```

---

## Verified Routes Summary

| Route | Method | Status | Description |
|-------|--------|--------|-------------|
| `/api/venues/analytics` | GET | ✅ Verified | Get analytics for all venues |
| `/api/venues/analytics?venue_id={id}` | GET | ✅ Verified | Get analytics for specific venue |
| `/api/venues/analytics?venue_id={id}&facility_id={id}` | GET | ✅ Verified | Get analytics for specific facility |
| `/api/venues/analytics?period={period}` | GET | ✅ Verified | Get analytics with period filter |
| `/api/venues/owner` | GET | ✅ Verified | Get user's owned venues |
| `/api/venues/{venueId}/facilities/list` | GET | ✅ Verified | Get facilities for venue |
| `/api/venues/show/{venueId}` | GET | ✅ Verified | Get venue details |

---

## Notes

- All endpoints require JWT authentication
- Token expires in 3600 seconds (1 hour)
- Base URL may vary depending on your environment
- Response format is consistent across all endpoints
- Weekly revenue adapts to custom date ranges (shows actual days if 7 days or less)
- Facilities list is only included when `venue_id` is specified
- Revenue by facility is only included when `venue_id` is specified but `facility_id` is not

---

**Last Updated:** November 2025
**API Version:** 1.0



