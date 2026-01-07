# Admin Verification API Documentation

## Overview

This document describes the API endpoints for admin-side verification of athletes, venues, teams, and coaches. All endpoints use AI-powered document verification and support both automated (AI) and manual verification workflows.

**Base URL**: `/api/admin`

**Authentication**: All endpoints require JWT authentication with admin role

---

## Table of Contents

1. [Authentication](#authentication)
2. [Entity Types](#entity-types)
3. [Document Categories](#document-categories)
4. [Athletes (Pro Athletes)](#athletes-pro-athletes)
5. [Venues](#venues)
6. [Teams](#teams)
7. [Coaches](#coaches)
8. [Entity Documents (Polymorphic)](#entity-documents-polymorphic)
9. [Statistics](#statistics)
10. [Response Formats](#response-formats)
11. [Verification Workflow](#verification-workflow)

---

## Authentication

All admin endpoints require:
- JWT token in `Authorization: Bearer {token}` header
- User must have admin role

---

## Entity Types

The following entity types are supported:

- `user` - Athletes (Pro Athletes)
- `venue` - Venues
- `team` - Teams
- `coach` - Coaches

---

## Document Categories

- `athlete_certification` - For pro athlete verification
- `venue_business` - For venue business licenses/permits
- `team_registration` - For team registration documents
- `coach_license` - For coach licensing documents
- `other` - Other document types

---

## Athletes (Pro Athletes)

### List Athletes

**GET** `/admin/users`

**Query Parameters:**
- `q` (optional) - Search by email, username, first_name, last_name
- `role` (optional) - Filter by role
- `is_pro_athlete` (optional) - Filter by pro athlete status (`true`/`false`)
- `ai_verified` (optional) - Filter by AI verification (`true`/`false`)
- `date_from` (optional) - Filter by creation date from
- `date_to` (optional) - Filter by creation date to
- `per_page` (optional, default: 20) - Results per page (max: 100)

**Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "username": "johndoe",
      "email": "john@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "is_pro_athlete": true,
      "verified_at": "2026-01-07T20:30:00.000000Z",
      "verified_by": 1,
      "verified_by_ai": true,
      "verification_source": "ai",
      "role": {...}
    }
  ],
  "total": 100
}
```

**Verification Source Values:**
- `"ai"` - Verified automatically by AI
- `"manual"` - Verified manually by admin
- `null` - Not verified

---

### Get Athlete Details

**GET** `/admin/users/{id}`

**Response:**
```json
{
  "id": 1,
  "username": "johndoe",
  "email": "john@example.com",
  "first_name": "John",
  "last_name": "Doe",
  "is_pro_athlete": true,
  "verified_at": "2026-01-07T20:30:00.000000Z",
  "verified_by": 1,
  "verified_by_ai": true,
  "verification_source": "ai",
  "verification_notes": "Auto-verified by AI based on verified documents",
  "role": {...}
}
```

---

### Approve Athlete as Pro Athlete

**POST** `/admin/users/{id}/approve`

**Request Body:**
```json
{
  "verification_notes": "Manually approved by admin review" // optional
}
```

**Response:**
```json
{
  "status": "success",
  "message": "User verified as Pro Athlete successfully",
  "user": {
    "id": 1,
    "is_pro_athlete": true,
    "verified_at": "2026-01-07T20:30:00.000000Z",
    "verified_by": 2,
    "verified_by_ai": false,
    "verifier": {
      "id": 2,
      "username": "admin"
    }
  }
}
```

---

### Reject Athlete Verification

**POST** `/admin/users/{id}/reject`

**Request Body:**
```json
{
  "verification_notes": "Required documents missing" // required
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Pro Athlete verification rejected",
  "user": {
    "id": 1,
    "is_pro_athlete": false,
    "verified_at": null,
    "verified_by": 2,
    "verified_by_ai": false
  }
}
```

---

### Get Athlete Documents

**GET** `/admin/users/{id}/documents`

**Response:**
```json
{
  "status": "success",
  "user": {
    "id": 1,
    "username": "johndoe",
    "email": "john@example.com",
    "name": "John Doe"
  },
  "documents": [
    {
      "id": 1,
      "document_category": "athlete_certification",
      "document_type": "certification",
      "document_name": "PBA Player Certification",
      "verification_status": "verified",
      "verified_by_ai": true,
      "ai_confidence_score": 92.5,
      "verified_at": "2026-01-07T20:25:00.000000Z",
      "verifier": {
        "id": 1,
        "username": "system"
      }
    }
  ]
}
```

---

### Get Athlete Statistics

**GET** `/admin/users/statistics`

**Response:**
```json
{
  "status": "success",
  "statistics": {
    "total_users": 1000,
    "pro_athletes": 150,
    "verified_by_ai": 120,
    "verified_manually": 30
  }
}
```

---

## Venues

### List Venues

**GET** `/admin/venues`

**Query Parameters:**
- `q` (optional) - Search by name, address, description
- `status` (optional) - Filter by status:
  - `closed` - Closed venues
  - `active` - Active venues
  - `verified` - Verified venues (verified_at IS NOT NULL)
  - `unverified` - Unverified venues (verified_at IS NULL)
- `ai_verified` (optional) - Filter by AI verification (`true`/`false`)
- `per_page` (optional, default: 20) - Results per page (max: 100)

**Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "name": "Sports Complex",
      "address": "123 Main St",
      "verified_at": "2026-01-07T20:30:00.000000Z",
      "verification_expires_at": "2027-01-07T20:30:00.000000Z",
      "verified_by": 1,
      "verified_by_ai": true,
      "verification_source": "ai",
      "photos": [...],
      "facilities": [...]
    }
  ]
}
```

---

### Get Venue Details

**GET** `/admin/venues/{id}`

**Response:**
```json
{
  "id": 1,
  "name": "Sports Complex",
  "address": "123 Main St",
  "verified_at": "2026-01-07T20:30:00.000000Z",
  "verification_expires_at": "2027-01-07T20:30:00.000000Z",
  "verified_by": 1,
  "verified_by_ai": true,
  "verification_source": "ai",
  "entity_documents": [
    {
      "id": 1,
      "document_category": "venue_business",
      "document_name": "Business Permit",
      "verification_status": "verified"
    }
  ],
  "photos": [...],
  "facilities": [...]
}
```

---

### Approve Venue

**POST** `/admin/venues/{id}/approve`

**Response:**
```json
{
  "status": "success",
  "message": "Approved",
  "venue": {
    "id": 1,
    "verified_at": "2026-01-07T20:30:00.000000Z",
    "verification_expires_at": "2027-01-07T20:30:00.000000Z",
    "verified_by": 2,
    "verified_by_ai": false
  }
}
```

---

### Reject Venue

**POST** `/admin/venues/{id}/reject`

**Request Body:**
```json
{
  "reason": "Insufficient documentation" // required
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Rejected",
  "venue": {
    "id": 1,
    "verified_at": null,
    "verified_by": 2,
    "verified_by_ai": false,
    "closed_reason": "Insufficient documentation"
  }
}
```

---

### Reset Venue Verification

**POST** `/admin/venues/{id}/reset-verification`

**Response:**
```json
{
  "status": "success",
  "message": "Verification reset to pending",
  "venue": {
    "id": 1,
    "verified_at": null,
    "verification_expires_at": null,
    "verified_by": null,
    "verified_by_ai": false
  }
}
```

---

### Get Venue Documents

**GET** `/admin/venues/{id}/documents`

**Response:**
```json
{
  "status": "success",
  "venue": {
    "id": 1,
    "name": "Sports Complex"
  },
  "documents": [
    {
      "id": 1,
      "document_category": "venue_business",
      "document_type": "business_license",
      "document_name": "Business Permit 2026",
      "verification_status": "verified",
      "verified_by_ai": true,
      "ai_confidence_score": 88.5
    }
  ]
}
```

---

## Teams

### List Teams

**GET** `/admin/teams`

**Query Parameters:**
- `q` (optional) - Search by team name
- `verification_status` (optional) - Filter by status:
  - `verified` - Verified teams
  - `pending` - Pending verification
  - `rejected` - Rejected teams
- `ai_verified` (optional) - Filter by AI verification (`true`/`false`)
- `per_page` (optional, default: 20) - Results per page (max: 100)

**Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "name": "Eagles Basketball Team",
      "certification_status": "verified",
      "certification_verified_at": "2026-01-07T20:30:00.000000Z",
      "certification_verified_by": 1,
      "verified_by_ai": true,
      "verification_source": "ai",
      "creator": {
        "id": 5,
        "username": "teamowner",
        "email": "owner@example.com"
      },
      "sport": {...}
    }
  ]
}
```

---

### Get Team Details

**GET** `/admin/teams/{id}`

**Response:**
```json
{
  "id": 1,
  "name": "Eagles Basketball Team",
  "certification_status": "verified",
  "certification_verified_at": "2026-01-07T20:30:00.000000Z",
  "certification_verified_by": 1,
  "verified_by_ai": true,
  "verification_source": "ai",
  "entity_documents": [
    {
      "id": 1,
      "document_category": "team_registration",
      "document_name": "Team Registration Certificate",
      "verification_status": "verified"
    }
  ],
  "creator": {...},
  "sport": {...},
  "certification_verifier": {
    "id": 1,
    "username": "system"
  }
}
```

---

### Approve Team

**POST** `/admin/teams/{id}/approve`

**Request Body:**
```json
{
  "verification_notes": "All documents verified" // optional
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Team verified successfully",
  "team": {
    "id": 1,
    "certification_status": "verified",
    "certification_verified_at": "2026-01-07T20:30:00.000000Z",
    "certification_verified_by": 2,
    "verified_by_ai": false,
    "certification_verifier": {
      "id": 2,
      "username": "admin"
    }
  }
}
```

---

### Reject Team

**POST** `/admin/teams/{id}/reject`

**Request Body:**
```json
{
  "verification_notes": "Invalid registration documents" // required
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Team verification rejected",
  "team": {
    "id": 1,
    "certification_status": "rejected",
    "certification_verified_at": "2026-01-07T20:30:00.000000Z",
    "certification_verified_by": 2,
    "verified_by_ai": false
  }
}
```

---

### Reset Team Verification

**POST** `/admin/teams/{id}/reset-verification`

**Response:**
```json
{
  "status": "success",
  "message": "Team verification reset to pending",
  "team": {
    "id": 1,
    "certification_status": "pending",
    "certification_verified_at": null,
    "certification_verified_by": null,
    "verified_by_ai": false
  }
}
```

---

### Get Team Documents

**GET** `/admin/teams/{id}/documents`

**Response:**
```json
{
  "status": "success",
  "team": {
    "id": 1,
    "name": "Eagles Basketball Team"
  },
  "documents": [
    {
      "id": 1,
      "document_category": "team_registration",
      "document_type": "registration",
      "document_name": "Team Registration Certificate",
      "verification_status": "verified",
      "verified_by_ai": true,
      "ai_confidence_score": 91.0
    }
  ]
}
```

---

### Get Team Statistics

**GET** `/admin/teams/statistics`

**Response:**
```json
{
  "status": "success",
  "statistics": {
    "total": 200,
    "verified": 150,
    "pending": 40,
    "rejected": 10,
    "verified_by_ai": 120,
    "verified_manually": 30
  }
}
```

---

## Coaches

### List Coaches

**GET** `/admin/coaches`

**Query Parameters:**
- `q` (optional) - Search by username, email, first_name, last_name
- `verification_status` (optional) - Filter by status:
  - `verified` - Verified coaches (is_verified = true AND verified_at IS NOT NULL)
  - `pending` - Pending verification
- `ai_verified` (optional) - Filter by AI verification (`true`/`false`)
- `per_page` (optional, default: 20) - Results per page (max: 100)

**Response:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "user_id": 10,
      "is_verified": true,
      "verified_at": "2026-01-07T20:30:00.000000Z",
      "verified_by": 1,
      "verified_by_ai": true,
      "verification_source": "ai",
      "years_experience": 5,
      "rating": 4.5,
      "user": {
        "id": 10,
        "username": "coachsmith",
        "email": "coach@example.com",
        "first_name": "John",
        "last_name": "Smith"
      }
    }
  ]
}
```

---

### Get Coach Details

**GET** `/admin/coaches/{id}`

**Response:**
```json
{
  "id": 1,
  "user_id": 10,
  "is_verified": true,
  "verified_at": "2026-01-07T20:30:00.000000Z",
  "verified_by": 1,
  "verified_by_ai": true,
  "verification_source": "ai",
  "verification_notes": "Auto-verified by AI",
  "entity_documents": [
    {
      "id": 1,
      "document_category": "coach_license",
      "document_name": "Coaching License",
      "verification_status": "verified"
    }
  ],
  "user": {...},
  "verifier": {
    "id": 1,
    "username": "system"
  }
}
```

---

### Approve Coach

**POST** `/admin/coaches/{id}/approve`

**Request Body:**
```json
{
  "verification_notes": "License verified" // optional
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Coach verified successfully",
  "coach": {
    "id": 1,
    "is_verified": true,
    "verified_at": "2026-01-07T20:30:00.000000Z",
    "verified_by": 2,
    "verified_by_ai": false,
    "verifier": {
      "id": 2,
      "username": "admin"
    },
    "user": {...}
  }
}
```

---

### Reject Coach

**POST** `/admin/coaches/{id}/reject`

**Request Body:**
```json
{
  "verification_notes": "License expired" // required
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Coach verification rejected",
  "coach": {
    "id": 1,
    "is_verified": false,
    "verified_at": null,
    "verified_by": 2,
    "verified_by_ai": false
  }
}
```

---

### Reset Coach Verification

**POST** `/admin/coaches/{id}/reset-verification`

**Response:**
```json
{
  "status": "success",
  "message": "Coach verification reset to pending",
  "coach": {
    "id": 1,
    "is_verified": false,
    "verified_at": null,
    "verified_by": null,
    "verified_by_ai": false
  }
}
```

---

### Get Coach Documents

**GET** `/admin/coaches/{id}/documents`

**Response:**
```json
{
  "status": "success",
  "coach": {
    "id": 1,
    "user": {
      "id": 10,
      "name": "John Smith"
    }
  },
  "documents": [
    {
      "id": 1,
      "document_category": "coach_license",
      "document_type": "certification",
      "document_name": "Level 3 Coaching License",
      "verification_status": "verified",
      "verified_by_ai": true,
      "ai_confidence_score": 89.5
    }
  ]
}
```

---

### Get Coach Statistics

**GET** `/admin/coaches/statistics`

**Response:**
```json
{
  "status": "success",
  "statistics": {
    "total": 50,
    "verified": 35,
    "pending": 15,
    "verified_by_ai": 28,
    "verified_manually": 7
  }
}
```

---

## Entity Documents (Polymorphic)

### List All Entity Documents

**GET** `/admin/entity-documents`

**Query Parameters:**
- `entity_type` (optional) - Filter by entity type (`user`, `venue`, `team`, `coach`)
- `document_category` (optional) - Filter by category
- `status` (optional) - Filter by verification status (`pending`, `verified`, `rejected`)
- `ai_verified` (optional) - Filter by AI auto-verification (`true`/`false`)
- `per_page` (optional, default: 20) - Results per page (max: 100)

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "documentable_type": "App\\Models\\User",
      "documentable_id": 5,
      "document_category": "athlete_certification",
      "document_type": "certification",
      "document_name": "PBA Certification",
      "verification_status": "verified",
      "verified_by_ai": true,
      "ai_confidence_score": 92.5,
      "verified_at": "2026-01-07T20:25:00.000000Z",
      "entity_name": "John Doe",
      "entity_type_display": "User (Athlete)",
      "documentable": {...},
      "verifier": {
        "id": 1,
        "username": "system"
      }
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 20,
    "total": 200
  }
}
```

---

### Get Document Details

**GET** `/admin/entity-documents/{id}`

**Response:**
```json
{
  "status": "success",
  "document": {
    "id": 1,
    "documentable_type": "App\\Models\\Team",
    "documentable_id": 3,
    "document_category": "team_registration",
    "document_type": "registration",
    "document_name": "Team Registration",
    "file_path": "entities/team/3/documents/registration.pdf",
    "file_url": "http://domain.com/storage/entities/team/3/documents/registration.pdf",
    "verification_status": "verified",
    "verified_by_ai": true,
    "ai_confidence_score": 91.0,
    "ai_quality_score": 95.0,
    "ai_extracted_data": {...},
    "verified_at": "2026-01-07T20:25:00.000000Z",
    "verification_notes": "Auto-verified by AI",
    "entity_name": "Eagles Basketball Team",
    "entity_type_display": "Team",
    "documentable": {...},
    "verifier": {
      "id": 1,
      "username": "system"
    }
  }
}
```

---

### Verify Document

**POST** `/admin/entity-documents/{id}/verify`

**Request Body:**
```json
{
  "verification_notes": "Document manually verified" // optional
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Document verified successfully",
  "document": {
    "id": 1,
    "verification_status": "verified",
    "verified_by": 2,
    "verified_at": "2026-01-07T20:30:00.000000Z",
    "verified_by_ai": false,
    "documentable": {...},
    "verifier": {
      "id": 2,
      "username": "admin"
    }
  }
}
```

**Note**: When a document is verified, the system automatically checks if the entity should be auto-verified based on document requirements.

---

### Reject Document

**POST** `/admin/entity-documents/{id}/reject`

**Request Body:**
```json
{
  "verification_notes": "Document is expired" // required
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Document rejected",
  "document": {
    "id": 1,
    "verification_status": "rejected",
    "verified_by": 2,
    "verified_at": "2026-01-07T20:30:00.000000Z",
    "verification_notes": "Document is expired"
  }
}
```

---

### Reset Document Verification

**POST** `/admin/entity-documents/{id}/reset`

**Response:**
```json
{
  "status": "success",
  "message": "Document verification reset to pending",
  "document": {
    "id": 1,
    "verification_status": "pending",
    "verified_by": null,
    "verified_at": null,
    "verification_notes": null
  }
}
```

---

### Download Document

**GET** `/admin/entity-documents/{id}/download`

**Response**: File download (binary)

---

## Statistics

### AI Document Processing Statistics

**GET** `/admin/documents/ai/statistics`

**Response:**
```json
{
  "status": "success",
  "statistics": {
    "total_processed": 1000,
    "auto_approved": 850,
    "pending_review": 100,
    "today_processed": 50,
    "today_auto_approved": 42,
    "avg_confidence": 87.5,
    "avg_quality": 91.2,
    "by_confidence_range": {
      "high_90_plus": 600,
      "good_80_89": 250,
      "medium_70_79": 100,
      "low_below_70": 50
    },
    "auto_approve_rate": 85.0
  }
}
```

---

### AI Smart Queue

**GET** `/admin/documents/ai/smart-queue`

**Response:**
```json
{
  "status": "success",
  "statistics": {
    "auto_approved_today": 42,
    "pending_high_priority": 15,
    "pending_quick_review": 25,
    "avg_confidence": 87.5,
    "avg_processing_time": "10.5s"
  },
  "high_priority": [
    {
      "id": 1,
      "user": "johndoe",
      "user_email": "john@example.com",
      "document_name": "ID Card",
      "document_type": "government_id",
      "ai_confidence": 65.5,
      "ai_flags": ["Name mismatch"],
      "ai_quality_score": 78.0,
      "created_at": "2026-01-07T20:00:00.000000Z",
      "file_url": "http://domain.com/storage/..."
    }
  ],
  "quick_review": [...]
}
```

---

## Response Formats

### Success Response

All successful operations return:
```json
{
  "status": "success",
  "message": "Operation completed successfully",
  // Additional data...
}
```

### Error Response

All errors return:
```json
{
  "status": "error",
  "message": "Error description",
  "errors": {
    // Validation errors (if applicable)
  }
}
```

**HTTP Status Codes:**
- `200` - Success
- `201` - Created
- `404` - Not Found
- `403` - Forbidden
- `422` - Validation Error
- `500` - Server Error

---

## Verification Workflow

### Automated Verification Flow

1. **Document Upload**
   - User uploads document via `/api/entity-documents`
   - Document is queued for AI processing

2. **AI Processing**
   - AI extracts text using OCR
   - AI validates document content
   - AI matches names/entities
   - AI calculates confidence score

3. **Auto-Verification Decision**
   - If confidence ≥ 85% and no critical flags → **Auto-verify document**
   - If confidence 70-84% → **Queue for quick review**
   - If confidence < 70% → **Require manual review**

4. **Entity Verification Check**
   - When document is verified, system checks if entity has enough verified documents
   - If requirements met → **Auto-verify entity**
   - Entity is marked with `verified_by_ai: true`

### Manual Verification Flow

1. Admin reviews document in smart queue
2. Admin can:
   - Approve document manually
   - Reject document with notes
   - Reset verification status
3. When admin approves/rejects → Entity verification is checked
4. Entity is marked with `verified_by_ai: false`

### Verification Indicators

**Document Level:**
- `ai_auto_verified: true` - Document was auto-verified by AI
- `verified_by: 1` - System user ID (AI)
- `verified_by: {admin_id}` - Admin user ID (manual)

**Entity Level:**
- `verified_by_ai: true` - Entity was auto-verified by AI
- `verified_by_ai: false` - Entity was manually verified/rejected
- `verification_source: "ai"` - Computed field showing AI verification
- `verification_source: "manual"` - Computed field showing manual verification

---

## Frontend Integration Examples

### Example 1: List All Pending Verifications

```javascript
// Get all entities pending verification
const [athletes, venues, teams, coaches] = await Promise.all([
  fetch('/api/admin/users?is_pro_athlete=false', {
    headers: { 'Authorization': `Bearer ${token}` }
  }).then(r => r.json()),
  
  fetch('/api/admin/venues?status=unverified', {
    headers: { 'Authorization': `Bearer ${token}` }
  }).then(r => r.json()),
  
  fetch('/api/admin/teams?verification_status=pending', {
    headers: { 'Authorization': `Bearer ${token}` }
  }).then(r => r.json()),
  
  fetch('/api/admin/coaches?verification_status=pending', {
    headers: { 'Authorization': `Bearer ${token}` }
  }).then(r => r.json())
]);
```

---

### Example 2: Approve with AI Badge Display

```javascript
// Approve entity
const response = await fetch(`/api/admin/teams/${teamId}/approve`, {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    verification_notes: 'All documents verified'
  })
});

const result = await response.json();

// Display verification source badge
if (result.team.verified_by_ai) {
  showBadge('Approved by AI', 'success');
} else {
  showBadge('Approved by Admin', 'info');
}
```

---

### Example 3: Display Document Verification Status

```javascript
// Get entity documents
const response = await fetch(`/api/admin/teams/${teamId}/documents`, {
  headers: { 'Authorization': `Bearer ${token}` }
});

const { documents } = await response.json();

// Render documents with AI indicators
documents.forEach(doc => {
  const status = doc.verification_status;
  const aiVerified = doc.verified_by_ai;
  const confidence = doc.ai_confidence_score;
  
  renderDocument({
    name: doc.document_name,
    status: status,
    badge: aiVerified ? 
      `AI Verified (${confidence}% confidence)` : 
      'Manually Verified'
  });
});
```

---

### Example 4: Filter by AI Verification

```javascript
// Show only AI-verified entities
const response = await fetch('/api/admin/venues?ai_verified=true', {
  headers: { 'Authorization': `Bearer ${token}` }
});

const { data } = await response.json();

// Display with AI badge
data.forEach(venue => {
  if (venue.verification_source === 'ai') {
    addAIBadge(venue.id);
  }
});
```

---

## Document Categories Reference

| Category | Entity Type | Required Documents |
|----------|-------------|-------------------|
| `athlete_certification` | User | Pro league certifications, athlete IDs |
| `venue_business` | Venue | Business permits, licenses, certificates |
| `team_registration` | Team | Team registration certificates, league memberships |
| `coach_license` | CoachProfile | Coaching licenses, certifications |
| `other` | Any | Custom document types |

---

## Verification Status Values

- `pending` - Document/entity awaiting verification
- `verified` - Document/entity has been verified and approved
- `rejected` - Document/entity has been rejected

**Note**: Entity-specific statuses:
- Teams: `certification_status` uses same values (`pending`, `verified`, `rejected`)
- Coaches: `is_verified` boolean + `verified_at` timestamp
- Venues: `verified_at` timestamp (null = not verified)
- Users: `is_pro_athlete` boolean + `verified_at` timestamp

---

## Best Practices

1. **Always check `verification_source`** to display appropriate badges
2. **Show AI confidence scores** for transparency
3. **Filter by `ai_verified`** to separate AI vs manual verifications
4. **Use statistics endpoints** for dashboard summaries
5. **Handle pagination** properly (check `current_page`, `last_page`, `total`)
6. **Display verification notes** to users for transparency
7. **Show document counts** per entity to understand verification completeness

---

## Error Handling

All endpoints may return these common errors:

```json
{
  "status": "error",
  "message": "Entity not found"
}
```

```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": {
    "verification_notes": ["The verification notes field is required."]
  }
}
```

Always check `status` field in response before processing data.

---

## Notes

- All timestamps are in ISO 8601 format (UTC)
- File URLs are relative to storage root
- AI confidence scores range from 0-100
- Document verification triggers automatic entity verification check
- Manual admin actions override AI decisions
- Reset verification returns entity/document to pending state

