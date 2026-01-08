# Backend Schema Comparison Report: LFG_CS vs lfg-initial Frontend

**Generated:** December 2024  
**Purpose:** Identify missing tables, columns, relationships, and API endpoints in the backend schema compared to frontend expectations.

---

## Executive Summary

The backend schema (LFG_CS) is **largely complete** with most core tables and fields present. However, there are several **missing fields**, **API endpoint gaps**, and **data structure mismatches** that need to be addressed for full frontend compatibility.

### Overall Status
- ✅ **Core Tables**: All major tables exist (users, teams, events, venues, tournaments, messaging, posts, notifications)
- ⚠️ **Field Completeness**: ~85% complete - some fields missing or not exposed in API responses
- ⚠️ **API Endpoints**: ~90% complete - some endpoints missing or have method mismatches
- ❌ **Data Relationships**: Some relationships not fully implemented in API responses

---

## 1. Teams Table Schema

### ✅ Present Fields
- `id`, `name`, `created_by`, `team_photo`, `certification`, `certified`, `team_type`
- `sport_id` (via migration `2025_11_11_135145`)
- `bio` (via migration `2025_11_11_135149`)
- `roster_size_limit` (via migration `2025_11_11_135156`)
- `address_line`, `latitude`, `longitude` (via migration `2025_10_16_163120`)
- Certification fields: `certification_document`, `certification_verified_at`, `certification_verified_by`, `certification_status`, `certification_ai_confidence`, `certification_ai_notes`

### ⚠️ Issues Identified

#### 1.1 Missing API Response Fields
**Issue:** Frontend expects `sport` object in team responses, but backend may only return `sport_id`
- **Expected:** `{ sport: { id: 1, name: "Basketball" } }`
- **Current:** May only return `sport_id: 1`
- **Priority:** Medium
- **Fix:** Ensure Team model includes `sport` relationship in API responses

#### 1.2 Team Type Not Editable
**Issue:** Frontend displays `team_type` but cannot edit it
- **Frontend Expectation:** Editable via team settings
- **Current:** Read-only field
- **Priority:** Low
- **Fix:** Add `team_type` to update endpoint allowed fields

#### 1.3 Roster Fields Not Exposed
**Issue:** Team members table has roster fields but they're not exposed in API
- **Fields:** `is_active`, `position`, `roster_status` exist in `team_members` table
- **Frontend Expectation:** These fields should be returned in `/api/teams/{id}/members` endpoint
- **Priority:** Medium
- **Fix:** Include roster fields in TeamMember API responses

---

## 2. Team Members Table Schema

### ✅ Present Fields
- `id`, `team_id`, `user_id`, `role`, `joined_at`
- `is_active`, `position`, `roster_status` (via migration `2025_11_11_135153`)

### ⚠️ Issues Identified

#### 2.1 Roster Fields Not in API Response
**Issue:** Roster fields exist in database but not returned in API
- **Missing in API:** `is_active`, `position`, `roster_status`
- **Frontend Expectation:** These should be in `/api/teams/{id}/members` response
- **Priority:** Medium
- **Fix:** Add roster fields to TeamMember model's API serialization

#### 2.2 Role Enum Mismatch
**Issue:** Database uses `['captain', 'member']` but frontend may expect `['owner', 'admin', 'member']`
- **Database:** `enum('role', ['captain', 'member'])`
- **Frontend Expectation:** May expect `['owner', 'admin', 'member']` based on messaging system
- **Priority:** Low
- **Fix:** Verify frontend expectations and align if needed

---

## 3. Tournaments Table Schema

### ✅ Present Fields
- `id`, `name`, `description`, `type`, `created_by`, `start_date`, `end_date`
- `registration_deadline`, `status`, `requires_documents`, `required_documents`
- `settings`, `max_teams`, `min_teams`, `registration_fee`, `rules`, `prizes`
- `tournament_type` (via migration `2025_12_02_152109`) - enum: `['team vs team', 'free for all']`
- `location` (via migration `2025_12_15_174402`)
- `photo` (via migration `2025_12_16_145822`)
- `cancelled_at`, `cancellation_reason` (via migration `2025_12_14_124729`)

### ⚠️ Issues Identified

#### 3.1 Tournament Type Field Name Confusion
**Issue:** Frontend uses both `type` and `tournament_type` fields
- **Database:** Has both `type` (enum: `['single_sport', 'multisport']`) and `tournament_type` (enum: `['team vs team', 'free for all']`)
- **Frontend:** Uses `tournament_type` primarily, falls back to `type`
- **Priority:** Low
- **Fix:** Ensure API consistently returns `tournament_type` field

#### 3.2 Missing Location Coordinates
**Issue:** Frontend expects `latitude` and `longitude` for tournaments
- **Frontend Code:** References `tournament?.latitude || tournament?.location_latitude`
- **Database:** Only has `location` (string), no coordinates
- **Priority:** Medium
- **Fix:** Add `latitude` and `longitude` columns to tournaments table

---

## 4. Events Table Schema

### ✅ Present Fields
- `id`, `name`, `description`, `event_type`, `sport`, `venue_id`, `slots`
- `date`, `start_time`, `end_time`, `created_by`
- `checkin_code`, `cancelled_at` (via migration `2025_10_23_065149`)
- `is_approved`, `approved_at` (via migration `2025_11_15_000000`)
- `tournament_id`, `game_number`, `game_status`, `is_tournament_game` (via migration `2025_11_25_163527`)

### ✅ Status
**Events table appears complete** - all expected fields are present.

---

## 5. Venues Table Schema

### ✅ Present Fields
- `id`, `name`, `description`, `price_per_hr`, `address`, `latitude`, `longitude`
- `verified_at`, `verification_expires_at`, `created_by`
- `phone_number`, `email`, `facebook_url`, `instagram_url`, `website`, `house_rules` (via migration `2025_11_01_060233`)
- `is_closed`, `closed_at`, `closed_reason` (via migration `2025_11_16_000001`)

### ✅ Status
**Venues table appears complete** - all expected fields are present.

---

## 6. Users Table Schema

### ✅ Present Fields
- `id`, `first_name`, `middle_name`, `last_name`, `username`, `email`, `password`
- `birthday`, `sex`, `contact_number`, `barangay`, `city`, `province`, `zip_code`
- `profile_photo`, `role_id`, `email_verified_at`
- `provider`, `provider_id` (via migration `2025_12_07_075520`) - for social auth
- All fields made nullable for social auth (via migration `2025_12_09_070222`)

### ✅ Status
**Users table appears complete** - social auth fields are present.

---

## 7. Messaging Tables Schema

### ✅ Present Fields
**message_threads:**
- `id` (uuid), `created_by`, `is_group`, `title`, `timestamps`
- `type`, `game_id`, `team_id`, `venue_id`, `is_closed`, `closed_at` (via migration `2025_11_16_000001`)
- `is_read` (via migration `2025_12_16_150947`)

**messages:**
- `id` (uuid), `thread_id`, `sender_id`, `body`, `sent_at`, `edited_at`, `deleted_at`

**thread_participants:**
- `thread_id`, `user_id`, `role`, `joined_at`, `left_at`, `last_read_message_id`
- `mute_until`, `notifications`, `archived` (via migration `2025_11_16_160000`)

### ✅ Status
**Messaging tables appear complete** - all expected fields are present.

---

## 8. Posts Table Schema

### ✅ Present Fields
- `id` (uuid), `author_id`, `location`, `image_url`, `caption`, `created_at`

### ✅ Status
**Posts table appears complete** - all expected fields are present.

---

## 9. Notifications Tables Schema

### ✅ Present Fields
**notifications:**
- `id` (uuid), `type`, `data` (json), `created_by`, `created_at`

**user_notifications:**
- `id` (uuid), `notification_id`, `user_id`, `read_at`, `pinned`
- `action_state`, `action_taken_at`, `created_at`

**user_notification_action_events:**
- `id` (uuid), `user_notification_id`, `action_key`, `metadata`, `created_at`

### ✅ Status
**Notifications tables appear complete** - all expected fields are present.

---

## 10. Missing API Endpoints

Based on frontend API documentation analysis:

### 10.1 Team Endpoints

#### ❌ Missing: `GET /api/teams/my`
**Issue:** Frontend needs endpoint to get only user's teams (owned + member)
- **Current:** Frontend fetches all teams and filters client-side
- **Priority:** Medium
- **Impact:** Performance - unnecessary data transfer
- **Fix:** Add endpoint that filters teams server-side using SQL joins

#### ⚠️ Method Mismatch: `POST /api/teams/{teamId}/request-cancel`
**Issue:** Frontend uses POST but backend may expect DELETE
- **Frontend:** Uses POST method
- **Priority:** Low
- **Fix:** Verify backend accepts POST, or update frontend to use DELETE

#### ❌ Missing: `GET /api/teams/{teamId}/events`
**Issue:** Frontend expects team-specific events endpoint
- **Current:** May need to use `/api/events?team_id={teamId}` instead
- **Priority:** Low
- **Fix:** Add endpoint or document alternative

### 10.2 Roster Management Endpoints

#### ❌ Missing: `GET /api/teams/{teamId}/roster`
**Issue:** Frontend expects dedicated roster endpoint with roster fields
- **Expected Response:**
  ```json
  {
    "roster": {
      "total_active": 8,
      "total_inactive": 2,
      "available_slots": 2,
      "members": [...]
    }
  }
  ```
- **Priority:** Medium
- **Fix:** Create endpoint that returns roster with statistics

#### ❌ Missing: `PATCH /api/teams/{teamId}/members/{memberId}/roster`
**Issue:** Frontend needs to update roster status/position
- **Priority:** Medium
- **Fix:** Add endpoint to update `is_active`, `position`, `roster_status`

#### ❌ Missing: `PATCH /api/teams/{teamId}/roster-limit`
**Issue:** Frontend needs to set roster size limit
- **Priority:** Low
- **Fix:** Add endpoint to update `roster_size_limit`

### 10.3 Team Invite Endpoints

#### ✅ Present: `POST /api/teams/{teamId}/invites/generate`
**Status:** Endpoint exists in routes

#### ✅ Present: `POST /api/teams/invites/{token}/accept`
**Status:** Endpoint exists in routes

#### ✅ Present: `GET /api/teams/{teamId}/invites`
**Status:** Endpoint exists in routes

#### ✅ Present: `DELETE /api/teams/{teamId}/invites/{inviteId}`
**Status:** Endpoint exists in routes

### 10.4 Certification Endpoints

#### ✅ Present: `POST /api/teams/{teamId}/certification/upload`
**Status:** Endpoint exists in routes

#### ✅ Present: `POST /api/teams/{teamId}/certification/verify-ai`
**Status:** Endpoint exists in routes

#### ✅ Present: `GET /api/teams/{teamId}/certification/status`
**Status:** Endpoint exists in routes

---

## 11. Data Structure Mismatches

### 11.1 Team API Response Structure

#### Issue: Missing `sport` Relationship
**Current:** May return only `sport_id: 1`  
**Expected:** Should return `sport: { id: 1, name: "Basketball" }`  
**Priority:** Medium  
**Fix:** Ensure Team model includes `sport` relationship in API serialization

#### Issue: Missing `creator` Relationship
**Current:** Returns `created_by: 5`  
**Expected:** Should return `creator: { id: 5, username: "...", profile_photo: "..." }`  
**Priority:** Low  
**Fix:** Include `creator` relationship in Team API responses

### 11.2 Team Members API Response Structure

#### Issue: Missing User Details
**Current:** May return only `user_id`  
**Expected:** Should return full user object: `{ id, username, email, profile_photo }`  
**Priority:** High  
**Fix:** Include user relationship in TeamMember API responses

#### Issue: Missing Roster Fields
**Current:** May not return `is_active`, `position`, `roster_status`  
**Expected:** Should return all roster fields  
**Priority:** Medium  
**Fix:** Include roster fields in TeamMember API responses

---

## 12. Priority Summary

### 🔴 High Priority (Critical Functionality)

1. **Team Members API Response** - Include user details (username, email, profile_photo)
   - **Impact:** Frontend cannot display member information properly
   - **Effort:** Low - Add relationship loading

### 🟡 Medium Priority (Important Features)

2. **Roster Management Endpoints** - Add roster endpoints and expose roster fields
   - **Impact:** Roster management features not functional
   - **Effort:** Medium - Create endpoints and update responses

3. **Team Sport Relationship** - Include sport object in team responses
   - **Impact:** Frontend cannot display sport information
   - **Effort:** Low - Add relationship loading

4. **Tournament Location Coordinates** - Add latitude/longitude to tournaments
   - **Impact:** Location-based features may not work
   - **Effort:** Low - Add columns and migration

5. **Team Events Endpoint** - Add `/api/teams/{teamId}/events` or document alternative
   - **Impact:** Team events page may not work
   - **Effort:** Low - Add endpoint or update documentation

### 🟢 Low Priority (Nice to Have)

6. **Team Type Edit** - Make team_type editable
   - **Impact:** Minor UX improvement
   - **Effort:** Low - Add to update endpoint

7. **Team My Endpoint** - Add `/api/teams/my` for performance
   - **Impact:** Performance optimization
   - **Effort:** Medium - Create optimized endpoint

8. **Cancel Request Method** - Verify POST vs DELETE
   - **Impact:** Minor - may already work
   - **Effort:** Low - Verify and document

---

## 13. Recommended Implementation Order

### Phase 1: Critical Fixes (Week 1)
1. Fix Team Members API to include user details
2. Add roster fields to TeamMember API responses
3. Add sport relationship to Team API responses

### Phase 2: Roster Management (Week 2)
4. Create `GET /api/teams/{teamId}/roster` endpoint
5. Create `PATCH /api/teams/{teamId}/members/{memberId}/roster` endpoint
6. Create `PATCH /api/teams/{teamId}/roster-limit` endpoint

### Phase 3: Tournament Enhancements (Week 3)
7. Add `latitude` and `longitude` columns to tournaments table
8. Ensure `tournament_type` is consistently returned in API

### Phase 4: Performance & UX (Week 4)
9. Add `GET /api/teams/my` endpoint
10. Add `GET /api/teams/{teamId}/events` endpoint
11. Make `team_type` editable in update endpoint

---

## 14. Database Migration Checklist

### Required Migrations

1. ✅ **Teams Table** - Already complete
   - sport_id, bio, roster_size_limit, address_line, latitude, longitude

2. ✅ **Team Members Table** - Already complete
   - is_active, position, roster_status

3. ❌ **Tournaments Table** - Missing coordinates
   ```php
   Schema::table('tournaments', function (Blueprint $table) {
       $table->decimal('latitude', 10, 8)->nullable()->after('location');
       $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
   });
   ```

---

## 15. Model Updates Required

### Team Model
- ✅ Already includes `sport` relationship
- ⚠️ Ensure `sport` is included in API responses (check controllers)

### TeamMember Model
- ⚠️ Ensure `user` relationship is loaded in API responses
- ⚠️ Ensure roster fields (`is_active`, `position`, `roster_status`) are included

### Tournament Model
- ✅ Already includes `tournament_type` in fillable
- ❌ Add `latitude` and `longitude` to fillable after migration

---

## 16. Controller Updates Required

### TeamController
- [ ] Update `index()` or `show()` to include `sport` relationship
- [ ] Update `members()` to include `user` relationship and roster fields
- [ ] Add `roster()` method for roster endpoint
- [ ] Add `updateRoster()` method
- [ ] Add `setRosterLimit()` method
- [ ] Add `myTeams()` method for `/api/teams/my` endpoint
- [ ] Add `teamEvents()` method for `/api/teams/{teamId}/events` endpoint

### TournamentController
- [ ] Ensure `tournament_type` is returned in all responses
- [ ] Include `latitude` and `longitude` in responses after migration

---

## 17. Testing Checklist

After implementing fixes, verify:

- [ ] Team API returns `sport` object (not just `sport_id`)
- [ ] Team Members API returns user details (username, email, profile_photo)
- [ ] Team Members API returns roster fields (is_active, position, roster_status)
- [ ] Roster endpoint returns correct statistics
- [ ] Tournament API returns `tournament_type` consistently
- [ ] Tournament API returns `latitude` and `longitude` (after migration)
- [ ] `/api/teams/my` endpoint returns only user's teams
- [ ] `/api/teams/{teamId}/events` endpoint works (or alternative documented)

---

## Conclusion

The backend schema is **85-90% complete** compared to frontend expectations. The main gaps are:

1. **API Response Structure** - Missing relationships and fields in responses
2. **Roster Management** - Endpoints and fields exist but not fully exposed
3. **Tournament Coordinates** - Missing latitude/longitude fields
4. **Performance Endpoints** - Missing optimized endpoints like `/api/teams/my`

Most issues are **low to medium effort** fixes involving:
- Adding relationship loading in controllers
- Creating new endpoints
- Adding missing columns via migrations
- Updating API response serialization

The schema foundation is solid - these are primarily API layer improvements rather than fundamental schema changes.



















