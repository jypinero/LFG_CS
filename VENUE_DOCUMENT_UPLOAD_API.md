# How Users Upload Documents for Venue Verification

## API Endpoint

**POST** `/api/entity-documents`

## Required Parameters

- `entity_type`: `"venue"` (string, required)
- `entity_id`: The venue ID (integer, required)
- `document_category`: `"venue_business"` (string, required) - for venue verification
- `document_type`: One of the following (string, required):
  - `"business_license"` (most common for venues)
  - `"government_id"`
  - `"insurance_proof"`
  - `"certification"`
  - `"registration"`
  - `"other"` (requires `custom_type` field)
- `document`: The file (file, required)
  - Max size: 10MB
  - Allowed formats: `pdf`, `jpg`, `jpeg`, `png`, `doc`, `docx`
- `document_name`: Name of the document (string, required, max 255 characters)

## Optional Parameters

- `custom_type`: Required if `document_type` is `"other"` (string, max 100 characters)
- `description`: Document description (string, max 1000 characters)
- `reference_number`: Reference number (string, max 100 characters)
- `issued_by`: Issuing authority (string, max 255 characters)
- `issue_date`: Issue date (date, must be today or earlier)
- `expiry_date`: Expiry date (date, must be after `issue_date`)

## Authorization

The user must be the creator of the venue (checked via `venue.created_by === user.id`).

Only the venue creator can upload documents for their venue.

## Example Request

### JavaScript/Fetch

```javascript
const formData = new FormData();
formData.append('entity_type', 'venue');
formData.append('entity_id', venueId);
formData.append('document_category', 'venue_business');
formData.append('document_type', 'business_license');
formData.append('document_name', 'Business License 2024');
formData.append('document', fileInput.files[0]);
formData.append('issued_by', 'City Business Bureau');
formData.append('issue_date', '2024-01-15');
formData.append('expiry_date', '2025-01-15');

fetch('/api/entity-documents', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`
  },
  body: formData
});
```

### cURL

```bash
curl -X POST https://your-api-domain.com/api/entity-documents \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "entity_type=venue" \
  -F "entity_id=5" \
  -F "document_category=venue_business" \
  -F "document_type=business_license" \
  -F "document_name=Business License 2024" \
  -F "document=@/path/to/document.pdf" \
  -F "issued_by=City Business Bureau" \
  -F "issue_date=2024-01-15" \
  -F "expiry_date=2025-01-15"
```

## Response

### Success Response (201 Created)

```json
{
  "status": "success",
  "message": "Document uploaded successfully. AI verification in progress...",
  "document": {
    "id": 123,
    "documentable_type": "App\\Models\\Venue",
    "documentable_id": 5,
    "document_category": "venue_business",
    "document_type": "business_license",
    "document_name": "Business License 2024",
    "description": null,
    "reference_number": null,
    "file_path": "entities/venue/5/documents/...",
    "file_type": "application/pdf",
    "file_size": 245678,
    "issued_by": "City Business Bureau",
    "issue_date": "2024-01-15",
    "expiry_date": "2025-01-15",
    "verification_status": "pending",
    "created_at": "2024-01-20T10:30:00.000000Z",
    "updated_at": "2024-01-20T10:30:00.000000Z"
  },
  "ai_processing": true
}
```

### Error Responses

#### Validation Error (422 Unprocessable Entity)

```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": {
    "document": ["The document field is required."],
    "document_type": ["The selected document type is invalid."]
  }
}
```

#### Authorization Error (403 Forbidden)

```json
{
  "status": "error",
  "message": "You do not have permission to upload documents for this entity"
}
```

#### Entity Not Found (404 Not Found)

```json
{
  "status": "error",
  "message": "Entity not found"
}
```

## After Upload

1. Document status is set to `"pending"`.
2. If AI verification is enabled, the document is queued for AI processing.
3. Admins can review and verify/reject documents via:
   - `POST /api/admin/entity-documents/{id}/verify` - Verify the document
   - `POST /api/admin/entity-documents/{id}/reject` - Reject the document
4. Once verified documents meet requirements, the venue can be auto-verified.

## Viewing Uploaded Documents

**GET** `/api/entity-documents?entity_type=venue&entity_id={venueId}`

This returns all documents for the venue.

### Query Parameters

- `entity_type`: `"venue"` (required)
- `entity_id`: The venue ID (required, integer)
- `document_category`: Filter by category (optional)
- `status`: Filter by verification status - `pending`, `verified`, `rejected` (optional)

### Example Request

```javascript
fetch(`/api/entity-documents?entity_type=venue&entity_id=${venueId}&status=verified`, {
  method: 'GET',
  headers: {
    'Authorization': `Bearer ${token}`
  }
});
```

### Response

```json
{
  "status": "success",
  "documents": [
    {
      "id": 123,
      "documentable_type": "App\\Models\\Venue",
      "documentable_id": 5,
      "document_category": "venue_business",
      "document_type": "business_license",
      "document_name": "Business License 2024",
      "verification_status": "verified",
      "file_path": "entities/venue/5/documents/...",
      "verified_at": "2024-01-21T14:30:00.000000Z",
      "verifier": {
        "id": 1,
        "username": "admin_user"
      },
      ...
    }
  ]
}
```

## Document Types for Venues

When `document_category` is `"venue_business"`, the following `document_type` values are valid:

- `business_license` - Business license or permit
- `government_id` - Government-issued identification
- `insurance_proof` - Insurance certificate or proof
- `certification` - Business certification
- `registration` - Business registration document
- `other` - Other document type (requires `custom_type` field)

## Notes

- The system uses the `EntityDocumentController` which handles document uploads for venues, teams, coaches, and users in a unified way.
- Documents are stored in: `storage/app/public/entities/venue/{venueId}/documents/`
- Maximum file size: 10MB (10240 KB)
- Allowed file types: PDF, JPG, JPEG, PNG, DOC, DOCX
- Documents are processed asynchronously if AI verification is enabled
- Only the venue creator can upload/view documents for their venue
