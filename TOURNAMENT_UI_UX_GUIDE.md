# Tournament Management UI/UX Guide

## Overview

This guide provides a simple, Challonge-inspired workflow for tournament management. All routes require authentication (`auth:api` middleware).

## Core Management Workflow

### 1. Create Tournament

**Route:** `POST /api/tournaments/create`

**Request:**
```json
{
  "name": "Summer Basketball Championship",
  "type": "single_sport",
  "tournament_type": "team vs team",
  "start_date": "2024-07-01",
  "end_date": "2024-07-15",
  "registration_deadline": "2024-06-25",
  "status": "draft",
  "location": "Sports Complex",
  "rules": "Standard basketball rules apply",
  "max_teams": 16,
  "min_teams": 8,
  "photo": "<file>"
}
```

**Response (201):**
```json
{
  "message": "Tournament created successfully",
  "tournament": {
    "id": 1,
    "name": "Summer Basketball Championship",
    "status": "draft",
    "created_by": 5
  }
}
```

**UI Note:** Start with `status: "draft"` - allows editing before opening registration.

---

### 2. Create Events (Games) Within Tournament

**Route:** `POST /api/tournaments/{tournamentId}/events`

**Request:**
```json
{
  "name": "Quarterfinal - Game 1",
  "description": "First quarterfinal match",
  "sport": "Basketball",
  "venue_id": 3,
  "facility_id": 5,
  "date": "2024-07-05",
  "start_time": "14:00",
  "end_time": "16:00",
  "slots": 2
}
```

**Response (201):**
```json
{
  "status": "success",
  "event": {
    "id": 10,
    "name": "Quarterfinal - Game 1",
    "tournament_id": 1,
    "game_number": 1,
    "is_tournament_game": true
  }
}
```

**UI Note:** Events are the actual games/matches. Create multiple events for different rounds.

---

### 3. User Registers for Event

**Route:** `POST /api/tournaments/events/{eventId}/register`

**Request (Team vs Team):**
```json
{
  "team_id": 7
}
```

**Request (Free for All):**
```json
{}
```

**Response (201):**
```json
{
  "message": "Successfully registered for event",
  "event_participant": {
    "id": 25,
    "event_id": 10,
    "user_id": 8,
    "team_id": 7,
    "status": "pending"
  }
}
```

**UI Note:** Organizer must approve participants before schedule generation.

---

### 4. Generate Schedule (Matches)

**Route:** `POST /api/tournaments/events/{eventId}/generate-schedule`

**Request:** `{}` (no body)

**Response (200):**
```json
{
  "message": "Event schedule generated successfully",
  "total_matches": 4
}
```

**UI Note:** Only works with approved participants. Creates `EventGame` records (matches) automatically.

---

### 5. Submit Match Score

**Route:** `POST /api/tournaments/event-game/{gameId}/submit-score`

**Request:**
```json
{
  "score_a": 85,
  "score_b": 72
}
```

**Response (200):**
```json
{
  "message": "Score recorded",
  "game": {
    "id": 15,
    "score_a": 85,
    "score_b": 72,
    "winner_team_id": 7,
    "status": "completed"
  }
}
```

**UI Note:** Automatically advances bracket (double elimination logic).

---

## Additional Routes

### Tournament CRUD

- `GET /api/tournaments/my` - List organizer's tournaments
- `GET /api/tournaments/{tournamentId}` - Get tournament details
- `PUT /api/tournaments/{tournamentId}` - Update tournament
- `DELETE /api/tournaments/{tournamentId}` - Delete tournament

### Games/Matches

- `GET /api/tournaments/events/{eventId}/games` - List all matches for event
- `GET /api/tournaments/events/{eventId}/bracket` - Get bracket view
- `GET /api/tournaments/event-game/{gameId}` - Get single match details
- `GET /api/tournaments/{tournamentId}/schedule` - Get full tournament schedule

### Results

- `GET /api/tournaments/events/{eventId}/champion` - Get event winner
- `GET /api/tournaments/{tournamentId}/results` - Get tournament results

### Public View

- `GET /api/tournaments/public/{tournamentId}` - Public tournament view (no auth)

### Announcements

- `POST /api/tournaments/{tournamentId}/announcements` - Create announcement
- `GET /api/tournaments/{tournamentId}/announcements` - List announcements
- `PUT /api/tournaments/{tournamentId}/announcements/{announcementId}` - Update
- `DELETE /api/tournaments/{tournamentId}/announcements/{announcementId}` - Delete

---

## Tournament Lifecycle Management

### Open Registration

**Route:** `POST /api/tournaments/{tournamentId}/open-registration`

**Request:** `{}`

**Response:**
```json
{
  "status": "success",
  "message": "Registration opened successfully",
  "tournament": { "status": "open_registration" }
}
```

### Close Registration

**Route:** `POST /api/tournaments/{tournamentId}/close-registration`

**Request:** `{}`

**Response:**
```json
{
  "status": "success",
  "message": "Registration closed successfully",
  "tournament": { "status": "registration_closed" }
}
```

### Start Tournament

**Route:** `POST /api/tournaments/{tournamentId}/start`

**Request:** `{}`

**Response:**
```json
{
  "status": "success",
  "message": "Tournament started successfully",
  "tournament": { "status": "ongoing" }
}
```

**UI Note:** Requires minimum approved participants (`min_teams`).

### Complete Tournament

**Route:** `POST /api/tournaments/{tournamentId}/complete`

**Request:** `{}`

**Response:**
```json
{
  "status": "success",
  "message": "Tournament completed successfully",
  "tournament": { "status": "completed" }
}
```

---

## Status Flow

```
draft → open_registration → registration_closed → ongoing → completed
```

**UI Recommendation:** Show status badges and disable actions based on current status.

---

## Key UI/UX Patterns

1. **Simple Workflow:** Follow the 5-step process above
2. **Status-Driven UI:** Show/hide buttons based on tournament status
3. **Approval Queue:** Show pending participants before schedule generation
4. **Bracket Visualization:** Use `/bracket` endpoint for visual bracket display
5. **Real-time Updates:** Poll match status after score submission

---

## Error Handling

Common errors:

- `403` - Unauthorized (not organizer)
- `422` - Validation failed
- `409` - Already registered/exists
- `404` - Tournament/event not found

All errors follow format:
```json
{
  "message": "Error description",
  "errors": { /* validation errors if applicable */ }
}
```

---

## Participant Management

### List Participants

**Route:** `GET /api/tournaments/{tournamentId}/participants?status=pending`

**Response:**
```json
{
  "participants": [
    {
      "id": 1,
      "user_id": 8,
      "team_id": 7,
      "status": "pending",
      "user": { "id": 8, "first_name": "John", "last_name": "Doe" },
      "team": { "id": 7, "name": "Lakers" }
    }
  ]
}
```

### Approve/Reject Participant

**Route:** `POST /api/tournaments/participants/{participantId}/approve`

**Request:**
```json
{
  "action": "approve"
}
```

or

```json
{
  "action": "reject",
  "rejection_reason": "Team roster incomplete"
}
```

**Response:**
```json
{
  "message": "Participant status updated",
  "participant": {
    "id": 1,
    "status": "approved",
    "approved_by": 5,
    "approved_at": "2024-06-20T10:00:00Z"
  }
}
```

### Approve/Reject Event Participant

**Route:** `POST /api/tournaments/participants/{participantId}/event-approve`

**Request:**
```json
{
  "action": "approve"
}
```

**Response:**
```json
{
  "message": "Participant status updated",
  "participant": {
    "id": 25,
    "event_id": 10,
    "status": "approved",
    "approved_at": "2024-06-20T10:00:00Z"
  }
}
```

---

## Event Management

### List Events

**Route:** `GET /api/tournaments/{tournamentId}/events`

**Response:**
```json
{
  "tournament": "Summer Basketball Championship",
  "events": [
    {
      "id": 10,
      "name": "Quarterfinal - Game 1",
      "date": "2024-07-05",
      "start_time": "14:00",
      "sport": "Basketball"
    }
  ]
}
```

### Update Event

**Route:** `PUT /api/tournaments/events/{eventId}`

**Request:**
```json
{
  "name": "Quarterfinal - Game 1 (Updated)",
  "date": "2024-07-06",
  "start_time": "15:00"
}
```

### Cancel Event

**Route:** `POST /api/tournaments/events/{eventId}/cancel`

**Request:** `{}`

**Response:**
```json
{
  "message": "Event cancelled successfully",
  "event": {
    "id": 10,
    "cancelled_at": "2024-06-20T10:00:00Z"
  }
}
```

---

## Bracket & Schedule Details

### Get Bracket View

**Route:** `GET /api/tournaments/events/{eventId}/bracket`

**Response:**
```json
{
  "event_id": 10,
  "event_name": "Quarterfinal - Game 1",
  "bracket": {
    "winners": {
      "1": [
        {
          "id": 15,
          "round_number": 1,
          "match_number": 1,
          "team_a_id": 7,
          "team_b_id": 8,
          "score_a": 85,
          "score_b": 72,
          "status": "completed",
          "team_a": { "id": 7, "name": "Lakers" },
          "team_b": { "id": 8, "name": "Warriors" }
        }
      ]
    },
    "losers": {},
    "grand_final": {}
  },
  "champion": null
}
```

### Get Tournament Schedule

**Route:** `GET /api/tournaments/{tournamentId}/schedule?event_id=10&status=scheduled`

**Response:**
```json
{
  "tournament_id": 1,
  "tournament_name": "Summer Basketball Championship",
  "schedule": [
    {
      "event_id": 10,
      "event_name": "Quarterfinal - Game 1",
      "games": [
        {
          "id": 15,
          "round_number": 1,
          "match_number": 1,
          "match_stage": "winners",
          "team_a_id": 7,
          "team_b_id": 8,
          "status": "scheduled"
        }
      ]
    }
  ],
  "summary": {
    "total_events": 1,
    "total_matches": 4,
    "completed_matches": 0,
    "upcoming_matches": 4
  }
}
```

---

## Frontend Implementation Tips

### 1. Tournament Creation Flow

```javascript
// Step 1: Create tournament
const tournament = await createTournament({
  name: "Summer Championship",
  type: "single_sport",
  tournament_type: "team vs team",
  start_date: "2024-07-01",
  end_date: "2024-07-15",
  status: "draft"
});

// Step 2: Navigate to tournament detail page
router.push(`/tournaments/${tournament.id}`);
```

### 2. Event Creation

```javascript
// Create event within tournament
const event = await createEvent(tournamentId, {
  name: "Quarterfinal - Game 1",
  sport: "Basketball",
  venue_id: 3,
  facility_id: 5,
  date: "2024-07-05",
  start_time: "14:00",
  end_time: "16:00",
  slots: 2
});
```

### 3. Participant Approval Workflow

```javascript
// List pending participants
const pending = await getParticipants(tournamentId, { status: "pending" });

// Approve participants
for (const participant of pending) {
  await approveParticipant(participant.id, { action: "approve" });
}

// Generate schedule after approvals
await generateSchedule(eventId);
```

### 4. Score Submission

```javascript
// Submit score for a match
const result = await submitScore(gameId, {
  score_a: 85,
  score_b: 72
});

// Refresh bracket to show updated matches
const bracket = await getBracket(eventId);
```

### 5. Status-Based UI Controls

```javascript
const canOpenRegistration = tournament.status === "draft";
const canCloseRegistration = tournament.status === "open_registration";
const canStart = tournament.status === "registration_closed";
const canComplete = tournament.status === "ongoing";

// Show/hide buttons based on status
```

---

## User-Side (Participant) Workflow

This section covers the user experience for participants who want to join tournaments, view brackets, and check results.

### 1. Browse Available Tournaments

**Route:** `GET /api/tournaments?status=open_registration&type=single_sport`

**Query Parameters:**
- `status` - Filter by status (e.g., `open_registration`, `ongoing`)
- `type` - Filter by type (`single_sport`, `multisport`)
- `tournament_type` - Filter by tournament type (`team vs team`, `free for all`)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Summer Basketball Championship",
      "type": "single_sport",
      "tournament_type": "team vs team",
      "start_date": "2024-07-01",
      "end_date": "2024-07-15",
      "status": "open_registration",
      "location": "Sports Complex",
      "max_teams": 16,
      "min_teams": 8
    }
  ],
  "current_page": 1,
  "per_page": 10,
  "total": 5
}
```

**UI Note:** Show tournaments with `status: "open_registration"` for users to join.

---

### 2. View Tournament Details

**Route:** `GET /api/tournaments/{tournamentId}`

**Response (200):**
```json
{
  "message": "Tournament retrieved successfully",
  "tournament": {
    "id": 1,
    "name": "Summer Basketball Championship",
    "type": "single_sport",
    "tournament_type": "team vs team",
    "start_date": "2024-07-01",
    "end_date": "2024-07-15",
    "registration_deadline": "2024-06-25",
    "status": "open_registration",
    "location": "Sports Complex",
    "rules": "Standard basketball rules apply",
    "max_teams": 16,
    "min_teams": 8,
    "participants_count": 12,
    "pending_participants_count": 3,
    "events": [
      {
        "id": 10,
        "name": "Quarterfinal - Game 1",
        "date": "2024-07-05",
        "sport": "Basketball"
      }
    ],
    "announcements": [
      {
        "id": 1,
        "title": "Registration Closing Soon",
        "content": "Register before June 25th!",
        "is_pinned": true,
        "published_at": "2024-06-20T10:00:00Z"
      }
    ]
  }
}
```

**UI Note:** Show registration button if `status === "open_registration"` and user hasn't registered yet.

---

### 3. Register for Tournament

**Route:** `POST /api/tournaments/{tournamentId}/register`

**Request (Team vs Team):**
```json
{
  "type": "team",
  "team_id": 7
}
```

**Request (Free for All):**
```json
{
  "type": "individual"
}
```

**Response (201):**
```json
{
  "message": "Registration submitted",
  "participant": {
    "id": 1,
    "tournament_id": 1,
    "user_id": 8,
    "team_id": 7,
    "type": "team",
    "status": "pending",
    "registered_at": "2024-06-20T10:00:00Z"
  }
}
```

**UI Note:** Registration status will be `pending` until organizer approves.

---

### 4. Check Registration Status

**Route:** `GET /api/tournaments/{tournamentId}/participants?status=approved`

**Response (200):**
```json
{
  "participants": [
    {
      "id": 1,
      "tournament_id": 1,
      "user_id": 8,
      "team_id": 7,
      "type": "team",
      "status": "approved",
      "approved_at": "2024-06-21T10:00:00Z",
      "user": {
        "id": 8,
        "first_name": "John",
        "last_name": "Doe"
      },
      "team": {
        "id": 7,
        "name": "Lakers"
      }
    }
  ]
}
```

**UI Note:** Show user's own registration status prominently on tournament page.

---

### 5. Register for Specific Event (Game)

**Route:** `POST /api/tournaments/events/{eventId}/register`

**Request (Team vs Team):**
```json
{
  "team_id": 7
}
```

**Request (Free for All):**
```json
{}
```

**Response (201):**
```json
{
  "message": "Successfully registered for event",
  "event_participant": {
    "id": 25,
    "event_id": 10,
    "user_id": 8,
    "team_id": 7,
    "status": "pending"
  }
}
```

**UI Note:** Users must be approved tournament participants first. Event registration may require organizer approval.

---

### 6. View Tournament Schedule

**Route:** `GET /api/tournaments/{tournamentId}/schedule`

**Response (200):**
```json
{
  "tournament_id": 1,
  "tournament_name": "Summer Basketball Championship",
  "schedule": [
    {
      "event_id": 10,
      "event_name": "Quarterfinal - Game 1",
      "games": [
        {
          "id": 15,
          "round_number": 1,
          "match_number": 1,
          "match_stage": "winners",
          "team_a_id": 7,
          "team_b_id": 8,
          "status": "scheduled",
          "team_a": {
            "id": 7,
            "name": "Lakers"
          },
          "team_b": {
            "id": 8,
            "name": "Warriors"
          }
        }
      ]
    }
  ],
  "summary": {
    "total_events": 1,
    "total_matches": 4,
    "completed_matches": 0,
    "upcoming_matches": 4
  }
}
```

**UI Note:** Display schedule in calendar or list view. Highlight user's team matches.

---

### 7. View Bracket

**Route:** `GET /api/tournaments/events/{eventId}/bracket`

**Response (200):**
```json
{
  "event_id": 10,
  "event_name": "Quarterfinal - Game 1",
  "bracket": {
    "winners": {
      "1": [
        {
          "id": 15,
          "round_number": 1,
          "match_number": 1,
          "team_a_id": 7,
          "team_b_id": 8,
          "score_a": 85,
          "score_b": 72,
          "status": "completed",
          "winner_team_id": 7,
          "team_a": {
            "id": 7,
            "name": "Lakers"
          },
          "team_b": {
            "id": 8,
            "name": "Warriors"
          }
        }
      ]
    },
    "losers": {},
    "grand_final": {}
  },
  "champion": null
}
```

**UI Note:** Visualize bracket with winners/losers/grand final sections. Highlight user's team progress.

---

### 8. View Tournament Results

**Route:** `GET /api/tournaments/{tournamentId}/results`

**Response (200):**
```json
{
  "tournament_id": 1,
  "tournament_name": "Summer Basketball Championship",
  "status": "completed",
  "events": [
    {
      "event_id": 10,
      "event_name": "Quarterfinal - Game 1",
      "champion": {
        "team_id": 7,
        "team": {
          "id": 7,
          "name": "Lakers"
        }
      },
      "final_standings": [
        {
          "rank": 1,
          "team_id": 7,
          "team": {
            "id": 7,
            "name": "Lakers"
          },
          "wins": 5,
          "losses": 0,
          "points_for": 425,
          "points_against": 320,
          "point_difference": 105
        }
      ]
    }
  ],
  "overall_champion": {
    "team_id": 7,
    "team": {
      "id": 7,
      "name": "Lakers"
    }
  }
}
```

**UI Note:** Show results page after tournament completion. Display standings, champion, and statistics.

---

### 9. View Event Champion

**Route:** `GET /api/tournaments/events/{eventId}/champion`

**Response (200):**
```json
{
  "event_id": 10,
  "event_name": "Quarterfinal - Game 1",
  "is_completed": true,
  "champion": {
    "team_id": 7,
    "team": {
      "id": 7,
      "name": "Lakers"
    }
  },
  "final_match": {
    "id": 20,
    "round_number": 2,
    "match_stage": "grand_final",
    "score_a": 95,
    "score_b": 88,
    "winner_team_id": 7
  }
}
```

---

### 10. View Tournament Announcements

**Route:** `GET /api/tournaments/{tournamentId}/announcements`

**Response (200):**
```json
{
  "announcements": [
    {
      "id": 1,
      "tournament_id": 1,
      "title": "Registration Closing Soon",
      "content": "Register before June 25th!",
      "priority": "high",
      "is_pinned": true,
      "creator_name": "John Organizer",
      "published_at": "2024-06-20T10:00:00Z",
      "created_at": "2024-06-20T10:00:00Z"
    }
  ],
  "count": 1
}
```

**UI Note:** Display pinned announcements at top. Show priority badges (high/medium/low).

---

### 11. Withdraw from Tournament

**Route:** `POST /api/tournaments/participants/{participantId}/withdraw`

**Request:** `{}` (no body)

**Response (200):**
```json
{
  "message": "Registration withdrawn successfully"
}
```

**UI Note:** Only allow withdrawal before tournament starts. Show confirmation dialog.

---

### 12. View Public Tournament (No Auth Required)

**Route:** `GET /api/tournaments/public/{tournamentId}`

**Response (200):**
```json
{
  "tournament": {
    "id": 1,
    "name": "Summer Basketball Championship",
    "type": "single_sport",
    "tournament_type": "team vs team",
    "start_date": "2024-07-01",
    "end_date": "2024-07-15",
    "status": "open_registration",
    "location": "Sports Complex",
    "participants_count": 12,
    "events": [
      {
        "id": 10,
        "name": "Quarterfinal - Game 1",
        "date": "2024-07-05",
        "sport": "Basketball"
      }
    ],
    "announcements": [
      {
        "id": 1,
        "title": "Registration Closing Soon",
        "content": "Register before June 25th!",
        "published_at": "2024-06-20T10:00:00Z"
      }
    ]
  }
}
```

**UI Note:** Use this for sharing tournament links publicly. Hide sensitive data like participant details.

---

## User-Side Frontend Implementation

### 1. Tournament Discovery

```javascript
// Browse open tournaments
const tournaments = await fetch('/api/tournaments?status=open_registration')
  .then(res => res.json());

// Filter by sport type
const singleSport = tournaments.data.filter(t => t.type === 'single_sport');
```

### 2. Registration Flow

```javascript
// Check if user already registered
const participants = await fetch(`/api/tournaments/${tournamentId}/participants`)
  .then(res => res.json());

const isRegistered = participants.participants.some(
  p => p.user_id === currentUser.id
);

// Register if not registered
if (!isRegistered) {
  const registration = await registerForTournament(tournamentId, {
    type: tournament.tournament_type === 'team vs team' ? 'team' : 'individual',
    team_id: selectedTeamId
  });
  
  // Show pending status
  showNotification('Registration submitted. Waiting for approval.');
}
```

### 3. View My Tournaments

```javascript
// Get tournaments where user is registered
const myTournaments = await fetch('/api/tournaments')
  .then(res => res.json())
  .then(data => data.data.filter(t => 
    t.participants?.some(p => p.user_id === currentUser.id)
  ));
```

### 4. Track Match Progress

```javascript
// Get bracket for user's event
const bracket = await fetch(`/api/tournaments/events/${eventId}/bracket`)
  .then(res => res.json());

// Find user's team matches
const myMatches = [];
Object.values(bracket.bracket.winners).forEach(round => {
  round.forEach(match => {
    if (match.team_a_id === myTeamId || match.team_b_id === myTeamId) {
      myMatches.push(match);
    }
  });
});

// Show upcoming matches
const upcoming = myMatches.filter(m => m.status === 'scheduled');
```

### 5. Results Display

```javascript
// Show tournament results
const results = await fetch(`/api/tournaments/${tournamentId}/results`)
  .then(res => res.json());

if (results.status === 'completed') {
  // Display champion
  displayChampion(results.overall_champion);
  
  // Show standings
  results.events.forEach(event => {
    displayStandings(event.final_standings);
  });
}
```

---

## User-Side Quick Reference

| Action | Method | Route | Auth Required |
|--------|--------|-------|---------------|
| Browse Tournaments | GET | `/api/tournaments?status=open_registration` | Yes |
| View Tournament | GET | `/api/tournaments/{id}` | Yes |
| View Public Tournament | GET | `/api/tournaments/public/{id}` | No |
| Register for Tournament | POST | `/api/tournaments/{id}/register` | Yes |
| Check Registration Status | GET | `/api/tournaments/{id}/participants` | Yes |
| Register for Event | POST | `/api/tournaments/events/{eventId}/register` | Yes |
| View Schedule | GET | `/api/tournaments/{id}/schedule` | Yes |
| View Bracket | GET | `/api/tournaments/events/{eventId}/bracket` | Yes |
| View Results | GET | `/api/tournaments/{id}/results` | Yes |
| View Event Champion | GET | `/api/tournaments/events/{eventId}/champion` | Yes |
| View Announcements | GET | `/api/tournaments/{id}/announcements` | Yes |
| Withdraw Registration | POST | `/api/tournaments/participants/{id}/withdraw` | Yes |

---

## Quick Reference: Route Summary

| Action | Method | Route | Auth Required |
|--------|--------|-------|---------------|
| Create Tournament | POST | `/api/tournaments/create` | Yes |
| List My Tournaments | GET | `/api/tournaments/my` | Yes |
| Get Tournament | GET | `/api/tournaments/{id}` | Yes |
| Update Tournament | PUT | `/api/tournaments/{id}` | Yes |
| Delete Tournament | DELETE | `/api/tournaments/{id}` | Yes |
| Create Event | POST | `/api/tournaments/{id}/events` | Yes |
| List Events | GET | `/api/tournaments/{id}/events` | Yes |
| Register for Event | POST | `/api/tournaments/events/{eventId}/register` | Yes |
| List Event Participants | GET | `/api/tournaments/events/{eventId}/participants` | Yes |
| Approve Event Participant | POST | `/api/tournaments/participants/{id}/event-approve` | Yes |
| Generate Schedule | POST | `/api/tournaments/events/{eventId}/generate-schedule` | Yes |
| Submit Score | POST | `/api/tournaments/event-game/{gameId}/submit-score` | Yes |
| Get Bracket | GET | `/api/tournaments/events/{eventId}/bracket` | Yes |
| Get Schedule | GET | `/api/tournaments/{id}/schedule` | Yes |
| Open Registration | POST | `/api/tournaments/{id}/open-registration` | Yes |
| Close Registration | POST | `/api/tournaments/{id}/close-registration` | Yes |
| Start Tournament | POST | `/api/tournaments/{id}/start` | Yes |
| Complete Tournament | POST | `/api/tournaments/{id}/complete` | Yes |
