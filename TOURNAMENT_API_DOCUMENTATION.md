# Tournament API Documentation

Complete API reference for all tournament-related endpoints with sample payloads and responses.

**Base URL**: `/api`  
**Authentication**: All routes (except public) require `auth:api` middleware (JWT token in `Authorization: Bearer {token}` header)

---

## Table of Contents

1. [Tournament CRUD](#tournament-crud)
2. [Games/Events Management](#gamesevents-management)
3. [Registration & Participants](#registration--participants)
4. [Document Management](#document-management)
5. [Match Management](#match-management)
6. [Bracket Management](#bracket-management)
7. [Analytics & Statistics](#analytics--statistics)
8. [Organizer Management](#organizer-management)
9. [Tournament Status](#tournament-status)
10. [Announcements](#announcements)
11. [Waitlist Management](#waitlist-management)
12. [Tournament Phases](#tournament-phases)
13. [Templates](#templates)
14. [Utility Routes](#utility-routes)

---

## Tournament CRUD

### 1. List Tournaments
**GET** `/tournaments`

**Query Parameters:**
- `name` (string, optional) - Filter by tournament name
- `status` (string, optional) - Filter by status: `draft`, `open_registration`, `registration_closed`, `ongoing`, `completed`, `cancelled`
- `type` (string, optional) - Filter by type: `single_sport`, `multisport`
- `tournament_type` (string, optional) - Filter by tournament type: `team vs team`, `free for all`
- `sport` (string, optional) - Filter by sport
- `start_date_from` (date, optional) - Filter tournaments starting from date
- `start_date_to` (date, optional) - Filter tournaments starting until date
- `venue_id` (integer, optional) - Filter by venue
- `city` (string, optional) - Filter by city
- `include_draft` (boolean, optional) - Include draft tournaments
- `sort_by` (string, optional) - Sort by: `created_at`, `start_date`, `name`, `status` (default: `created_at`)
- `sort_order` (string, optional) - `asc` or `desc` (default: `desc`)
- `per_page` (integer, optional) - Results per page (max 100, default: 15)
- `page` (integer, optional) - Page number

**Sample Request:**
```bash
GET /api/tournaments?status=ongoing&sport=basketball&per_page=20
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "name": "Summer Basketball Championship",
      "description": "Annual summer tournament",
      "type": "single_sport",
      "tournament_type": "team vs team",
      "status": "ongoing",
      "start_date": "2024-06-01",
      "end_date": "2024-06-15",
      "registration_deadline": "2024-05-25",
      "max_teams": 32,
      "min_teams": 8,
      "registration_fee": 500.00,
      "created_by": 1,
      "created_at": "2024-05-01T10:00:00.000000Z",
      "events": [...],
      "participants": [...],
      "organizers": [...]
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 87,
    "from": 1,
    "to": 20
  },
  "count": 20
}
```

---

### 2. Get Public Tournament
**GET** `/tournaments/public/{id}`

**No Authentication Required**

**Sample Request:**
```bash
GET /api/tournaments/public/1
```

**Sample Response:**
```json
{
  "status": "success",
  "tournament": {
    "id": 1,
    "name": "Summer Basketball Championship",
    "description": "Annual summer tournament",
    "location": "City Sports Complex",
    "type": "single_sport",
    "tournament_type": "team vs team",
    "start_date": "2024-06-01",
    "end_date": "2024-06-15",
    "registration_deadline": "2024-05-25",
    "status": "open_registration",
    "registration_fee": 500.00,
    "rules": "Standard basketball rules apply...",
    "prizes": "1st Place: $5000, 2nd Place: $2500",
    "max_teams": 32,
    "min_teams": 8,
    "created_at": "2024-05-01T10:00:00.000000Z",
    "events": [
      {
        "id": 10,
        "name": "Round 1 - Match 1",
        "date": "2024-06-01",
        "start_time": "09:00:00",
        "end_time": "11:00:00",
        "sport": "basketball",
        "game_number": 1
      }
    ],
    "organizers": [
      {
        "id": 1,
        "username": "organizer1",
        "first_name": "John",
        "last_name": "Doe",
        "role": "owner"
      }
    ],
    "announcements": [
      {
        "id": 1,
        "title": "Registration Open",
        "content": "Registration is now open!",
        "created_at": "2024-05-01T10:00:00.000000Z"
      }
    ],
    "participants_count": 24
  }
}
```

---

### 3. Get Tournament Details
**GET** `/tournaments/show/{id}`

**Sample Request:**
```bash
GET /api/tournaments/show/1
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "tournament": {
    "id": 1,
    "name": "Summer Basketball Championship",
    "description": "Annual summer tournament",
    "location": "City Sports Complex",
    "type": "single_sport",
    "tournament_type": "team vs team",
    "status": "ongoing",
    "start_date": "2024-06-01",
    "end_date": "2024-06-15",
    "registration_deadline": "2024-05-25",
    "requires_documents": true,
    "required_documents": ["id_card", "medical_certificate"],
    "settings": {
      "participants_locked": false,
      "auto_advance_bracket": true,
      "public_brackets": true
    },
    "max_teams": 32,
    "min_teams": 8,
    "registration_fee": 500.00,
    "rules": "Standard basketball rules apply...",
    "prizes": "1st Place: $5000",
    "created_by": 1,
    "created_at": "2024-05-01T10:00:00.000000Z",
    "events": [...],
    "participants": [...],
    "organizers": [...],
    "documents": [...],
    "analytics": {...},
    "announcements": [...]
  }
}
```

---

### 4. Get My Tournaments
**GET** `/tournaments/my`

**Query Parameters:** Same as List Tournaments

**Sample Request:**
```bash
GET /api/tournaments/my?status=ongoing
Authorization: Bearer {token}
```

**Sample Response:** Same structure as List Tournaments, but filtered to tournaments where user is creator or organizer

---

### 5. Create Tournament
**POST** `/tournaments/create`

**Request Body:**
```json
{
  "name": "Summer Basketball Championship",
  "description": "Annual summer tournament",
  "location": "City Sports Complex",
  "type": "single_sport",
  "tournament_type": "team vs team",
  "start_date": "2024-06-01",
  "end_date": "2024-06-15",
  "registration_deadline": "2024-05-25",
  "status": "draft",
  "requires_documents": true,
  "required_documents": ["id_card", "medical_certificate"],
  "settings": {
    "participants_locked": false,
    "auto_advance_bracket": true
  },
  "max_teams": 32,
  "min_teams": 8,
  "registration_fee": 500.00,
  "rules": "Standard basketball rules apply...",
  "prizes": "1st Place: $5000",
  "photo": "<file>"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "tournament": {
    "id": 1,
    "name": "Summer Basketball Championship",
    ...
  }
}
```

---

### 6. Update Tournament
**PUT** `/tournaments/update/{id}`

**Request Body:** Same as Create Tournament (all fields optional)

**Sample Request:**
```bash
PUT /api/tournaments/update/1
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Updated Tournament Name",
  "status": "open_registration"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "tournament": {
    "id": 1,
    "name": "Updated Tournament Name",
    ...
  }
}
```

---

### 7. Update Tournament Settings
**PATCH** `/tournaments/{tournamentId}/settings`

**Request Body:**
```json
{
  "settings": {
    "participants_locked": true,
    "auto_advance_bracket": false,
    "allow_withdrawal": true,
    "require_checkin": true,
    "checkin_deadline_minutes": 30,
    "score_verification_required": true,
    "public_brackets": true,
    "public_standings": true
  }
}
```

**OR individual settings:**
```json
{
  "participants_locked": true,
  "auto_advance_bracket": false
}
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Tournament settings updated",
  "tournament_id": 1,
  "settings": {
    "participants_locked": true,
    "auto_advance_bracket": false,
    "allow_withdrawal": true,
    ...
  }
}
```

---

### 8. Delete Tournament
**DELETE** `/tournaments/delete/{id}`

**Sample Request:**
```bash
DELETE /api/tournaments/delete/1
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Tournament deleted"
}
```

---

### 9. Get Tournament Schedule
**GET** `/tournaments/{tournamentId}/schedule`

**Query Parameters:**
- `start_date` (date, optional) - Filter from date
- `end_date` (date, optional) - Filter until date
- `phase_id` (integer, optional) - Filter by phase
- `round` (integer, optional) - Filter by round/game_number

**Sample Request:**
```bash
GET /api/tournaments/1/schedule?start_date=2024-06-01&end_date=2024-06-05
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "schedule": [
    {
      "id": 10,
      "name": "Round 1 - Match 1",
      "date": "2024-06-01",
      "start_time": "09:00:00",
      "end_time": "11:00:00",
      "game_number": 1,
      "status": "scheduled",
      "sport": "basketball",
      "venue": {
        "id": 5,
        "name": "City Sports Complex",
        "address": "123 Main St",
        "city": "New York"
      },
      "facility": {
        "id": 2,
        "type": "basketball_court"
      },
      "teams": [...],
      "participants_count": 10
    }
  ],
  "count": 15
}
```

---

## Games/Events Management

### 10. Create Game/Event
**POST** `/tournaments/{tournamentid}/creategames`

**Request Body:**
```json
{
  "name": "Round 1 - Match 1",
  "description": "First round match",
  "sport": "basketball",
  "venue_id": 5,
  "facility_id": 2,
  "date": "2024-06-01",
  "start_time": "09:00:00",
  "end_time": "11:00:00",
  "game_number": 1,
  "is_tournament_game": true
}
```

**Sample Response:**
```json
{
  "status": "success",
  "game": {
    "id": 10,
    "name": "Round 1 - Match 1",
    ...
  }
}
```

---

### 11. Get Games
**GET** `/tournaments/{tournamentid}/getgames`

**Query Parameters:**
- `sport` (string, optional) - Filter by sport

**Sample Request:**
```bash
GET /api/tournaments/1/getgames?sport=basketball
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "games": [
    {
      "id": 10,
      "name": "Round 1 - Match 1",
      "game_number": 1,
      "date": "2024-06-01",
      "start_time": "09:00:00",
      "sport": "basketball",
      ...
    }
  ]
}
```

---

### 12. Update Game
**PUT/PATCH** `/tournaments/{tournamentid}/updategames/{gameid}`

**Request Body:** Same as Create Game (all fields optional)

**Sample Response:**
```json
{
  "status": "success",
  "game": {...}
}
```

---

### 13. Delete Game
**DELETE** `/tournaments/{tournamentid}/deletegames/{gameid}`

**Sample Response:**
```json
{
  "status": "success",
  "message": "Game deleted"
}
```

---

## Registration & Participants

### 14. Register for Tournament
**POST** `/tournaments/{tournamentid}/register/{eventid}`

**Request Body (Individual):**
```json
{
  "participant_type": "individual",
  "user_id": 5
}
```

**Request Body (Team):**
```json
{
  "participant_type": "team",
  "team_id": 10
}
```

**Sample Response:**
```json
{
  "status": "success",
  "participant": {
    "id": 25,
    "tournament_id": 1,
    "participant_type": "individual",
    "user_id": 5,
    "status": "pending",
    "registered_at": "2024-05-15T10:00:00.000000Z"
  }
}
```

---

### 15. Get Participants
**GET** `/tournaments/{tournamentid}/participants`

**Query Parameters:**
- `status` (string, optional) - Filter by status: `pending`, `approved`, `rejected`, `banned`
- `participant_type` (string, optional) - Filter by type: `individual`, `team`

**Sample Request:**
```bash
GET /api/tournaments/1/participants?status=approved
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "participants": [
    {
      "id": 25,
      "tournament_id": 1,
      "participant_type": "individual",
      "user_id": 5,
      "team_id": null,
      "status": "approved",
      "registered_at": "2024-05-15T10:00:00.000000Z",
      "approved_at": "2024-05-16T09:00:00.000000Z",
      "user": {
        "id": 5,
        "username": "player1",
        "first_name": "John",
        "last_name": "Doe"
      }
    }
  ],
  "count": 24
}
```

---

### 16. Approve Participant
**POST** `/tournaments/{tournamentid}/participants/{participantid}/approve`

**Sample Request:**
```bash
POST /api/tournaments/1/participants/25/approve
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "participant": {
    "id": 25,
    "status": "approved",
    "approved_at": "2024-05-16T09:00:00.000000Z"
  },
  "message": "Participant approved"
}
```

---

### 17. Bulk Approve Participants
**POST** `/tournaments/{tournamentid}/participants/bulk-approve`

**Request Body:**
```json
{
  "participant_ids": [25, 26, 27, 28]
}
```

**Sample Response:**
```json
{
  "status": "success",
  "approved_count": 4,
  "approved_ids": [25, 26, 27, 28],
  "skipped_count": 0,
  "skipped_ids": [],
  "message": "4 participant(s) approved successfully"
}
```

---

### 18. Reject Participant
**POST** `/tournaments/{tournamentid}/participants/{participantid}/reject`

**Request Body (optional):**
```json
{
  "reason": "Incomplete documentation"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "participant": {
    "id": 25,
    "status": "rejected"
  },
  "message": "Participant rejected"
}
```

---

### 19. Ban Participant
**POST** `/tournaments/{tournamentid}/participants/{participantid}/ban`

**Request Body (optional):**
```json
{
  "reason": "Violation of rules"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "participant": {
    "id": 25,
    "status": "banned"
  },
  "message": "Participant banned"
}
```

---

### 20. Withdraw from Tournament
**DELETE** `/tournaments/{tournamentId}/withdraw`

**Sample Request:**
```bash
DELETE /api/tournaments/1/withdraw
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Successfully withdrawn from tournament"
}
```

---

## Document Management

### 21. Upload Document
**POST** `/tournaments/{tournamentid}/documents/upload`

**Request Body (multipart/form-data):**
```
file: <file>
participant_id: 25
document_type: "id_card"
```

**Sample Response:**
```json
{
  "status": "success",
  "document": {
    "id": 10,
    "tournament_id": 1,
    "participant_id": 25,
    "document_type": "id_card",
    "file_path": "tournaments/1/documents/10/file.pdf",
    "verification_status": "pending",
    "uploaded_at": "2024-05-15T10:00:00.000000Z"
  }
}
```

---

### 22. Get Documents
**GET** `/tournaments/{tournamentid}/documents`

**Sample Response:**
```json
{
  "status": "success",
  "documents": [
    {
      "id": 10,
      "tournament_id": 1,
      "participant_id": 25,
      "document_type": "id_card",
      "verification_status": "pending",
      "uploaded_at": "2024-05-15T10:00:00.000000Z"
    }
  ]
}
```

---

### 23. Get Participant Documents
**GET** `/tournaments/{tournamentid}/participants/{participantId}/documents`

**Sample Response:** Same structure as Get Documents, filtered by participant

---

### 24. Verify Document
**POST** `/tournaments/{tournamentid}/documents/{documentId}/verify`

**Request Body:**
```json
{
  "verification_status": "verified",
  "notes": "Document verified successfully"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "document": {
    "id": 10,
    "verification_status": "verified",
    ...
  }
}
```

---

### 25. Delete Document
**DELETE** `/tournaments/{tournamentid}/documents/{documentId}/delete`

**Sample Response:**
```json
{
  "status": "success",
  "message": "Document deleted"
}
```

---

## Match Management

### 26. Get Matches
**GET** `/tournaments/{tournamentid}/matches`

**Sample Request:**
```bash
GET /api/tournaments/1/matches
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "groups": {
    "1": [
      {
        "id": 10,
        "name": "Round 1 - Match 1",
        "game_number": 1,
        "date": "2024-06-01",
        "start_time": "09:00:00",
        "teams": [...],
        "participants": [...]
      }
    ],
    "2": [...]
  }
}
```

---

### 27. Get Live Matches
**GET** `/tournaments/{tournamentid}/matches/live`

**Sample Request:**
```bash
GET /api/tournaments/1/matches/live
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "live_matches": [
    {
      "id": 10,
      "name": "Round 1 - Match 1",
      "date": "2024-06-01",
      "start_time": "09:00:00",
      "end_time": "11:00:00",
      "game_number": 1,
      "status": "ongoing",
      "game_status": "in_progress",
      "sport": "basketball",
      "venue": {
        "id": 5,
        "name": "City Sports Complex",
        "address": "123 Main St"
      },
      "teams": [...],
      "participants": [...]
    }
  ],
  "count": 3
}
```

---

### 28. Get Match Details
**GET** `/tournaments/{tournamentid}/matches/{match}`

**Sample Response:**
```json
{
  "status": "success",
  "match": {
    "id": 10,
    "name": "Round 1 - Match 1",
    "date": "2024-06-01",
    "start_time": "09:00:00",
    "teams": [...],
    "participants": [...]
  },
  "penalties": [
    {
      "id": 1,
      "event_id": 10,
      "team_id": 5,
      "type": "technical_foul",
      "description": "Unsportsmanlike conduct",
      "created_at": "2024-06-01T09:30:00.000000Z"
    }
  ],
  "results": [
    {
      "id": 1,
      "event_id": 10,
      "team_id": 5,
      "points": 85,
      "recorded_by": 1,
      "timestamp": "2024-06-01T11:00:00.000000Z"
    }
  ]
}
```

---

### 29. Start Match
**POST** `/tournaments/{tournamentid}/matches/{match}/start`

**Sample Request:**
```bash
POST /api/tournaments/1/matches/10/start
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "match": {
    "id": 10,
    "status": "in_progress",
    "started_at": "2024-06-01T09:00:00.000000Z",
    ...
  }
}
```

---

### 30. End Match
**POST** `/tournaments/{tournamentid}/matches/{match}/end`

**Request Body:**
```json
{
  "winner_team_id": 5,
  "score_home": 85,
  "score_away": 72,
  "auto_advance": true
}
```

**Sample Response:**
```json
{
  "status": "success",
  "match": {
    "id": 10,
    "status": "completed",
    "completed_at": "2024-06-01T11:00:00.000000Z",
    "score_home": 85,
    "score_away": 72,
    "winner_team_id": 5
  },
  "advanced_to_next_match": true
}
```

---

### 31. Update Score
**POST** `/tournaments/{tournamentid}/matches/{match}/score`

**Request Body (Team vs Team):**
```json
{
  "score_home": 85,
  "score_away": 72
}
```

**Request Body (Free for All):**
```json
{
  "scores": [
    {
      "participant_id": 25,
      "points": 95
    },
    {
      "participant_id": 26,
      "points": 87
    }
  ]
}
```

**Sample Response:**
```json
{
  "status": "success",
  "match": {
    "id": 10,
    "score_home": 85,
    "score_away": 72,
    ...
  }
}
```

---

### 32. Issue Penalty
**POST** `/tournaments/{tournamentid}/matches/{match}/penalty`

**Request Body:**
```json
{
  "team_id": 5,
  "type": "technical_foul",
  "description": "Unsportsmanlike conduct",
  "points_deducted": 2
}
```

**Sample Response:**
```json
{
  "status": "success",
  "penalty": {
    "id": 1,
    "event_id": 10,
    "team_id": 5,
    "type": "technical_foul",
    ...
  }
}
```

---

### 33. Mark Forfeit
**POST** `/tournaments/{tournamentid}/matches/{match}/forfeit`

**Request Body:**
```json
{
  "winner_team_id": 5,
  "reason": "Opponent did not show up"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "match": {
    "id": 10,
    "status": "forfeited",
    "winner_team_id": 5,
    ...
  }
}
```

---

### 34. Upload Result
**POST** `/tournaments/{tournamentid}/matches/{match}/results`

**Request Body (multipart/form-data):**
```
result_file: <file>
notes: "Official match result"
```

**Sample Response:**
```json
{
  "status": "success",
  "result": {
    "id": 1,
    "event_id": 10,
    "file_path": "tournaments/1/matches/10/results/result.pdf",
    ...
  }
}
```

---

### 35. Dispute Result
**POST** `/tournaments/{tournamentId}/matches/{match}/dispute`

**Request Body:**
```json
{
  "reason": "Score discrepancy",
  "details": "The recorded score does not match our records"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "dispute": {
    "id": 1,
    "match_id": 10,
    "status": "pending",
    ...
  }
}
```

---

### 36. Resolve Dispute
**POST** `/tournaments/{tournamentId}/matches/{match}/resolve-dispute`

**Request Body:**
```json
{
  "resolution": "Score corrected to 85-72",
  "final_score_home": 85,
  "final_score_away": 72
}
```

**Sample Response:**
```json
{
  "status": "success",
  "dispute": {
    "id": 1,
    "status": "resolved",
    ...
  }
}
```

---

## Bracket Management

### 37. Generate Brackets
**POST** `/tournaments/{tournament}/events/{event}/generate-brackets`

**Request Body:**
```json
{
  "bracket_type": "single_elimination",
  "seed_by": "random"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Brackets generated successfully",
  "matchups": [
    {
      "id": 1,
      "round_number": 1,
      "match_number": 1,
      "team_a_id": 5,
      "team_b_id": 6,
      "status": "scheduled"
    }
  ]
}
```

---

### 38. Advance Bracket
**POST** `/tournaments/{tournamentId}/matches/{matchId}/advance`

**Sample Request:**
```bash
POST /api/tournaments/1/matches/10/advance
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Winner advanced to next round",
  "next_match": {
    "id": 15,
    "round_number": 2,
    "match_number": 1,
    ...
  }
}
```

---

## Analytics & Statistics

### 39. Get Analytics
**GET** `/tournaments/{tournamentId}/analytics`

**Sample Request:**
```bash
GET /api/tournaments/1/analytics
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "analytics": {
    "total_participants": 24,
    "total_teams": 8,
    "total_games": 15,
    "completed_games": 10,
    "no_shows": 1,
    "completion_rate": 66.67,
    "average_match_duration": 120
  }
}
```

---

### 40. Get Standings
**GET** `/tournaments/{tournamentId}/standings`

**Sample Response:**
```json
{
  "status": "success",
  "standings": [
    {
      "rank": 1,
      "name": "Team Alpha",
      "wins": 5,
      "losses": 0,
      "draws": 0,
      "points": 15,
      "win_rate": 100.0,
      "matches_played": 5
    },
    {
      "rank": 2,
      "name": "Team Beta",
      "wins": 4,
      "losses": 1,
      "draws": 0,
      "points": 12,
      "win_rate": 80.0,
      "matches_played": 5
    }
  ],
  "count": 8
}
```

---

### 41. Get Leaderboard
**GET** `/tournaments/{tournamentId}/leaderboard`

**Sample Response:**
```json
{
  "status": "success",
  "leaderboard": [
    {
      "rank": 1,
      "name": "Team Alpha",
      "wins": 5,
      "losses": 0,
      "draws": 0,
      "points": 15,
      "win_rate": 100.0,
      "matches_played": 5,
      "match_history": [
        {
          "match_id": 10,
          "opponent": "Team Beta",
          "result": "win",
          "score": "85-72"
        }
      ],
      "stats": {
        "average_points": 82.5,
        "total_points_scored": 412
      }
    }
  ],
  "count": 8
}
```

---

### 42. Get Activity Log
**GET** `/tournaments/{tournamentId}/activity-log`

**Query Parameters:**
- `action` (string, optional) - Filter by action
- `actor_id` (integer, optional) - Filter by actor
- `per_page` (integer, optional) - Results per page (default: 50, max: 100)
- `page` (integer, optional) - Page number

**Sample Request:**
```bash
GET /api/tournaments/1/activity-log?action=participant.approved&per_page=20
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "activity_log": [
    {
      "id": 100,
      "actor_id": 1,
      "actor_type": "user",
      "action": "participant.approved",
      "entity_type": "App\\Models\\TournamentParticipant",
      "entity_id": 25,
      "metadata": {
        "tournament_id": 1,
        "participant_type": "individual"
      },
      "ip": "192.168.1.1",
      "created_at": "2024-05-16T09:00:00.000000Z",
      "actor": {
        "id": 1,
        "username": "organizer1",
        "first_name": "John",
        "last_name": "Doe"
      }
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 45
  }
}
```

---

### 43. Get Spectator Count
**GET** `/tournaments/{tournamentId}/spectator-count`

**Sample Request:**
```bash
GET /api/tournaments/1/spectator-count
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "tournament_id": 1,
  "total_spectators": 0,
  "live_matches_count": 3,
  "matches": [
    {
      "match_id": 10,
      "match_name": "Round 1 - Match 1",
      "spectator_count": 0,
      "viewers": []
    }
  ]
}
```

---

## Organizer Management

### 44. Add Organizer
**POST** `/tournaments/{tournamentId}/organizers`

**Request Body:**
```json
{
  "user_id": 5,
  "role": "organizer"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "organizer": {
    "id": 1,
    "tournament_id": 1,
    "user_id": 5,
    "role": "organizer",
    "user": {
      "id": 5,
      "username": "organizer2",
      ...
    }
  }
}
```

---

### 45. List Organizers
**GET** `/tournaments/{tournamentId}/organizers`

**Sample Response:**
```json
{
  "status": "success",
  "organizers": [
    {
      "id": 1,
      "tournament_id": 1,
      "user_id": 1,
      "role": "owner",
      "user": {...}
    }
  ]
}
```

---

### 46. Update Organizer Role
**PUT** `/tournaments/{tournamentId}/organizers/{userId}/role`

**Request Body:**
```json
{
  "role": "organizer"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "organizer": {
    "id": 1,
    "role": "organizer",
    ...
  }
}
```

---

### 47. Remove Organizer
**DELETE** `/tournaments/{tournamentId}/organizers/{userId}`

**Sample Response:**
```json
{
  "status": "success",
  "message": "Organizer removed"
}
```

---

## Tournament Status

### 48. Open Registration
**POST** `/tournaments/{tournamentId}/open-registration`

**Sample Request:**
```bash
POST /api/tournaments/1/open-registration
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "tournament": {
    "id": 1,
    "status": "open_registration",
    ...
  }
}
```

---

### 49. Close Registration
**POST** `/tournaments/{tournamentId}/close-registration`

**Sample Response:**
```json
{
  "status": "success",
  "tournament": {
    "id": 1,
    "status": "registration_closed",
    ...
  }
}
```

---

### 50. Start Tournament
**POST** `/tournaments/{tournamentId}/start`

**Sample Response:**
```json
{
  "status": "success",
  "tournament": {
    "id": 1,
    "status": "ongoing",
    ...
  }
}
```

---

### 51. Complete Tournament
**POST** `/tournaments/{tournamentId}/complete`

**Sample Response:**
```json
{
  "status": "success",
  "tournament": {
    "id": 1,
    "status": "completed",
    ...
  }
}
```

---

### 52. Cancel Tournament
**POST** `/tournaments/{tournamentId}/cancel`

**Request Body (optional):**
```json
{
  "reason": "Insufficient participants"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "tournament": {
    "id": 1,
    "status": "cancelled",
    "cancelled_at": "2024-05-20T10:00:00.000000Z",
    ...
  }
}
```

---

## Announcements

### 53. Create Announcement
**POST** `/tournaments/{tournamentId}/announcements/create`

**Request Body:**
```json
{
  "title": "Registration Deadline Extended",
  "content": "Registration deadline has been extended to May 30th",
  "priority": "high",
  "is_pinned": true,
  "published_at": "2024-05-20T10:00:00.000000Z"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "announcement": {
    "id": 1,
    "tournament_id": 1,
    "title": "Registration Deadline Extended",
    "content": "...",
    "priority": "high",
    "is_pinned": true,
    "published_at": "2024-05-20T10:00:00.000000Z"
  }
}
```

---

### 54. Get Announcements
**GET** `/tournaments/{tournamentId}/announcements/get`

**Sample Response:**
```json
{
  "status": "success",
  "announcements": [
    {
      "id": 1,
      "title": "Registration Deadline Extended",
      "content": "...",
      "priority": "high",
      "is_pinned": true,
      "published_at": "2024-05-20T10:00:00.000000Z",
      "created_at": "2024-05-20T10:00:00.000000Z"
    }
  ]
}
```

---

### 55. Update Announcement
**PUT** `/tournaments/{tournamentId}/announcements/{announcementId}/put`

**Request Body:** Same as Create Announcement (all fields optional)

**Sample Response:**
```json
{
  "status": "success",
  "announcement": {...}
}
```

---

### 56. Delete Announcement
**DELETE** `/tournaments/{tournamentId}/announcements/{announcementId}/delete`

**Sample Response:**
```json
{
  "status": "success",
  "message": "Announcement deleted"
}
```

---

## Waitlist Management

### 57. Join Waitlist
**POST** `/tournaments/{tournamentId}/waitlist`

**Request Body:**
```json
{
  "participant_type": "individual",
  "user_id": 5
}
```

**Sample Response:**
```json
{
  "status": "success",
  "waitlist_entry": {
    "id": 1,
    "tournament_id": 1,
    "user_id": 5,
    "position": 1,
    "created_at": "2024-05-20T10:00:00.000000Z"
  }
}
```

---

### 58. Get Waitlist
**GET** `/tournaments/{tournamentId}/waitlist`

**Sample Response:**
```json
{
  "status": "success",
  "waitlist": [
    {
      "id": 1,
      "tournament_id": 1,
      "user_id": 5,
      "position": 1,
      "created_at": "2024-05-20T10:00:00.000000Z",
      "user": {...}
    }
  ]
}
```

---

### 59. Remove from Waitlist
**DELETE** `/tournaments/{tournamentId}/waitlist`

**Sample Response:**
```json
{
  "status": "success",
  "message": "Removed from waitlist"
}
```

---

### 60. Promote from Waitlist
**POST** `/tournaments/{tournamentId}/waitlist/promote`

**Request Body:**
```json
{
  "waitlist_id": 1
}
```

**Sample Response:**
```json
{
  "status": "success",
  "participant": {
    "id": 30,
    "status": "approved",
    ...
  },
  "message": "Promoted from waitlist"
}
```

---

## Tournament Phases

### 61. Create Phase
**POST** `/tournaments/{tournamentId}/phases`

**Request Body:**
```json
{
  "name": "Group Stage",
  "description": "Initial group stage matches",
  "start_date": "2024-06-01",
  "end_date": "2024-06-05",
  "order": 1
}
```

**Sample Response:**
```json
{
  "status": "success",
  "phase": {
    "id": 1,
    "tournament_id": 1,
    "name": "Group Stage",
    "order": 1,
    ...
  }
}
```

---

### 62. List Phases
**GET** `/tournaments/{tournamentId}/phases`

**Sample Response:**
```json
{
  "status": "success",
  "phases": [
    {
      "id": 1,
      "name": "Group Stage",
      "order": 1,
      ...
    }
  ]
}
```

---

### 63. Update Phase
**PUT** `/tournaments/{tournamentId}/phases/{phaseId}`

**Request Body:** Same as Create Phase (all fields optional)

**Sample Response:**
```json
{
  "status": "success",
  "phase": {...}
}
```

---

### 64. Delete Phase
**DELETE** `/tournaments/{tournamentId}/phases/{phaseId}`

**Sample Response:**
```json
{
  "status": "success",
  "message": "Phase deleted"
}
```

---

### 65. Reorder Phases
**POST** `/tournaments/{tournamentId}/phases/reorder`

**Request Body:**
```json
{
  "phase_orders": [
    {"phase_id": 1, "order": 1},
    {"phase_id": 2, "order": 2}
  ]
}
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Phases reordered"
}
```

---

## Templates

### 66. Create Template
**POST** `/tournaments/templates`

**Request Body:**
```json
{
  "name": "Standard Basketball Tournament",
  "description": "Template for standard basketball tournament",
  "type": "single_sport",
  "tournament_type": "team vs team",
  "settings": {
    "max_teams": 32,
    "min_teams": 8
  },
  "is_public": false
}
```

**Sample Response:**
```json
{
  "status": "success",
  "template": {
    "id": 1,
    "name": "Standard Basketball Tournament",
    ...
  }
}
```

---

### 67. List Templates
**GET** `/tournaments/templates`

**Query Parameters:**
- `type` (string, optional) - Filter by type
- `tournament_type` (string, optional) - Filter by tournament type

**Sample Response:**
```json
{
  "status": "success",
  "templates": [
    {
      "id": 1,
      "name": "Standard Basketball Tournament",
      "type": "single_sport",
      "is_public": true,
      ...
    }
  ],
  "count": 5
}
```

---

### 68. Create from Template
**POST** `/tournaments/create-from-template/{templateId}`

**Request Body:**
```json
{
  "name": "Summer Championship 2024",
  "start_date": "2024-06-01",
  "end_date": "2024-06-15",
  "registration_deadline": "2024-05-25"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "tournament": {
    "id": 10,
    "name": "Summer Championship 2024",
    ...
  }
}
```

---

### 69. Update Template
**PUT** `/tournaments/templates/{templateId}`

**Request Body:** Same as Create Template (all fields optional)

**Sample Response:**
```json
{
  "status": "success",
  "template": {...}
}
```

---

### 70. Delete Template
**DELETE** `/tournaments/templates/{templateId}`

**Sample Response:**
```json
{
  "status": "success",
  "message": "Template deleted"
}
```

---

## Utility Routes

### 71. Bulk Import Participants
**POST** `/tournaments/{tournamentId}/participants/bulk`

**Request Body (JSON):**
```json
{
  "participants": [
    {
      "participant_type": "individual",
      "user_id": 5
    },
    {
      "participant_type": "team",
      "team_id": 10
    }
  ]
}
```

**OR Request Body (CSV file):**
```
Content-Type: multipart/form-data
file: <csv_file>
```

**Sample Response:**
```json
{
  "status": "success",
  "imported": 20,
  "skipped": 2,
  "errors": []
}
```

---

### 72. Create Invite Link
**POST** `/tournaments/{tournamentId}/invite-link`

**Request Body:**
```json
{
  "expires_at": "2024-05-30T23:59:59.000000Z",
  "max_uses": 100
}
```

**Sample Response:**
```json
{
  "status": "success",
  "invite_link": {
    "token": "abc123xyz",
    "url": "https://app.example.com/tournaments/1/join?token=abc123xyz",
    "expires_at": "2024-05-30T23:59:59.000000Z",
    "max_uses": 100
  }
}
```

---

### 73. Set Participant Lock
**PATCH** `/tournaments/{tournamentId}/lock-participants`

**Request Body:**
```json
{
  "locked": true
}
```

**Sample Response:**
```json
{
  "status": "success",
  "tournament_id": 1,
  "participants_locked": true
}
```

---

### 74. Generate Bracket Preview
**GET** `/tournaments/{tournamentId}/bracket-preview/{eventId}`

**Query Parameters:**
- `type` (string, optional) - Bracket type: `single_elimination`, `double_elimination`
- `persist` (boolean, optional) - Whether to persist the bracket (default: false)

**Sample Response:**
```json
{
  "status": "success",
  "preview": {
    "matchups": [...],
    "rounds": 4,
    "total_matches": 15
  }
}
```

---

### 75. Export Participants
**POST** `/tournaments/{tournamentId}/participants/export`

**Request Body:**
```json
{
  "format": "csv",
  "include_fields": ["name", "email", "status"]
}
```

**Sample Response:** CSV file download

---

### 76. Export Results
**POST** `/tournaments/{tournamentId}/results/export`

**Request Body:**
```json
{
  "format": "csv",
  "include_scores": true
}
```

**Sample Response:** CSV file download

---

### 77. Reset Match
**POST** `/tournaments/{tournamentId}/matches/{matchId}/reset`

**Sample Request:**
```bash
POST /api/tournaments/1/matches/10/reset
Authorization: Bearer {token}
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Match reset successfully"
}
```

---

### 78. Reset Bracket
**POST** `/tournaments/{tournamentId}/bracket/reset/{eventId}`

**Sample Response:**
```json
{
  "status": "success",
  "message": "Bracket reset for event"
}
```

---

## Error Responses

All endpoints may return the following error responses:

### 400 Bad Request
```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": {
    "name": ["The name field is required."]
  }
}
```

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden
```json
{
  "status": "error",
  "message": "Forbidden"
}
```

### 404 Not Found
```json
{
  "status": "error",
  "message": "Tournament not found"
}
```

### 422 Unprocessable Entity
```json
{
  "status": "error",
  "message": "Cannot perform this action",
  "details": "..."
}
```

### 500 Internal Server Error
```json
{
  "status": "error",
  "message": "Failed to process request",
  "error": "Error details"
}
```

---

## Authentication

All protected routes require JWT authentication:

```
Authorization: Bearer {your_jwt_token}
```

To get a token, use the login endpoint:
```
POST /api/login
{
  "email": "user@example.com",
  "password": "password"
}
```

---

## Rate Limiting

- API routes: 60 requests per minute per user/IP
- Admin routes: 60 requests per minute per user/IP
- OTP routes: 3 requests per minute per email/IP

---

## Notes

1. All dates should be in `YYYY-MM-DD` format
2. All times should be in `HH:MM:SS` format (24-hour)
3. File uploads should use `multipart/form-data` content type
4. Pagination defaults: 15 items per page, max 100
5. Tournament status flow: `draft` → `open_registration` → `registration_closed` → `ongoing` → `completed`
6. Participant status: `pending` → `approved` → `confirmed` (or `rejected`/`banned`)
7. Match status: `scheduled` → `in_progress` → `completed` (or `forfeited`/`cancelled`)

---

**Last Updated:** 2024-05-20  
**API Version:** 1.0

