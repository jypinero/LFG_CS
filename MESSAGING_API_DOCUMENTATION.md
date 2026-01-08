# Messaging API Documentation

## Base URL
All messaging endpoints are under: `/api/messaging`

---

## Features

### Core Messaging Features
- ✅ List threads with advanced filtering
- ✅ Get thread details
- ✅ Get messages in a thread
- ✅ Create 1-on-1 conversations
- ✅ Create group chats
- ✅ Send messages
- ✅ Edit messages
- ✅ Delete messages
- ✅ Archive/Unarchive threads
- ✅ Mark messages as read
- ✅ Leave threads
- ✅ Add/Remove participants
- ✅ Update thread title
- ✅ Auto-create team/venue/game threads

### Advanced Filtering
- ✅ Filter by venue
- ✅ Filter by team
- ✅ Filter by thread type
- ✅ Filter by user role/level
- ✅ Filter by specific user (1-on-1)
- ✅ Filter by group/individual
- ✅ Filter by archived status
- ✅ Filter by unread messages
- ✅ Filter by closed status
- ✅ Search by title/participant name
- ✅ Date range filtering
- ✅ Pagination support

---

## Routes

### 1. List Threads (with Filtering)
**GET** `/api/messaging/threads`

**Query Parameters:**
- `venue_id` (integer, optional) - Filter by venue
- `team_id` (integer, optional) - Filter by team
- `type` (string, optional) - Filter by thread type: `one_to_one`, `team`, `venue`, `game_group`, `coach`, `group`
- `participant_role_id` (integer, optional) - Filter by participant's role ID
- `with_user_id` (integer, optional) - Filter 1-on-1 conversations with specific user
- `is_group` (boolean, optional) - Filter group vs individual threads
- `archived` (boolean, optional) - Filter archived threads
- `unread_only` (boolean, optional) - Show only unread threads
- `is_closed` (boolean, optional) - Filter closed threads
- `search` (string, optional) - Search in thread titles and participant names
- `date_from` (date, optional) - Filter by date range (from)
- `date_to` (date, optional) - Filter by date range (to)
- `per_page` (integer, optional, default: 50, max: 100) - Results per page

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
GET /api/messaging/threads?venue_id=5&type=venue&unread_only=true&per_page=20
```

**Sample Response:**
```json
{
  "threads": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "created_by": 1,
      "is_group": true,
      "title": "Sports Complex Chat",
      "type": "venue",
      "venue_id": 5,
      "team_id": null,
      "game_id": null,
      "is_closed": false,
      "closed_at": null,
      "created_at": "2024-01-15T10:00:00.000000Z",
      "updated_at": "2024-01-15T12:30:00.000000Z",
      "latest_message_at": "2024-01-15T12:30:00.000000Z",
      "venue": {
        "id": 5,
        "name": "Sports Complex"
      },
      "team": null,
      "participants": [
        {
          "thread_id": "550e8400-e29b-41d4-a716-446655440000",
          "user_id": 1,
          "role": "owner",
          "joined_at": "2024-01-15T10:00:00.000000Z",
          "left_at": null,
          "last_read_message_id": "msg-123",
          "mute_until": null,
          "notifications": true,
          "archived": false,
          "user": {
            "id": 1,
            "username": "john_doe",
            "first_name": "John",
            "last_name": "Doe",
            "profile_photo": "storage/photos/user1.jpg",
            "role_id": 2,
            "role": {
              "id": 2,
              "name": "Player"
            }
          }
        }
      ],
      "messages": [
        {
          "id": "msg-123",
          "thread_id": "550e8400-e29b-41d4-a716-446655440000",
          "sender_id": 1,
          "body": "Hey everyone!",
          "sent_at": "2024-01-15T12:30:00.000000Z",
          "edited_at": null,
          "deleted_at": null
        }
      ]
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

### 2. Get Thread Details
**GET** `/api/messaging/threads/{threadId}`

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
GET /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000
```

**Sample Response:**
```json
{
  "thread": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "created_by": 1,
    "is_group": true,
    "title": "Team Alpha",
    "type": "team",
    "venue_id": null,
    "team_id": 10,
    "game_id": null,
    "is_closed": false,
    "closed_at": null,
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-01-15T12:30:00.000000Z",
    "participants": [
      {
        "thread_id": "550e8400-e29b-41d4-a716-446655440000",
        "user_id": 1,
        "role": "owner",
        "joined_at": "2024-01-15T10:00:00.000000Z",
        "left_at": null,
        "last_read_message_id": "msg-123",
        "mute_until": null,
        "notifications": true,
        "archived": false,
        "user": {
          "id": 1,
          "username": "john_doe",
          "first_name": "John",
          "last_name": "Doe",
          "profile_photo": "storage/photos/user1.jpg"
        }
      }
    ],
    "creator": {
      "id": 1,
      "username": "john_doe",
      "first_name": "John",
      "last_name": "Doe",
      "profile_photo": "storage/photos/user1.jpg"
    }
  },
  "current_user_role": "owner",
  "current_user_archived": false,
  "current_user_notifications": true,
  "current_user_muted_until": null
}
```

---

### 3. Get Thread Messages
**GET** `/api/messaging/threads/{threadId}/messages`

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
GET /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/messages
```

**Sample Response:**
```json
{
  "messages": [
    {
      "id": "msg-001",
      "thread_id": "550e8400-e29b-41d4-a716-446655440000",
      "sender_id": 1,
      "body": "Hello everyone!",
      "sent_at": "2024-01-15T10:00:00.000000Z",
      "edited_at": null,
      "deleted_at": null
    },
    {
      "id": "msg-002",
      "thread_id": "550e8400-e29b-41d4-a716-446655440000",
      "sender_id": 2,
      "body": "Hey John!",
      "sent_at": "2024-01-15T10:05:00.000000Z",
      "edited_at": null,
      "deleted_at": null
    }
  ]
}
```

---

### 4. Create 1-on-1 Conversation
**POST** `/api/messaging/threads/create-one`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Payload:**
```json
{
  "username": "jane_smith"
}
```

**Sample Request:**
```bash
POST /api/messaging/threads/create-one
Content-Type: application/json

{
  "username": "jane_smith"
}
```

**Sample Response (New Thread):**
```json
{
  "thread": {
    "id": "550e8400-e29b-41d4-a716-446655440001",
    "created_by": 1,
    "is_group": false,
    "title": null,
    "type": "one_to_one",
    "venue_id": null,
    "team_id": null,
    "game_id": null,
    "is_closed": false,
    "closed_at": null,
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-01-15T10:00:00.000000Z"
  },
  "status": "created"
}
```

**Sample Response (Existing Thread):**
```json
{
  "thread": {
    "id": "550e8400-e29b-41d4-a716-446655440001",
    "created_by": 1,
    "is_group": false,
    "title": null,
    "type": "one_to_one",
    "venue_id": null,
    "team_id": null,
    "game_id": null,
    "is_closed": false,
    "closed_at": null,
    "created_at": "2024-01-10T08:00:00.000000Z",
    "updated_at": "2024-01-15T10:00:00.000000Z"
  },
  "status": "exists"
}
```

---

### 5. Create Group Chat
**POST** `/api/messaging/threads/create-group`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Payload:**
```json
{
  "title": "Project Discussion",
  "participants": ["jane_smith", "bob_wilson", "alice_brown"],
  "type": "group"
}
```

**Sample Request:**
```bash
POST /api/messaging/threads/create-group
Content-Type: application/json

{
  "title": "Project Discussion",
  "participants": ["jane_smith", "bob_wilson", "alice_brown"],
  "type": "group"
}
```

**Sample Response:**
```json
{
  "thread": {
    "id": "550e8400-e29b-41d4-a716-446655440002",
    "created_by": 1,
    "is_group": true,
    "title": "Project Discussion",
    "type": "group",
    "venue_id": null,
    "team_id": null,
    "game_id": null,
    "is_closed": false,
    "closed_at": null,
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-01-15T10:00:00.000000Z"
  },
  "status": "created"
}
```

---

### 6. Send Message
**POST** `/api/messaging/threads/{threadId}/messages`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Payload:**
```json
{
  "body": "Hello everyone! How's it going?"
}
```

**Sample Request:**
```bash
POST /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/messages
Content-Type: application/json

{
  "body": "Hello everyone! How's it going?"
}
```

**Sample Response:**
```json
{
  "message": {
    "id": "msg-003",
    "thread_id": "550e8400-e29b-41d4-a716-446655440000",
    "sender_id": 1,
    "body": "Hello everyone! How's it going?",
    "sent_at": "2024-01-15T12:30:00.000000Z",
    "edited_at": null,
    "deleted_at": null
  }
}
```

---

### 7. Edit Message
**PUT** `/api/messaging/threads/{threadId}/messages/{messageId}`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Payload:**
```json
{
  "body": "Hello everyone! How's everyone doing?"
}
```

**Sample Request:**
```bash
PUT /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/messages/msg-003
Content-Type: application/json

{
  "body": "Hello everyone! How's everyone doing?"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "message": {
    "id": "msg-003",
    "thread_id": "550e8400-e29b-41d4-a716-446655440000",
    "sender_id": 1,
    "body": "Hello everyone! How's everyone doing?",
    "sent_at": "2024-01-15T12:30:00.000000Z",
    "edited_at": "2024-01-15T12:35:00.000000Z",
    "deleted_at": null
  }
}
```

---

### 8. Delete Message
**DELETE** `/api/messaging/threads/{threadId}/messages/{messageId}`

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
DELETE /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/messages/msg-003
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Message deleted successfully"
}
```

---

### 9. Archive Thread
**POST** `/api/messaging/threads/{threadId}/archive`

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
POST /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/archive
```

**Sample Response:**
```json
{
  "status": "archived"
}
```

---

### 10. Unarchive Thread
**POST** `/api/messaging/threads/{threadId}/unarchive`

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
POST /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/unarchive
```

**Sample Response:**
```json
{
  "status": "unarchived"
}
```

---

### 11. Leave Thread
**POST** `/api/messaging/threads/{threadId}/leave`

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
POST /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/leave
```

**Sample Response:**
```json
{
  "status": "left"
}
```

---

### 12. Mark Thread as Read
**POST** `/api/messaging/threads/{threadId}/read`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Payload:**
```json
{
  "last_read_message_id": "msg-003"
}
```

**Sample Request:**
```bash
POST /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/read
Content-Type: application/json

{
  "last_read_message_id": "msg-003"
}
```

**Sample Response:**
```json
{
  "status": "ok"
}
```

---

### 13. Update Thread Title
**PUT** `/api/messaging/threads/{threadId}/title`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Payload:**
```json
{
  "title": "Updated Group Name"
}
```

**Sample Request:**
```bash
PUT /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/title
Content-Type: application/json

{
  "title": "Updated Group Name"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Thread title updated",
  "thread": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "title": "Updated Group Name",
    "is_group": true,
    "type": "group",
    "updated_at": "2024-01-15T12:40:00.000000Z"
  }
}
```

---

### 14. Add Participant
**POST** `/api/messaging/threads/{threadId}/participants`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Payload (Option 1 - by user_id):**
```json
{
  "user_id": 5
}
```

**Payload (Option 2 - by username):**
```json
{
  "username": "jane_smith"
}
```

**Sample Request:**
```bash
POST /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/participants
Content-Type: application/json

{
  "username": "jane_smith"
}
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Participant added successfully",
  "thread": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "is_group": true,
    "participants": [
      {
        "thread_id": "550e8400-e29b-41d4-a716-446655440000",
        "user_id": 1,
        "role": "owner",
        "user": {
          "id": 1,
          "username": "john_doe",
          "first_name": "John",
          "last_name": "Doe",
          "profile_photo": "storage/photos/user1.jpg"
        }
      },
      {
        "thread_id": "550e8400-e29b-41d4-a716-446655440000",
        "user_id": 3,
        "role": "member",
        "user": {
          "id": 3,
          "username": "jane_smith",
          "first_name": "Jane",
          "last_name": "Smith",
          "profile_photo": "storage/photos/user3.jpg"
        }
      }
    ]
  }
}
```

---

### 15. Remove Participant
**DELETE** `/api/messaging/threads/{threadId}/participants/{participantUserId}`

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
DELETE /api/messaging/threads/550e8400-e29b-41d4-a716-446655440000/participants/3
```

**Sample Response:**
```json
{
  "status": "success",
  "message": "Participant removed successfully"
}
```

---

### 16. Auto-Create Team Thread
**POST** `/api/messaging/auto/team/{teamId}`

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
POST /api/messaging/auto/team/10
```

**Sample Response:**
```json
{
  "thread": {
    "id": "550e8400-e29b-41d4-a716-446655440003",
    "created_by": 1,
    "is_group": true,
    "title": "Team Alpha",
    "type": "team",
    "venue_id": null,
    "team_id": 10,
    "game_id": null,
    "is_closed": false,
    "closed_at": null,
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-01-15T10:00:00.000000Z"
  },
  "status": "created"
}
```

---

### 17. Auto-Create Venue Thread
**POST** `/api/messaging/auto/venue/{venueId}`

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
POST /api/messaging/auto/venue/5
```

**Sample Response:**
```json
{
  "thread": {
    "id": "550e8400-e29b-41d4-a716-446655440004",
    "created_by": 1,
    "is_group": true,
    "title": "Sports Complex",
    "type": "venue",
    "venue_id": 5,
    "team_id": null,
    "game_id": null,
    "is_closed": false,
    "closed_at": null,
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-01-15T10:00:00.000000Z"
  },
  "status": "created"
}
```

---

### 18. Auto-Create Game Thread
**POST** `/api/messaging/auto/game/{eventId}`

**Headers:**
```
Authorization: Bearer {token}
```

**Sample Request:**
```bash
POST /api/messaging/auto/game/25
```

**Sample Response:**
```json
{
  "thread": {
    "id": "550e8400-e29b-41d4-a716-446655440005",
    "created_by": 1,
    "is_group": true,
    "title": "Basketball Match - Jan 20",
    "type": "game_group",
    "venue_id": null,
    "team_id": null,
    "game_id": 25,
    "is_closed": false,
    "closed_at": null,
    "created_at": "2024-01-15T10:00:00.000000Z",
    "updated_at": "2024-01-15T10:00:00.000000Z"
  },
  "status": "created"
}
```

---

## Filter Examples

### Example 1: Get all venue-related threads
```bash
GET /api/messaging/threads?type=venue
```

### Example 2: Get unread venue threads for a specific venue
```bash
GET /api/messaging/threads?venue_id=5&type=venue&unread_only=true
```

### Example 3: Get threads with participants of a specific role
```bash
GET /api/messaging/threads?participant_role_id=2
```

### Example 4: Get 1-on-1 conversation with specific user
```bash
GET /api/messaging/threads?with_user_id=5&is_group=false
```

### Example 5: Get archived group chats
```bash
GET /api/messaging/threads?archived=true&is_group=true
```

### Example 6: Search for threads
```bash
GET /api/messaging/threads?search=john
```

### Example 7: Get threads from date range
```bash
GET /api/messaging/threads?date_from=2024-01-01&date_to=2024-01-31
```

### Example 8: Combined filters
```bash
GET /api/messaging/threads?type=venue&venue_id=5&unread_only=true&archived=false&per_page=20
```

---

## Error Responses

### 401 Unauthorized
```json
{
  "message": "Unauthenticated"
}
```

### 403 Forbidden
```json
{
  "message": "Not a participant"
}
```

### 404 Not Found
```json
{
  "message": "User not found"
}
```

### 422 Validation Error
```json
{
  "message": "Validation failed",
  "errors": {
    "body": ["The body field is required."]
  }
}
```

---

## Notes

1. All endpoints require authentication via Bearer token
2. Thread IDs are UUIDs (string format)
3. Message IDs are UUIDs (string format)
4. Pagination defaults to 50 items per page, max 100
5. Filtering is case-insensitive for search terms
6. Date filters use ISO 8601 format (YYYY-MM-DD)
7. Boolean filters accept: `true`, `false`, `1`, `0`, `yes`, `no`
8. Threads are ordered by latest message timestamp (most recent first)
9. Deleted messages are excluded from responses
10. Participants who left (`left_at` is not null) are excluded from thread lists








