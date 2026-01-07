# Backend Endpoints Status Check

## ✅ CONFIRMED - These Endpoints EXIST

### Analytics
- **GET** `/api/tournaments/{tournamentId}/analytics`
  - Route: Line 353 in `routes/api.php`
  - Controller: `AnalyticsController::getAnalytics()`
  - Returns: Analytics data calculated by `AnalyticsService`

### Participant Management
- **POST** `/api/tournaments/{tournamentid}/participants/{participantid}/approve`
  - Route: Line 312 in `routes/api.php`
  - Controller: `TournamentController::approveParticipant()`
  - Functionality: Approves a single participant, sends notification, updates analytics

- **POST** `/api/tournaments/{tournamentid}/participants/{participantid}/reject`
  - Route: Line 313 in `routes/api.php`
  - Controller: `TournamentController::rejectParticipant()`

- **POST** `/api/tournaments/{tournamentid}/participants/{participantid}/ban`
  - Route: Line 314 in `routes/api.php`
  - Controller: `TournamentController::banParticipant()`

## ❌ MISSING - These Endpoints DO NOT EXIST

### Activity Log
- **GET** `/api/tournaments/{id}/activity-log` ❌
  - **Note**: There IS an audit log system (`AuditLog` model, `AuditLogger` service)
  - **But**: Only admin endpoint exists: `GET /admin/audit-logs` (line 457)
  - **Missing**: Tournament-specific activity log endpoint for organizers

### Bulk Approve Participants
- **POST** `/api/tournaments/{id}/participants/bulk-approve` ❌
  - **Note**: There IS `bulkImportParticipants` endpoint (line 396)
  - **But**: This is for importing participants from CSV/JSON, NOT for bulk approval
  - **Missing**: Endpoint to approve multiple participants at once

### Spectator Count
- **GET** `/api/tournaments/{id}/spectator-count` ❌
  - **Missing**: No endpoint to get live spectator/viewer count for matches

### Tournament Settings Update
- **PUT/PATCH** `/api/tournaments/{id}/settings` ❌ (or similar)
  - **Note**: There IS `update()` method for tournaments (line 348)
  - **But**: This updates the entire tournament, not just settings
  - **Missing**: Dedicated endpoint for bulk settings update

## 📋 Summary

### What You CAN Build Now (Using Existing Endpoints):
1. ✅ **Analytics Dashboard** - Use `/api/tournaments/{id}/analytics`
2. ✅ **Participant Management** - Use individual approve/reject/ban endpoints
3. ✅ **Live Control Center** - Use `getLiveMatches()` + `updateScore()` + `startMatch()` + `endMatch()`
4. ✅ **Match Management** - Use `getSchedule()` + `getMatches()` + `getMatchDetails()`
5. ✅ **Brackets** - Use `getMatches()` + `generateBrackets()` + `advanceBracket()`
6. ✅ **Standings** - Use `getStandings()` + `getLeaderboard()`

### What You CANNOT Build Yet (Missing Endpoints):
1. ❌ **Activity Feed/Log** - Need tournament-specific activity log endpoint
2. ❌ **Bulk Approve Participants** - Need bulk approval endpoint
3. ❌ **Live Spectator Count** - Need spectator tracking endpoint
4. ❌ **Settings Panel** - Can use existing `update()` but might want dedicated settings endpoint

## 🔧 Recommendations

### Option 1: Build Without Missing Features (SAFE)
Build the organizer UI using only confirmed endpoints. Skip:
- Activity feed (use notifications instead)
- Bulk approve (use individual approve in a loop)
- Spectator count (skip this feature)
- Settings panel (use existing update endpoint)

### Option 2: Request Missing Endpoints
If you need these features, request implementation of:
1. `GET /api/tournaments/{id}/activity-log` - Filter audit logs by tournament
2. `POST /api/tournaments/{id}/participants/bulk-approve` - Approve multiple participants
3. `GET /api/tournaments/{id}/spectator-count` - Get live viewer count (if tracking is implemented)










