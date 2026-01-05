# Tournament Simplified Flow - Implementation Guide

This document provides a simplified, step-by-step approach to running tournaments. The flow is designed to be clean and intuitive, replacing the cluttered management interface.

## Overview

The tournament lifecycle follows these clear steps:
1. **Draft** → Create tournament
2. **Registration** → Users register, organizer approves
3. **Setup Brackets** → Generate brackets/matches
4. **Running** → Manage matches, enter scores
5. **Completed** → View final results

## New Endpoint: Tournament Flow State

### `GET /api/tournaments/{id}/flow`

Returns the current state of the tournament and what actions are available.

**Response Structure:**
```json
{
  "status": "success",
  "tournament_id": 1,
  "tournament_name": "Summer Basketball Championship",
  "current_step": "registration", // draft | registration | setup_brackets | running | completed
  "tournament_status": "open_registration",
  "is_authorized": true,
  "summary": {
    "status": "open_registration",
    "participants_approved": 8,
    "participants_pending": 2,
    "matches_total": 0,
    "matches_completed": 0,
    "matches_remaining": 0,
    "has_brackets": false,
    "events_count": 0
  },
  "available_actions": [
    {
      "action": "approve_participants",
      "label": "Approve Participants",
      "method": "GET",
      "endpoint": "/api/tournaments/1/participants",
      "description": "Review and approve 2 pending participant(s)"
    },
    {
      "action": "close_registration",
      "label": "Close Registration",
      "method": "POST",
      "endpoint": "/api/tournaments/1/close-registration",
      "description": "Close registration and prepare brackets"
    }
  ],
  "progress": {
    "step": "registration",
    "status": "open_registration",
    "completion_percentage": 0
  }
}
```

## UI Screen Structure Based on Flow State

### Screen 1: Draft State
**Current Step:** `draft`

**What to Show:**
- Tournament details
- "Open Registration" button (if authorized)
- "Edit Tournament" button (if authorized)

**Actions:**
- `open_registration` → POST `/api/tournaments/{id}/open-registration`

---

### Screen 2: Registration State
**Current Step:** `registration`

**What to Show:**
- Tournament info
- Participants count (approved / pending)
- "Approve Participants" button (shows pending count)
- "Close Registration" button (if authorized)

**Actions:**
- `approve_participants` → Navigate to participants page
- `close_registration` → POST `/api/tournaments/{id}/close-registration`

**Sub-screen: Participants Management**
- GET `/api/tournaments/{id}/participants`
- Approve/Reject buttons for each participant
- Bulk approve option

---

### Screen 3: Setup Brackets State
**Current Step:** `setup_brackets`

**What to Show:**
- Tournament info
- Participants count
- "Generate Brackets" button (if no brackets yet)
- "Start Tournament" button

**Actions:**
- `generate_brackets` → POST `/api/tournaments/{id}/events/{eventId}/generate-brackets`
  - Requires an event - you may need to create one first or use the first event
  - Payload: `{ "bracket_type": "single_elimination", "seed_by": "random" }`
- `start_tournament` → POST `/api/tournaments/{id}/start`

---

### Screen 4: Running State
**Current Step:** `running`

**What to Show:**
- Tournament status badge
- Progress bar (completion_percentage)
- Quick stats: Matches completed / total
- Action cards:
  - "Manage Matches" → Shows all matches
  - "Live Matches" → Shows in-progress matches
  - "View Standings" → Shows standings table
  - "Complete Tournament" (if all matches done)

**Sub-screens:**

#### 4a. Matches View
- GET `/api/tournaments/{id}/matches`
- Grouped by round
- Each match shows: teams, status, date/time
- Click match → Go to match detail

#### 4b. Match Detail / Scoring
- GET `/api/tournaments/{id}/matches/{matchId}`
- Match info (teams, venue, date/time)
- Score display (large, prominent)
- Action buttons:
  - "Start Match" (if scheduled)
  - Score input fields
  - "Update Score" button
  - "End Match" button (if in progress)

**Scoring Flow:**
1. Click "Start Match" → POST `/api/tournaments/{id}/matches/{matchId}/start`
2. Enter scores → POST `/api/tournaments/{id}/matches/{matchId}/score`
   ```json
   {
     "score_home": 85,
     "score_away": 72
   }
   ```
3. Click "End Match" → POST `/api/tournaments/{id}/matches/{matchId}/end`
   ```json
   {
     "score_home": 85,
     "score_away": 72,
     "winner_team_id": 5,
     "auto_advance": true
   }
   ```

#### 4c. Standings View
- GET `/api/tournaments/{id}/standings`
- Sortable table
- Shows: rank, team name, wins, losses, points, win rate

---

### Screen 5: Completed State
**Current Step:** `completed`

**What to Show:**
- Tournament completion message
- Final standings
- Winner announcement
- "View Final Results" button

**Actions:**
- `view_standings` → GET `/api/tournaments/{id}/standings`

---

## Complete Route Reference for Simplified Flow

### Tournament Lifecycle
```
1. POST /api/tournaments/create
   → Create tournament (status: draft)

2. POST /api/tournaments/{id}/open-registration
   → Open registration (status: open_registration)

3. GET /api/tournaments/{id}/participants
   → View participants

4. POST /api/tournaments/{id}/participants/{participantId}/approve
   → Approve participant

5. POST /api/tournaments/{id}/close-registration
   → Close registration (status: registration_closed)

6. POST /api/tournaments/{id}/events/{eventId}/generate-brackets
   → Generate brackets (creates matches)

7. POST /api/tournaments/{id}/start
   → Start tournament (status: ongoing)

8. POST /api/tournaments/{id}/matches/{matchId}/start
   → Start a match

9. POST /api/tournaments/{id}/matches/{matchId}/score
   → Update match score

10. POST /api/tournaments/{id}/matches/{matchId}/end
    → End match (auto-advances winner if enabled)

11. POST /api/tournaments/{id}/complete
    → Complete tournament (status: completed)
```

### Viewing Routes
```
- GET /api/tournaments/{id}/flow
  → Get current state and available actions

- GET /api/tournaments/{id}/matches
  → Get all matches (grouped by round)

- GET /api/tournaments/{id}/matches/live
  → Get live/ongoing matches

- GET /api/tournaments/{id}/matches/{matchId}
  → Get match details

- GET /api/tournaments/{id}/standings
  → Get standings table

- GET /api/tournaments/{id}/schedule
  → Get schedule (upcoming/past matches)
```

---

## Recommended UI Structure

### Main Tournament Management Page
```
/tournaments/{id}/manage

Layout:
┌─────────────────────────────────────┐
│ Tournament Name                     │
│ Status Badge                        │
│ Progress Bar (if running)           │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│ Current Step: [Step Name]           │
│                                     │
│ [Summary Cards]                     │
│ - Participants: X approved, Y pending│
│ - Matches: X completed / Y total    │
└─────────────────────────────────────┘

┌─────────────────────────────────────┐
│ Available Actions                   │
│                                     │
│ [Action Card 1] [Action Card 2]     │
│                                     │
│ Each action card shows:             │
│ - Icon/Button                       │
│ - Label                             │
│ - Description                       │
└─────────────────────────────────────┘
```

### Minimal Screens Needed

1. **Tournament Dashboard** (`/tournaments/{id}/manage`)
   - Shows flow state
   - Available actions
   - Summary stats

2. **Participants Management** (`/tournaments/{id}/participants`)
   - List participants
   - Approve/Reject actions

3. **Matches View** (`/tournaments/{id}/matches`)
   - All matches grouped by round
   - Quick status overview

4. **Match Detail** (`/tournaments/{id}/matches/{matchId}`)
   - Match info
   - Scoring interface
   - Start/End actions

5. **Standings** (`/tournaments/{id}/standings`)
   - Sortable table
   - Team rankings

---

## Implementation Example (Frontend)

```javascript
// Get tournament flow state
const flowState = await fetch(`/api/tournaments/${tournamentId}/flow`).then(r => r.json());

// Render based on current_step
switch (flowState.current_step) {
  case 'draft':
    return <DraftScreen actions={flowState.available_actions} />;
  case 'registration':
    return <RegistrationScreen actions={flowState.available_actions} summary={flowState.summary} />;
  case 'setup_brackets':
    return <SetupBracketsScreen actions={flowState.available_actions} />;
  case 'running':
    return <RunningScreen actions={flowState.available_actions} progress={flowState.progress} />;
  case 'completed':
    return <CompletedScreen actions={flowState.available_actions} />;
}
```

---

## Key Simplifications

1. **One Endpoint to Rule Them All**: `/flow` tells you everything you need to know
2. **State-Based UI**: Show only what's relevant for current step
3. **Clear Actions**: Each action card shows exactly what to do
4. **No Tabs**: Step-by-step flow instead of cluttered tabs
5. **Focus on Essential**: Only show what's needed for current phase

---

## Notes

- Registration currently requires an `eventId`. For initial tournament registration, you may need to:
  - Create a default event first, OR
  - Modify the registration endpoint to work without eventId for tournament-level registration
  
- The `generate_brackets` endpoint requires an event. You may need to create an event first, or use the first event if multiple exist.

- Auto-advance is enabled by default when ending matches. If disabled, use `/matches/{matchId}/advance` manually.

---

## Next Steps

1. Implement the flow state endpoint (✅ Done)
2. Build UI screens based on `current_step`
3. Use `available_actions` to show action buttons
4. Use `summary` for stats display
5. Navigate between screens based on flow state



