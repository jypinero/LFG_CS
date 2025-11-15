# Team Management API Documentation

## Base URL
```
http://127.0.0.1:8000/api
```

## Authentication
All endpoints require JWT authentication:
```
Authorization: Bearer {token}
Accept: application/json
```

---

## 1. Core Team Operations

### 1.1 List All Teams
**Endpoint:** `GET /teams`

**Request:**
```bash
GET /api/teams
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "teams": [
      {
        "id": 1,
        "name": "Sharks Unlimited",
        "created_by": 5,
        "team_photo": "http://127.0.0.1:8000/storage/team_photos/...",
        "certification": null,
        "certified": 0,
        "team_type": "collegiate",
        "address_line": "Lyceum of Subic Bay",
        "latitude": "14.82388433",
        "longitude": "120.27901381",
        "sport_id": null,
        "sport": null,
        "bio": null,
        "roster_size_limit": null,
        "created_at": "2025-10-16T17:22:21.000000Z",
        "updated_at": "2025-10-22T14:39:47.000000Z",
        "creator": {
          "id": 5,
          "name": "Luce01",
          "email": "lrepublo@lsb.edu.ph"
        }
      }
    ]
  }
}
```

**Frontend Component:** Team list/grid view
**Button:** None (auto-load on page load)

---

### 1.2 Create Team
**Endpoint:** `POST /teams/create`

**Request:**
```bash
POST /api/teams/create
Content-Type: multipart/form-data (for file upload) OR application/json
Headers: Authorization: Bearer {token}
```

**Payload (JSON):**
```json
{
  "name": "Warriors Basketball",
  "sport_id": 1,
  "team_type": "competitive",
  "certified": false,
  "bio": "A competitive basketball team",
  "address_line": "456 Oak Ave, San Francisco, CA",
  "latitude": 37.7749,
  "longitude": -122.4194,
  "roster_size_limit": 12
}
```

**Payload (FormData for photo upload):**
```javascript
const formData = new FormData();
formData.append('name', 'Warriors Basketball');
formData.append('sport_id', 1);
formData.append('team_type', 'competitive');
formData.append('bio', 'A competitive basketball team');
formData.append('team_photo', fileInput.files[0]);
formData.append('address_line', '456 Oak Ave');
formData.append('latitude', 37.7749);
formData.append('longitude', -122.4194);
formData.append('roster_size_limit', 12);
```

**Response:**
```json
{
  "status": "success",
  "message": "Team created",
  "team": {
    "id": 4,
    "name": "Warriors Basketball",
    "sport_id": 1,
    "created_by": 2,
    "team_photo": "team_photos/1234567891_warriors.jpg",
    "bio": "A competitive basketball team",
    "roster_size_limit": 12,
    "created_at": "2024-01-20T12:00:00.000000Z"
  },
  "creator_member": {
    "id": 20,
    "team_id": 4,
    "user_id": 2,
    "role": "owner",
    "is_active": true,
    "roster_status": "active"
  }
}
```

**Frontend Component:** CreateTeamModal / CreateTeamForm
**Button:** "Create Team" button in teams page header

---

### 1.3 Update Team
**Endpoint:** `PATCH /teams/{teamId}`

**Request:**
```bash
PATCH /api/teams/1
Content-Type: multipart/form-data OR application/json
Headers: Authorization: Bearer {token}
```

**Payload:**
```json
{
  "name": "Updated Team Name",
  "sport_id": 1,
  "team_type": "professional",
  "bio": "Updated team bio",
  "address_line": "789 New St",
  "latitude": 34.052236,
  "longitude": -118.243684,
  "roster_size_limit": 15
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Team updated",
  "team": {
    "id": 1,
    "name": "Updated Team Name",
    "sport_id": 1,
    "bio": "Updated team bio",
    "roster_size_limit": 15,
    "updated_at": "2024-01-20T15:00:00.000000Z"
  }
}
```

**Frontend Component:** EditTeamModal / TeamSettingsPage
**Button:** "Edit Team" button (owner/captain only)

---

### 1.4 Delete Team
**Endpoint:** `DELETE /teams/{teamId}`

**Request:**
```bash
DELETE /api/teams/1
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "message": "Team deleted successfully"
}
```

**Frontend Component:** DeleteTeamModal
**Button:** "Delete Team" button (owner only, with confirmation)

---

## 2. Team Members Management

### 2.1 Get Team Members
**Endpoint:** `GET /teams/{teamId}/members`

**Request:**
```bash
GET /api/teams/1/members
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "team": {
    "id": 1,
    "name": "Sharks Unlimited",
    "created_by": 5,
    "team_photo": "http://127.0.0.1:8000/storage/team_photos/...",
    "certification": null,
    "certified": 0,
    "team_type": "collegiate",
    "address_line": "Lyceum of Subic Bay",
    "latitude": "14.82388433",
    "longitude": "120.27901381",
    "created_at": "2025-10-16T17:22:21.000000Z",
    "updated_at": "2025-10-22T14:39:47.000000Z",
    "creator": {
      "id": 5,
      "username": "Luce01",
      "email": "lrepublo@lsb.edu.ph"
    }
  },
  "members": [
    {
      "id": 8,
      "user_id": 5,
      "username": "Luce01",
      "email": "lrepublo@lsb.edu.ph",
      "profile_photo": "http://127.0.0.1:8000/storage/photos/...",
      "role": "owner",
      "joined_at": "2025-10-22T14:39:47.000000Z"
    }
  ]
}
```

**Frontend Component:** TeamMembersList
**Button:** None (auto-load in team detail page)

---

### 2.2 Add Member
**Endpoint:** `POST /teams/{teamId}/addmembers`

**Request:**
```bash
POST /api/teams/1/addmembers
Content-Type: application/json
Headers: Authorization: Bearer {token}
```

**Payload:**
```json
{
  "username": "john_doe",
  "role": "member"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Member added",
  "member": {
    "id": 12,
    "team_id": 1,
    "user_id": 8,
    "role": "member",
    "is_active": true,
    "roster_status": "active",
    "joined_at": "2024-01-20T10:00:00.000000Z"
  }
}
```

**Error Response (Roster Limit):**
```json
{
  "status": "error",
  "message": "Cannot add member: Roster size limit reached (12/12 active)"
}
```

**Frontend Component:** AddMemberModal
**Button:** "Add Member" button (owner only)

---

### 2.3 Update Member Role
**Endpoint:** `PATCH /teams/{teamId}/members/{memberId}/role`

**Request:**
```bash
PATCH /api/teams/1/members/12/role
Content-Type: application/json
Headers: Authorization: Bearer {token}
```

**Payload:**
```json
{
  "role": "captain"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Member role updated",
  "member": {
    "id": 12,
    "role": "captain",
    "updated_at": "2024-01-20T17:00:00.000000Z"
  }
}
```

**Frontend Component:** MemberRoleDropdown / EditMemberModal
**Button:** Role dropdown in member card (owner only)

---

### 2.4 Remove Member
**Endpoint:** `DELETE /teams/{teamId}/members/{memberId}`

**Request:**
```bash
DELETE /api/teams/1/members/12
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "message": "Member removed"
}
```

**Frontend Component:** RemoveMemberModal
**Button:** "Remove" button in member card (owner/captain/manager only)

---

### 2.5 Leave Team
**Endpoint:** `POST /teams/{teamId}/leave`

**Request:**
```bash
POST /api/teams/1/leave
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "message": "Left team successfully"
}
```

**Frontend Component:** LeaveTeamModal
**Button:** "Leave Team" button (members only, not owner)

---

## 3. Roster Management

### 3.1 Get Roster
**Endpoint:** `GET /teams/{teamId}/roster`

**Request:**
```bash
GET /api/teams/1/roster
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "team": {
    "id": 1,
    "name": "Sharks Unlimited",
    "roster_size_limit": 12
  },
  "roster": {
    "active": [
      {
        "id": 8,
        "user_id": 5,
        "username": "Luce01",
        "email": "lrepublo@lsb.edu.ph",
        "role": "owner",
        "position": "starter",
        "roster_status": "active",
        "joined_at": "2025-10-22T14:39:47.000000Z"
      }
    ],
    "inactive": [
      {
        "id": 1,
        "user_id": 2,
        "username": "jypinero",
        "role": "member",
        "position": "bench",
        "roster_status": "injured",
        "joined_at": "2025-10-16T17:22:21.000000Z"
      }
    ],
    "total_active": 1,
    "total_inactive": 1,
    "available_slots": 11
  }
}
```

**Frontend Component:** RosterManagementView
**Button:** "View Roster" tab in team detail page

---

### 3.2 Update Roster Status/Position
**Endpoint:** `PATCH /teams/{teamId}/members/{memberId}/roster`

**Request:**
```bash
PATCH /api/teams/1/members/12/roster
Content-Type: application/json
Headers: Authorization: Bearer {token}
```

**Payload:**
```json
{
  "is_active": true,
  "position": "starter",
  "roster_status": "active"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Roster updated",
  "member": {
    "id": 12,
    "is_active": true,
    "position": "starter",
    "roster_status": "active"
  }
}
```

**Error Response (Roster Limit):**
```json
{
  "status": "error",
  "message": "Cannot activate member: Roster size limit reached (12/12 active)"
}
```

**Frontend Component:** RosterMemberCard with status dropdowns
**Button:** Status/Position dropdowns (owner/captain only)

---

### 3.3 Set Roster Size Limit
**Endpoint:** `PATCH /teams/{teamId}/roster-limit`

**Request:**
```bash
PATCH /api/teams/1/roster-limit
Content-Type: application/json
Headers: Authorization: Bearer {token}
```

**Payload:**
```json
{
  "roster_size_limit": 15
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Roster size limit updated",
  "team": {
    "id": 1,
    "name": "Sharks Unlimited",
    "roster_size_limit": 15,
    "current_active_count": 5
  }
}
```

**Frontend Component:** RosterSettingsModal
**Button:** "Set Roster Limit" button in roster management (owner/captain only)

---

## 4. Join Requests

### 4.1 Request to Join Team
**Endpoint:** `POST /teams/{teamId}/request-join`

**Request:**
```bash
POST /api/teams/1/request-join
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "message": "Join request sent. Waiting for approval.",
  "member": {
    "id": 14,
    "team_id": 1,
    "user_id": 11,
    "role": "pending",
    "joined_at": "2024-01-20T18:00:00.000000Z"
  }
}
```

**Frontend Component:** RequestJoinButton
**Button:** "Request to Join" button in team detail page (non-members only)

---

### 4.2 Get Pending Requests
**Endpoint:** `GET /teams/{teamId}/requests/pending`

**Request:**
```bash
GET /api/teams/1/requests/pending
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "pending_requests": [
      {
        "id": 15,
        "user_id": 12,
        "username": "new_user",
        "email": "newuser@example.com",
        "profile_photo": "http://127.0.0.1:8000/storage/photos/...",
        "requested_at": "2024-01-20T20:00:00.000000Z",
        "user_joined_platform": "2024-01-10T08:00:00.000000Z"
      }
    ],
    "total_pending": 1
  }
}
```

**Frontend Component:** PendingRequestsList
**Button:** "View Requests" badge/button (owner only, shows count)

---

### 4.3 Handle Join Request
**Endpoint:** `POST /teams/{teamId}/requests/{memberId}/handle`

**Request:**
```bash
POST /api/teams/1/requests/15/handle
Content-Type: application/json
Headers: Authorization: Bearer {token}
```

**Payload:**
```json
{
  "action": "accept"
}
```
or
```json
{
  "action": "decline"
}
```

**Response (Accept):**
```json
{
  "status": "success",
  "message": "Request accepted",
  "member": {
    "id": 15,
    "role": "member",
    "is_active": true,
    "roster_status": "active"
  }
}
```

**Response (Decline):**
```json
{
  "status": "success",
  "message": "Request declined"
}
```

**Frontend Component:** JoinRequestCard
**Button:** "Accept" and "Decline" buttons in pending request card (owner only)

---

### 4.4 Handle Bulk Requests
**Endpoint:** `POST /teams/{teamId}/requests/handle-bulk`

**Request:**
```bash
POST /api/teams/1/requests/handle-bulk
Content-Type: application/json
Headers: Authorization: Bearer {token}
```

**Payload:**
```json
{
  "requests": [
    {
      "member_id": 15,
      "action": "accept"
    },
    {
      "member_id": 16,
      "action": "decline"
    }
  ]
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Processed 2 requests successfully, 0 failed",
  "results": [
    {
      "member_id": 15,
      "user_id": 12,
      "status": "success",
      "action": "accepted",
      "message": "Request accepted"
    },
    {
      "member_id": 16,
      "user_id": 13,
      "status": "success",
      "action": "declined",
      "message": "Request declined"
    }
  ],
  "summary": {
    "total_processed": 2,
    "successful": 2,
    "failed": 0
  }
}
```

**Frontend Component:** BulkActionsToolbar
**Button:** "Accept All Selected" / "Decline All Selected" buttons (owner only)

---

### 4.5 Get Request History
**Endpoint:** `GET /teams/{teamId}/requests/history`

**Request:**
```bash
GET /api/teams/1/requests/history
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "data": {
    "request_history": [
      {
        "notification_id": 25,
        "user_id": 11,
        "username": "john_doe",
        "email": "john@example.com",
        "profile_photo": "http://127.0.0.1:8000/storage/photos/...",
        "message": "john_doe requested to join your team",
        "action_state": "accept",
        "created_at": "2024-01-20T18:00:00.000000Z",
        "handled_at": "2024-01-20T19:00:00.000000Z"
      }
    ]
  }
}
```

**Frontend Component:** RequestHistoryTab
**Button:** "History" tab in join requests section (owner only)

---

### 4.6 Cancel Join Request
**Endpoint:** `POST /teams/{teamId}/request-cancel`

**Request:**
```bash
POST /api/teams/1/request-cancel
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "message": "Join request cancelled successfully"
}
```

**Frontend Component:** CancelRequestButton
**Button:** "Cancel Request" button (for users who sent request)

---

## 5. Invite Links

### 5.1 Generate Invite Link
**Endpoint:** `POST /teams/{teamId}/invites/generate`

**Request:**
```bash
POST /api/teams/1/invites/generate
Content-Type: application/json
Headers: Authorization: Bearer {token}
```

**Payload:**
```json
{
  "role": "member",
  "expires_at": "2024-02-01T23:59:59.000000Z"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Invite link generated",
  "invite": {
    "id": 5,
    "team_id": 1,
    "token": "abc123xyz789def456",
    "role": "member",
    "invite_url": "http://127.0.0.1:8000/teams/invite/abc123xyz789def456",
    "expires_at": "2024-02-01T23:59:59.000000Z",
    "created_at": "2024-01-20T10:00:00.000000Z"
  }
}
```

**Frontend Component:** GenerateInviteModal
**Button:** "Generate Invite Link" button (owner/captain/manager only)

---

### 5.2 Accept Invite
**Endpoint:** `POST /teams/invites/{token}/accept`

**Request:**
```bash
POST /api/teams/invites/abc123xyz789def456/accept
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "message": "Invite accepted successfully",
  "member": {
    "id": 15,
    "team_id": 1,
    "user_id": 11,
    "role": "member",
    "is_active": true,
    "roster_status": "active"
  },
  "invite": {
    "id": 5,
    "used_at": "2024-01-20T10:15:00.000000Z",
    "used_by": 11
  }
}
```

**Error Response (Already Used):**
```json
{
  "status": "error",
  "message": "Invite token has already been used"
}
```

**Error Response (Expired):**
```json
{
  "status": "error",
  "message": "Invite token has expired"
}
```

**Frontend Component:** AcceptInvitePage (public route)
**Button:** "Accept Invite" button on invite landing page

---

### 5.3 List Invites
**Endpoint:** `GET /teams/{teamId}/invites`

**Request:**
```bash
GET /api/teams/1/invites
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "team": {
    "id": 1,
    "name": "Sharks Unlimited"
  },
  "invites": [
    {
      "id": 5,
      "token": "abc123xyz789def456",
      "role": "member",
      "invite_url": "http://127.0.0.1:8000/teams/invite/abc123xyz789def456",
      "expires_at": "2024-02-01T23:59:59.000000Z",
      "created_by": {
        "id": 5,
        "username": "Luce01"
      },
      "created_at": "2024-01-20T10:00:00.000000Z",
      "used_at": null,
      "is_expired": false
    }
  ]
}
```

**Frontend Component:** InvitesList
**Button:** "View Invites" button (owner/captain/manager only)

---

### 5.4 Revoke Invite
**Endpoint:** `DELETE /teams/{teamId}/invites/{inviteId}`

**Request:**
```bash
DELETE /api/teams/1/invites/5
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "message": "Invite revoked successfully"
}
```

**Frontend Component:** InviteCard
**Button:** "Revoke" button in invite card (owner/captain/manager only)

---

## 6. Certification Verification

### 6.1 Upload Certification Document
**Endpoint:** `POST /teams/{teamId}/certification/upload`

**Request:**
```bash
POST /api/teams/1/certification/upload
Content-Type: multipart/form-data
Headers: Authorization: Bearer {token}
```

**Payload (FormData):**
```javascript
const formData = new FormData();
formData.append('certification_document', fileInput.files[0]);
```

**Response:**
```json
{
  "status": "success",
  "message": "Certification document uploaded",
  "team": {
    "id": 1,
    "certification_document": "http://127.0.0.1:8000/storage/certifications/...",
    "certification_status": "pending"
  }
}
```

**Frontend Component:** UploadCertificationModal
**Button:** "Upload Certification" button (owner/captain only)

---

### 6.2 Trigger AI Verification
**Endpoint:** `POST /teams/{teamId}/certification/verify-ai`

**Request:**
```bash
POST /api/teams/1/certification/verify-ai
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "message": "AI verification completed",
  "verification": {
    "status": "under_review",
    "confidence": 0.85,
    "notes": "Document analyzed. Contains Philippine pro league certification patterns. Requires admin review for final verification."
  }
}
```

**Frontend Component:** VerifyCertificationButton
**Button:** "Verify with AI" button (owner/captain only, after upload)

---

### 6.3 Get Certification Status
**Endpoint:** `GET /teams/{teamId}/certification/status`

**Request:**
```bash
GET /api/teams/1/certification/status
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "certification": {
    "certified": 0,
    "certification": null,
    "certification_document": null,
    "certification_status": null,
    "certification_verified_at": null,
    "certification_ai_confidence": null,
    "certification_ai_notes": null,
    "verified_by": null
  }
}
```

**Frontend Component:** CertificationStatusBadge
**Button:** None (display status badge in team header)

---

## 7. Team Events

### 7.1 Get Team Events
**Endpoint:** `GET /teams/{teamId}/events`

**Request:**
```bash
GET /api/teams/1/events
Headers: Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "events": [
    {
      "id": 1,
      "name": "Basketball Tournament",
      "description": "Annual tournament",
      "date": "2024-02-15",
      "start_time": "10:00:00",
      "end_time": "18:00:00",
      "sport": "Basketball",
      "venue": {
        "id": 1,
        "name": "Main Arena"
      },
      "status": "upcoming"
    }
  ]
}
```

**Frontend Component:** TeamEventsList
**Button:** "View Events" tab in team detail page

---

## 8. Ownership Management

### 8.1 Transfer Ownership
**Endpoint:** `POST /teams/{teamId}/transfer-ownership`

**Request:**
```bash
POST /api/teams/1/transfer-ownership
Content-Type: application/json
Headers: Authorization: Bearer {token}
```

**Payload:**
```json
{
  "new_owner_id": 8
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Ownership transferred",
  "team": {
    "id": 1,
    "created_by": 8,
    "updated_at": "2024-01-20T16:00:00.000000Z"
  }
}
```

**Frontend Component:** TransferOwnershipModal
**Button:** "Transfer Ownership" button (owner only, in team settings)

---

## Frontend Integration Checklist

### Components Needed:
1. **TeamList** - Display all teams with filters
2. **TeamCard** - Individual team card component
3. **CreateTeamModal** - Form to create new team
4. **EditTeamModal** - Form to edit team details
5. **DeleteTeamModal** - Confirmation modal for deletion
6. **TeamMembersList** - List of team members
7. **MemberCard** - Individual member card with actions
8. **AddMemberModal** - Form to add member by username
9. **RemoveMemberModal** - Confirmation modal
10. **LeaveTeamModal** - Confirmation modal
11. **RosterManagementView** - Roster with active/inactive sections
12. **RosterMemberCard** - Member card with position/status dropdowns
13. **RosterSettingsModal** - Set roster size limit
14. **RequestJoinButton** - Button to request joining
15. **PendingRequestsList** - List of pending join requests
16. **JoinRequestCard** - Card with accept/decline buttons
17. **BulkActionsToolbar** - Toolbar for bulk accept/decline
18. **RequestHistoryTab** - History of all join requests
19. **CancelRequestButton** - Button to cancel own request
20. **GenerateInviteModal** - Form to generate invite link
21. **InvitesList** - List of active invites
22. **InviteCard** - Card with copy link and revoke button
23. **AcceptInvitePage** - Public page to accept invite
24. **UploadCertificationModal** - File upload for certification
25. **VerifyCertificationButton** - Button to trigger AI verification
26. **CertificationStatusBadge** - Status indicator badge
27. **TeamEventsList** - List of team events
28. **TransferOwnershipModal** - Form to transfer ownership

### Permission Checks:
- **Owner**: Full access to all features
- **Captain**: Can edit team, manage roster, handle requests, generate invites
- **Manager**: Can remove members, generate invites
- **Member**: Can view team, leave team, request to join (if not member)
- **Non-member**: Can view public team info, request to join

### State Management:
- Store team data in Redux/Zustand/Context
- Cache team members list
- Track pending requests count
- Manage invite links state
- Track certification status

### Error Handling:
- Handle 403 Forbidden (show permission message)
- Handle 404 Not Found (show not found message)
- Handle 409 Conflict (show conflict message with details)
- Handle 422 Validation errors (show field-specific errors)
- Handle roster limit errors (show limit reached message)

---

## Sample Frontend API Service

```javascript
// teamApi.js
const API_BASE = 'http://127.0.0.1:8000/api';

export const teamApi = {
  // Get all teams
  getAllTeams: async (token) => {
    const response = await fetch(`${API_BASE}/teams`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    });
    return response.json();
  },

  // Create team
  createTeam: async (token, formData) => {
    const response = await fetch(`${API_BASE}/teams/create`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      },
      body: formData
    });
    return response.json();
  },

  // Update team
  updateTeam: async (token, teamId, data) => {
    const response = await fetch(`${API_BASE}/teams/${teamId}`, {
      method: 'PATCH',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(data)
    });
    return response.json();
  },

  // Get roster
  getRoster: async (token, teamId) => {
    const response = await fetch(`${API_BASE}/teams/${teamId}/roster`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    });
    return response.json();
  },

  // Update roster
  updateRoster: async (token, teamId, memberId, data) => {
    const response = await fetch(`${API_BASE}/teams/${teamId}/members/${memberId}/roster`, {
      method: 'PATCH',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(data)
    });
    return response.json();
  },

  // Generate invite
  generateInvite: async (token, teamId, data) => {
    const response = await fetch(`${API_BASE}/teams/${teamId}/invites/generate`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(data)
    });
    return response.json();
  },

  // Accept invite
  acceptInvite: async (token, inviteToken) => {
    const response = await fetch(`${API_BASE}/teams/invites/${inviteToken}/accept`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    });
    return response.json();
  },

  // Upload certification
  uploadCertification: async (token, teamId, formData) => {
    const response = await fetch(`${API_BASE}/teams/${teamId}/certification/upload`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      },
      body: formData
    });
    return response.json();
  },

  // Verify certification AI
  verifyCertificationAI: async (token, teamId) => {
    const response = await fetch(`${API_BASE}/teams/${teamId}/certification/verify-ai`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    });
    return response.json();
  }
};
```

---

## Notes

1. **File Uploads**: Use `FormData` for team_photo and certification_document uploads
2. **Token Management**: Store JWT token securely and refresh when expired
3. **Error Handling**: Always check response status and handle errors appropriately
4. **Loading States**: Show loading indicators during API calls
5. **Optimistic Updates**: Update UI optimistically for better UX
6. **Pagination**: Consider adding pagination for teams list if many teams exist
7. **Real-time Updates**: Consider WebSocket for real-time join request notifications

