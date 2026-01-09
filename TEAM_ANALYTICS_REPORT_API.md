# Team Analytics Report API - Usage Guide

## Overview
The Team Analytics Report API provides comprehensive team data in a structured format designed for frontend formatting and printing. The frontend is responsible for all formatting and styling of the report.

## Endpoint

```
GET /api/teams/{teamId}/analytics/report
```

## Authentication
- **Required**: Yes (JWT Token)
- **Header**: `Authorization: Bearer {YOUR_JWT_TOKEN}`

## Authorization
Access is restricted to:
- Team **Owner** (user who created the team)
- Team **Captain** (member with `role = 'captain'`)
- Team **Manager** (member with `role = 'manager'`)

## Request Example

### JavaScript/Fetch
```javascript
const teamId = 123;
const token = 'YOUR_JWT_TOKEN';

const response = await fetch(`/api/teams/${teamId}/analytics/report`, {
  method: 'GET',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  }
});

const reportData = await response.json();
```

### Axios
```javascript
import axios from 'axios';

const teamId = 123;
const token = 'YOUR_JWT_TOKEN';

const response = await axios.get(`/api/teams/${teamId}/analytics/report`, {
  headers: {
    'Authorization': `Bearer ${token}`
  }
});

const reportData = response.data;
```

### cURL
```bash
curl -X GET "https://your-api-domain.com/api/teams/123/analytics/report" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Accept: application/json"
```

## Response Structure

### Success Response (200)
```json
{
  "status": "success",
  "report_generated_at": "2024-01-15T10:30:00.000000Z",
  "team": {
    "id": 123,
    "name": "Team Name",
    "team_type": "competitive",
    "team_photo": "http://domain.com/storage/team_photo.jpg",
    "certification": "certification_document.pdf",
    "certified": true,
    "address_line": "123 Main St",
    "latitude": 40.7128,
    "longitude": -74.0060,
    "roster_size_limit": 20,
    "created_at": "2024-01-01T00:00:00.000000Z",
    "updated_at": "2024-01-15T10:00:00.000000Z",
    "creator": {
      "id": 456,
      "username": "team_owner",
      "email": "owner@example.com"
    }
  },
  "summary": {
    "total_members": 15,
    "active_members": 12,
    "pending_requests": 2,
    "removed_members": 1,
    "roster_limit": 20,
    "available_slots": 8,
    "active_invites": 3,
    "total_events": 25,
    "upcoming_events": 5,
    "past_events": 20,
    "avg_member_rating": 4.5,
    "total_ratings": 150,
    "joined_last_30_days": 3,
    "joined_last_60_days": 5,
    "joined_last_90_days": 8,
    "removed_last_30_days": 1
  },
  "members": {
    "all": [
      {
        "member_id": 789,
        "user_id": 101,
        "username": "player1",
        "email": "player1@example.com",
        "first_name": "John",
        "last_name": "Doe",
        "profile_photo": "http://domain.com/storage/profile.jpg",
        "role": "member",
        "position": "forward",
        "is_active": true,
        "roster_status": "active",
        "joined_at": "2024-01-10T10:00:00.000000Z",
        "removed_at": null,
        "rating": 4.8,
        "rating_count": 25
      }
    ],
    "active": [...],
    "pending": [...],
    "removed": [...]
  },
  "events": {
    "all": [
      {
        "event_id": 501,
        "name": "Championship Game",
        "description": "Final championship match",
        "event_type": "tournament",
        "sport": "basketball",
        "date": "2024-02-15",
        "start_time": "18:00",
        "end_time": "20:00",
        "venue_id": 201,
        "group": "A",
        "participated_at": "2024-01-05T12:00:00.000000Z"
      }
    ],
    "upcoming": [...],
    "past": [...]
  },
  "ratings": {
    "team_average": 4.5,
    "total_ratings": 150,
    "top_players": [
      {
        "user_id": 101,
        "username": "star_player",
        "first_name": "John",
        "last_name": "Doe",
        "avg_rating": 4.9,
        "votes": 45
      }
    ]
  },
  "invites": [
    {
      "id": 301,
      "token": "abc123xyz",
      "expires_at": "2024-02-01T00:00:00.000000Z",
      "created_at": "2024-01-15T10:00:00.000000Z"
    }
  ],
  "statistics": {
    // Same as summary object
  }
}
```

### Error Responses

**404 - Team Not Found**
```json
{
  "status": "error",
  "message": "Team not found"
}
```

**403 - Forbidden (Insufficient Permissions)**
```json
{
  "status": "error",
  "message": "Forbidden"
}
```

## Response Fields Reference

### `team` Object
- `id` - Team ID
- `name` - Team name
- `team_type` - Type of team (e.g., "competitive", "recreational")
- `team_photo` - Full URL to team photo (or null)
- `certification` - Certification document filename (or null)
- `certified` - Boolean indicating if team is certified
- `address_line` - Team address (or null)
- `latitude` / `longitude` - Team location coordinates (or null)
- `roster_size_limit` - Maximum roster size (or null if unlimited)
- `created_at` / `updated_at` - Timestamps
- `creator` - Object with creator's id, username, email (or null)

### `summary` Object
- `total_members` - Total number of all members
- `active_members` - Number of active members
- `pending_requests` - Number of pending join requests
- `removed_members` - Number of removed members
- `roster_limit` - Maximum roster size (or null)
- `available_slots` - Available roster slots (or null)
- `active_invites` - Number of active invites
- `total_events` - Total events participated
- `upcoming_events` - Number of upcoming events
- `past_events` - Number of past events
- `avg_member_rating` - Average rating of all members (or null)
- `total_ratings` - Total number of ratings received
- `joined_last_30_days` - Members joined in last 30 days
- `joined_last_60_days` - Members joined in last 60 days
- `joined_last_90_days` - Members joined in last 90 days
- `removed_last_30_days` - Members removed in last 30 days

### `members` Object
Contains four arrays:
- `all` - All team members (sorted by joined_at desc)
- `active` - Only active members
- `pending` - Only pending members
- `removed` - Only removed members

Each member object contains:
- `member_id` - TeamMember record ID
- `user_id` - User ID
- `username` - User username
- `email` - User email
- `first_name` / `last_name` - User name
- `profile_photo` - Full URL to profile photo (or null)
- `role` - Member role (captain, manager, member, pending)
- `position` - Player position (or null)
- `is_active` - Boolean indicating if member is active
- `roster_status` - Roster status (active, removed, left)
- `joined_at` - Join date timestamp (or null)
- `removed_at` - Removal date timestamp (or null)
- `rating` - Individual member average rating (or null)
- `rating_count` - Number of ratings for this member

### `events` Object
Contains three arrays:
- `all` - All events team participated in
- `upcoming` - Only upcoming events (date in future)
- `past` - Only past events (date in past)

Each event object contains:
- `event_id` - Event ID
- `name` - Event name
- `description` - Event description (or null)
- `event_type` - Type of event
- `sport` - Sport name
- `date` - Event date (YYYY-MM-DD format)
- `start_time` / `end_time` - Event times
- `venue_id` - Venue ID (or null)
- `group` - Group/division (or null)
- `participated_at` - When team joined the event

### `ratings` Object
- `team_average` - Overall team average rating (or null)
- `total_ratings` - Total number of ratings
- `top_players` - Array of top 10 players by rating

Each top player object contains:
- `user_id` - User ID
- `username` - Username
- `first_name` / `last_name` - Player name
- `avg_rating` - Average rating
- `votes` - Number of rating votes

### `invites` Array
Array of active team invites, each containing:
- `id` - Invite ID
- `token` - Invite token
- `expires_at` - Expiration timestamp (or null if no expiration)
- `created_at` - Creation timestamp

## Frontend Implementation Guide

### Step 1: Fetch Report Data
```javascript
async function generateTeamReport(teamId) {
  try {
    const token = localStorage.getItem('auth_token'); // or your token storage method
    const response = await fetch(`/api/teams/${teamId}/analytics/report`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    });
    
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const reportData = await response.json();
    return reportData;
  } catch (error) {
    console.error('Error fetching report:', error);
    throw error;
  }
}
```

### Step 2: Format Data for Display
```javascript
function formatReportData(reportData) {
  return {
    teamName: reportData.team.name,
    generatedAt: new Date(reportData.report_generated_at).toLocaleString(),
    summary: reportData.summary,
    activeMembers: reportData.members.active,
    upcomingEvents: reportData.events.upcoming,
    topPlayers: reportData.ratings.top_players
  };
}
```

### Step 3: Create Print-Ready Component
```javascript
function TeamReport({ teamId }) {
  const [reportData, setReportData] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    generateTeamReport(teamId)
      .then(data => {
        setReportData(data);
        setLoading(false);
      })
      .catch(error => {
        console.error(error);
        setLoading(false);
      });
  }, [teamId]);

  const handlePrint = () => {
    window.print();
  };

  if (loading) return <div>Loading report...</div>;
  if (!reportData) return <div>Error loading report</div>;

  return (
    <div className="team-report">
      {/* Add print-specific CSS */}
      <style>{`
        @media print {
          .no-print { display: none; }
          .team-report { page-break-inside: avoid; }
        }
      `}</style>
      
      {/* Report Header */}
      <div className="report-header">
        <h1>{reportData.team.name} - Team Report</h1>
        <p>Generated: {new Date(reportData.report_generated_at).toLocaleString()}</p>
      </div>

      {/* Summary Section */}
      <section className="summary-section">
        <h2>Summary</h2>
        <div className="stats-grid">
          <div>Active Members: {reportData.summary.active_members}</div>
          <div>Total Events: {reportData.summary.total_events}</div>
          <div>Average Rating: {reportData.summary.avg_member_rating || 'N/A'}</div>
        </div>
      </section>

      {/* Members Section */}
      <section className="members-section">
        <h2>Team Members</h2>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Role</th>
              <th>Position</th>
              <th>Rating</th>
              <th>Joined</th>
            </tr>
          </thead>
          <tbody>
            {reportData.members.active.map(member => (
              <tr key={member.member_id}>
                <td>{member.first_name} {member.last_name}</td>
                <td>{member.role}</td>
                <td>{member.position || 'N/A'}</td>
                <td>{member.rating || 'N/A'}</td>
                <td>{new Date(member.joined_at).toLocaleDateString()}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      {/* Print Button */}
      <button onClick={handlePrint} className="no-print">
        Print Report
      </button>
    </div>
  );
}
```

### Step 4: Print Styling (CSS)
```css
/* Print-specific styles */
@media print {
  @page {
    margin: 1in;
    size: letter;
  }

  body {
    font-size: 12pt;
    line-height: 1.4;
  }

  .no-print {
    display: none !important;
  }

  .team-report {
    width: 100%;
  }

  .report-header {
    border-bottom: 2px solid #000;
    margin-bottom: 20px;
    padding-bottom: 10px;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    page-break-inside: auto;
  }

  tr {
    page-break-inside: avoid;
    page-break-after: auto;
  }

  thead {
    display: table-header-group;
  }

  tfoot {
    display: table-footer-group;
  }
}
```

## Best Practices

1. **Error Handling**: Always handle 403 and 404 errors gracefully
2. **Loading States**: Show loading indicators while fetching data
3. **Print Optimization**: Use CSS `@media print` for print-specific styling
4. **Data Formatting**: Format dates, numbers, and null values appropriately
5. **Performance**: Consider caching report data if generating multiple times
6. **Accessibility**: Ensure printed reports are readable and well-structured

## Notes

- All timestamps are in ISO 8601 format
- Photo URLs are full absolute URLs (use `asset()` helper)
- Null values indicate missing/optional data
- The `statistics` object is a duplicate of `summary` for convenience
- Events are separated into `upcoming` and `past` based on current date
- Members are separated by status for easy filtering
- Top players are limited to 10 highest-rated members

## Support

For issues or questions, contact the backend development team.
