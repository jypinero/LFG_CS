# Profile Edit - Frontend Integration Guide

## Backend Changes Applied ✅

The backend now supports:
- ✅ Updating `occupation`
- ✅ Updating `main_sport_id` and `main_sport_level`
- ✅ Adding/removing `additional_sports`
- ✅ All existing fields (bio, city, province, profile_photo, username)

---

## API Endpoints

### 1. Get Current User Profile
**GET** `/api/me`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "user": {
    "id": 1,
    "username": "johndoe",
    "email": "john@example.com",
    "first_name": "John",
    "last_name": "Doe",
    "city": "Lyceum of Subic B",
    "province": "Rizal Highway",
    "profile_photo": "userpfp/1234567890_photo.jpg",
    "user_profile": {
      "id": 1,
      "user_id": 1,
      "bio": "hi",
      "occupation": "Student",
      "main_sport_id": 1,
      "main_sport_level": "competitive",
      "main_sport": {
        "id": 1,
        "name": "Basketball",
        "category": "team_sport"
      }
    },
    "user_additional_sports": [
      {
        "id": 1,
        "user_id": 1,
        "sport_id": 2,
        "level": "beginner",
        "sport": {
          "id": 2,
          "name": "Volleyball",
          "category": "team_sport"
        }
      }
    ]
  },
  "has_team": true,
  "teams": [...]
}
```

---

### 2. Get All Available Sports
**GET** `/api/auth/get-sports`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "status": "success",
  "sports": [
    {
      "id": 1,
      "name": "Basketball",
      "category": "team_sport",
      "is_active": true
    },
    {
      "id": 2,
      "name": "Volleyball",
      "category": "team_sport",
      "is_active": true
    },
    {
      "id": 3,
      "name": "Football",
      "category": "team_sport",
      "is_active": true
    }
  ]
}
```

---

### 3. Update Profile
**POST** `/api/profile/update`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: multipart/form-data  // If uploading photo
// OR
Content-Type: application/json      // If no photo upload
```

**Request Body (JSON):**
```json
{
  "bio": "Updated bio text",
  "city": "New City",
  "province": "New Province",
  "occupation": "Software Developer",
  "main_sport_id": 1,
  "main_sport_level": "competitive",
  "additional_sports": [
    {
      "id": 2,
      "level": "beginner"
    },
    {
      "id": 3,
      "level": "professional"
    }
  ]
}
```

**Request Body (FormData - if uploading photo):**
```javascript
const formData = new FormData();
formData.append('bio', 'Updated bio');
formData.append('city', 'New City');
formData.append('province', 'New Province');
formData.append('occupation', 'Software Developer');
formData.append('main_sport_id', '1');
formData.append('main_sport_level', 'competitive');
formData.append('additional_sports', JSON.stringify([
  { id: 2, level: 'beginner' },
  { id: 3, level: 'professional' }
]));
formData.append('profile_photo', file); // File object
```

**Response:**
```json
{
  "status": "success",
  "message": "Profile updated successfully!",
  "user": {
    "id": 1,
    "username": "johndoe",
    "city": "New City",
    "province": "New Province",
    "profile_photo": "userpfp/1234567890_newphoto.jpg",
    "profile_photo_url": "http://your-domain.com/storage/userpfp/1234567890_newphoto.jpg",
    "user_profile": {
      "id": 1,
      "bio": "Updated bio text",
      "occupation": "Software Developer",
      "main_sport_id": 1,
      "main_sport_level": "competitive",
      "main_sport": {
        "id": 1,
        "name": "Basketball"
      }
    },
    "user_additional_sports": [
      {
        "id": 1,
        "sport_id": 2,
        "level": "beginner",
        "sport": {
          "id": 2,
          "name": "Volleyball"
        }
      },
      {
        "id": 2,
        "sport_id": 3,
        "level": "professional",
        "sport": {
          "id": 3,
          "name": "Football"
        }
      }
    ]
  }
}
```

---

## Frontend Implementation Guide

### 1. Fetch Current Profile Data

```javascript
// Fetch user profile
const response = await fetch('/api/me', {
  headers: {
    'Authorization': `Bearer ${token}`
  }
});

const data = await response.json();
const user = data.user;

// Extract profile data
const profileData = {
  bio: user.user_profile?.bio || '',
  city: user.city || '',
  province: user.province || '',
  occupation: user.user_profile?.occupation || '',
  mainSport: user.user_profile?.main_sport || null,
  mainSportLevel: user.user_profile?.main_sport_level || 'beginner',
  additionalSports: user.user_additional_sports?.map(item => ({
    id: item.sport.id,
    name: item.sport.name,
    level: item.level
  })) || [],
  profilePhoto: user.profile_photo_url || null
};
```

---

### 2. Fetch Available Sports

```javascript
// Fetch all sports for dropdowns
const sportsResponse = await fetch('/api/auth/get-sports', {
  headers: {
    'Authorization': `Bearer ${token}`
  }
});

const sportsData = await sportsResponse.json();
const availableSports = sportsData.sports;
```

---

### 3. Update Profile

```javascript
// Prepare update payload
const updatePayload = {
  bio: formData.bio,
  city: formData.city,
  province: formData.province,
  occupation: formData.occupation,
  main_sport_id: selectedMainSport?.id || null,
  main_sport_level: selectedMainSportLevel || 'beginner',
  additional_sports: additionalSports.map(sport => ({
    id: sport.id,
    level: sport.level
  }))
};

// If uploading photo, use FormData
const formDataToSend = new FormData();
Object.keys(updatePayload).forEach(key => {
  if (key === 'additional_sports') {
    formDataToSend.append(key, JSON.stringify(updatePayload[key]));
  } else {
    formDataToSend.append(key, updatePayload[key]);
  }
});

if (profilePhotoFile) {
  formDataToSend.append('profile_photo', profilePhotoFile);
}

// Send update request
const updateResponse = await fetch('/api/profile/update', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`
    // Don't set Content-Type for FormData - browser will set it with boundary
  },
  body: formDataToSend
});

const result = await updateResponse.json();
```

---

## UI Component Structure

### Profile Edit Form Fields

1. **Profile Picture**
   - Display current photo
   - Click to change
   - File input (accept: image/*)

2. **Bio**
   - Textarea (max 1000 chars)
   - Character counter: `{bio.length}/1000`

3. **Location**
   - Auto-detect button (optional - implement geolocation)
   - City input field
   - Province input field

4. **Occupation**
   - Text input field
   - Placeholder: "Enter your occupation"

5. **Main Sport**
   - Dropdown/Select: All available sports
   - Level selector (Radio buttons or Select):
     - Beginner
     - Competitive
     - Professional
   - Display current main sport if set

6. **Additional Sports**
   - List of current additional sports
   - Each item shows:
     - Sport name
     - Level badge
     - Remove button (×)
   - "+ Add Sport" button
   - Modal/Drawer to select:
     - Sport dropdown (exclude main sport)
     - Level selector
   - Validation: Can't add main sport as additional

---

## Validation Rules

### Frontend Validation:
- **Bio**: Max 1000 characters
- **Main Sport**: Optional (but if selected, level is required)
- **Additional Sports**: 
  - Can't add same sport twice
  - Can't add main sport as additional
  - Max reasonable limit (e.g., 10 sports)

### Backend Validation:
- `bio`: nullable, string, max:1000
- `occupation`: nullable, string, max:255
- `main_sport_id`: nullable, exists:sports,id
- `main_sport_level`: nullable, in:beginner,competitive,professional
- `additional_sports`: nullable, array
- `additional_sports.*.id`: required_with:additional_sports, exists:sports,id
- `additional_sports.*.level`: required_with:additional_sports, in:beginner,competitive,professional

---

## Example React/Next.js Component Structure

```jsx
// ProfileEditForm.jsx
const ProfileEditForm = () => {
  const [profile, setProfile] = useState(null);
  const [sports, setSports] = useState([]);
  const [formData, setFormData] = useState({
    bio: '',
    city: '',
    province: '',
    occupation: '',
    mainSportId: null,
    mainSportLevel: 'beginner',
    additionalSports: []
  });

  // Fetch profile and sports on mount
  useEffect(() => {
    fetchProfile();
    fetchSports();
  }, []);

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    const payload = {
      bio: formData.bio,
      city: formData.city,
      province: formData.province,
      occupation: formData.occupation,
      main_sport_id: formData.mainSportId,
      main_sport_level: formData.mainSportLevel,
      additional_sports: formData.additionalSports.map(s => ({
        id: s.id,
        level: s.level
      }))
    };

    // Send update...
  };

  const handleAddSport = (sportId, level) => {
    // Validate: not main sport, not duplicate
    if (sportId === formData.mainSportId) {
      alert('Cannot add main sport as additional');
      return;
    }
    
    if (formData.additionalSports.some(s => s.id === sportId)) {
      alert('Sport already added');
      return;
    }

    setFormData({
      ...formData,
      additionalSports: [
        ...formData.additionalSports,
        { id: sportId, level }
      ]
    });
  };

  const handleRemoveSport = (sportId) => {
    setFormData({
      ...formData,
      additionalSports: formData.additionalSports.filter(s => s.id !== sportId)
    });
  };

  // Render form...
};
```

---

## Error Handling

**400/422 Validation Errors:**
```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": {
    "main_sport_id": ["The selected main sport id is invalid."],
    "additional_sports.0.id": ["The selected sport id is invalid."]
  }
}
```

**500 Server Errors:**
```json
{
  "status": "error",
  "message": "Failed to update profile",
  "error": "Error message details"
}
```

---

## Notes

1. **Main Sport vs Additional Sports**: The backend automatically prevents adding the main sport as an additional sport, but it's good UX to prevent it on the frontend too.

2. **Photo Upload**: Use `FormData` when uploading photos. For text-only updates, you can use JSON.

3. **Level Options**: Always use these exact values:
   - `beginner`
   - `competitive`
   - `professional`

4. **Empty Additional Sports**: To remove all additional sports, send an empty array: `"additional_sports": []`

5. **Partial Updates**: All fields are optional. You can update just one field if needed.

---

## Testing Checklist

- [ ] Load current profile data correctly
- [ ] Display main sport and level
- [ ] Display additional sports list
- [ ] Update bio, city, province, occupation
- [ ] Change main sport and level
- [ ] Add additional sport
- [ ] Remove additional sport
- [ ] Prevent adding main sport as additional
- [ ] Prevent duplicate additional sports
- [ ] Upload profile photo
- [ ] Handle validation errors
- [ ] Handle network errors
- [ ] Character counter for bio (1000 max)

