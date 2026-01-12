# Profile Ratings Frontend Guide

## Overview
This guide explains how to display player ratings on user profiles using the `player_ratings` table. The ratings show who rated the user and their comments.

## API Endpoint

**GET** `/api/profile/{username}/ratings`

### Query Parameters (Optional)
- `per_page` - Number of ratings per page (default: 15)
- `page` - Page number (default: 1)

### Authentication
- Requires authentication token in header
- Works for both viewing own profile and other users' profiles
- Uses **username** (not userId) to match the profile route `/profile/{username}`

## Response Payload

### Success Response (200)

```json
{
  "status": "success",
  "data": {
    "ratings": [
      {
        "id": 123,
        "rating": 5,
        "comment": "Excellent player! Great teamwork and skills.",
        "created_at": "2024-01-15T10:30:00.000000Z",
        "rater": {
          "id": 10,
          "username": "player123",
          "first_name": "John",
          "last_name": "Doe",
          "profile_photo": "http://example.com/storage/userpfp/photo.jpg"
        },
        "event": {
          "id": 45,
          "name": "Basketball Championship",
          "date": "2024-01-10",
          "sport": "basketball"
        }
      },
      {
        "id": 124,
        "rating": 4,
        "comment": "Good performance overall.",
        "created_at": "2024-01-12T14:20:00.000000Z",
        "rater": {
          "id": 11,
          "username": "coach_mike",
          "first_name": "Mike",
          "last_name": "Smith",
          "profile_photo": null
        },
        "event": {
          "id": 44,
          "name": "Weekly Game",
          "date": "2024-01-08",
          "sport": "basketball"
        }
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 3,
      "per_page": 15,
      "total": 42,
      "from": 1,
      "to": 15
    },
    "summary": {
      "total_ratings": 42,
      "average_rating": 4.75,
      "rating_star": 4.5
    }
  }
}
```

### Error Response (404)

```json
{
  "status": "error",
  "message": "User not found"
}
```

## Frontend Implementation

### 1. Viewing Own Profile

```javascript
// Fetch ratings for current user's own profile
async function fetchMyRatings(page = 1) {
  const currentUsername = getCurrentUsername(); // Get from auth context/state
  
  try {
    const response = await fetch(
      `/api/profile/${currentUsername}/ratings?page=${page}&per_page=15`,
      {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${getAuthToken()}`,
          'Accept': 'application/json',
        }
      }
    );

    if (!response.ok) {
      throw new Error('Failed to fetch ratings');
    }

    const data = await response.json();
    return data.data;
  } catch (error) {
    console.error('Error fetching ratings:', error);
    throw error;
  }
}
```

### 2. Viewing Other User's Profile

```javascript
// Fetch ratings for another user's profile
// Note: Uses username (not userId) to match the profile route /profile/{username}
async function fetchUserRatings(username, page = 1) {
  try {
    const response = await fetch(
      `/api/profile/${username}/ratings?page=${page}&per_page=15`,
      {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${getAuthToken()}`,
          'Accept': 'application/json',
        }
      }
    );

    if (!response.ok) {
      throw new Error('Failed to fetch ratings');
    }

    const data = await response.json();
    return data.data;
  } catch (error) {
    console.error('Error fetching ratings:', error);
    throw error;
  }
}
```

### 3. React Component Example

```jsx
import { useState, useEffect } from 'react';
import axios from 'axios';

function ProfileRatings({ username, isOwnProfile = false }) {
  const [ratings, setRatings] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);

  useEffect(() => {
    fetchRatings(currentPage);
  }, [username, currentPage]);

  const fetchRatings = async (page) => {
    try {
      setLoading(true);
      setError(null);

      const response = await axios.get(
        `/api/profile/${username}/ratings`,
        {
          params: {
            page: page,
            per_page: 15
          },
          headers: {
            'Authorization': `Bearer ${getAuthToken()}`,
            'Accept': 'application/json',
          }
        }
      );

      if (response.data.status === 'success') {
        setRatings(response.data.data.ratings);
        setPagination(response.data.data.pagination);
        setSummary(response.data.data.summary);
      }
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to load ratings');
      console.error('Error fetching ratings:', err);
    } finally {
      setLoading(false);
    }
  };

  const handlePageChange = (newPage) => {
    setCurrentPage(newPage);
  };

  if (loading) {
    return <div>Loading ratings...</div>;
  }

  if (error) {
    return <div className="error">Error: {error}</div>;
  }

  return (
    <div className="profile-ratings">
      {/* Summary Section */}
      {summary && (
        <div className="ratings-summary">
          <h3>Rating Summary</h3>
          <div className="summary-stats">
            <div>
              <span className="label">Average Rating:</span>
              <span className="value">{summary.average_rating?.toFixed(2) || 'N/A'}</span>
            </div>
            <div>
              <span className="label">Total Ratings:</span>
              <span className="value">{summary.total_ratings}</span>
            </div>
            <div>
              <span className="label">Rating Star:</span>
              <span className="value">{summary.rating_star || 'N/A'}</span>
            </div>
          </div>
        </div>
      )}

      {/* Ratings List */}
      <div className="ratings-list">
        <h3>Player Ratings</h3>
        {ratings.length === 0 ? (
          <p>No ratings yet.</p>
        ) : (
          <>
            {ratings.map((rating) => (
              <div key={rating.id} className="rating-item">
                <div className="rating-header">
                  <div className="rater-info">
                    {rating.rater?.profile_photo ? (
                      <img 
                        src={rating.rater.profile_photo} 
                        alt={rating.rater.username}
                        className="rater-avatar"
                      />
                    ) : (
                      <div className="rater-avatar-placeholder">
                        {rating.rater?.first_name?.[0] || rating.rater?.username?.[0] || '?'}
                      </div>
                    )}
                    <div className="rater-details">
                      <div className="rater-name">
                        {rating.rater?.first_name && rating.rater?.last_name
                          ? `${rating.rater.first_name} ${rating.rater.last_name}`
                          : rating.rater?.username || 'Unknown User'}
                      </div>
                      {rating.event && (
                        <div className="event-name">
                          {rating.event.name} • {rating.event.sport}
                        </div>
                      )}
                    </div>
                  </div>
                  <div className="rating-value">
                    <span className="stars">{'★'.repeat(rating.rating)}</span>
                    <span className="rating-number">{rating.rating}/5</span>
                  </div>
                </div>
                {rating.comment && (
                  <div className="rating-comment">
                    "{rating.comment}"
                  </div>
                )}
                <div className="rating-date">
                  {new Date(rating.created_at).toLocaleDateString()}
                </div>
              </div>
            ))}

            {/* Pagination */}
            {pagination && pagination.last_page > 1 && (
              <div className="pagination">
                <button 
                  onClick={() => handlePageChange(currentPage - 1)}
                  disabled={currentPage === 1}
                >
                  Previous
                </button>
                <span>
                  Page {pagination.current_page} of {pagination.last_page}
                </span>
                <button 
                  onClick={() => handlePageChange(currentPage + 1)}
                  disabled={currentPage === pagination.last_page}
                >
                  Next
                </button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}

export default ProfileRatings;
```

### 4. Vue.js Component Example

```vue
<template>
  <div class="profile-ratings">
    <!-- Summary Section -->
    <div v-if="summary" class="ratings-summary">
      <h3>Rating Summary</h3>
      <div class="summary-stats">
        <div>
          <span class="label">Average Rating:</span>
          <span class="value">{{ summary.average_rating?.toFixed(2) || 'N/A' }}</span>
        </div>
        <div>
          <span class="label">Total Ratings:</span>
          <span class="value">{{ summary.total_ratings }}</span>
        </div>
      </div>
    </div>

    <!-- Ratings List -->
    <div class="ratings-list">
      <h3>Player Ratings</h3>
      <div v-if="loading">Loading...</div>
      <div v-else-if="error" class="error">{{ error }}</div>
      <div v-else-if="ratings.length === 0">
        <p>No ratings yet.</p>
      </div>
      <div v-else>
        <div 
          v-for="rating in ratings" 
          :key="rating.id" 
          class="rating-item"
        >
          <div class="rating-header">
            <div class="rater-info">
              <img 
                v-if="rating.rater?.profile_photo"
                :src="rating.rater.profile_photo" 
                :alt="rating.rater.username"
                class="rater-avatar"
              />
              <div v-else class="rater-avatar-placeholder">
                {{ rating.rater?.first_name?.[0] || rating.rater?.username?.[0] || '?' }}
              </div>
              <div class="rater-details">
                <div class="rater-name">
                  {{ getRaterName(rating.rater) }}
                </div>
                <div v-if="rating.event" class="event-name">
                  {{ rating.event.name }} • {{ rating.event.sport }}
                </div>
              </div>
            </div>
            <div class="rating-value">
              <span class="stars">{{ '★'.repeat(rating.rating) }}</span>
              <span class="rating-number">{{ rating.rating }}/5</span>
            </div>
          </div>
          <div v-if="rating.comment" class="rating-comment">
            "{{ rating.comment }}"
          </div>
          <div class="rating-date">
            {{ formatDate(rating.created_at) }}
          </div>
        </div>

        <!-- Pagination -->
        <div v-if="pagination && pagination.last_page > 1" class="pagination">
          <button 
            @click="changePage(currentPage - 1)"
            :disabled="currentPage === 1"
          >
            Previous
          </button>
          <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
          <button 
            @click="changePage(currentPage + 1)"
            :disabled="currentPage === pagination.last_page"
          >
            Next
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

export default {
  name: 'ProfileRatings',
  props: {
    username: {
      type: String,
      required: true
    },
    isOwnProfile: {
      type: Boolean,
      default: false
    }
  },
  data() {
    return {
      ratings: [],
      pagination: null,
      summary: null,
      loading: true,
      error: null,
      currentPage: 1
    };
  },
  mounted() {
    this.fetchRatings();
  },
  watch: {
    username() {
      this.currentPage = 1;
      this.fetchRatings();
    },
    currentPage() {
      this.fetchRatings();
    }
  },
  methods: {
    async fetchRatings() {
      try {
        this.loading = true;
        this.error = null;

        const response = await axios.get(
          `/api/profile/${this.username}/ratings`,
          {
            params: {
              page: this.currentPage,
              per_page: 15
            },
            headers: {
              'Authorization': `Bearer ${this.getAuthToken()}`,
              'Accept': 'application/json',
            }
          }
        );

        if (response.data.status === 'success') {
          this.ratings = response.data.data.ratings;
          this.pagination = response.data.data.pagination;
          this.summary = response.data.data.summary;
        }
      } catch (err) {
        this.error = err.response?.data?.message || 'Failed to load ratings';
        console.error('Error fetching ratings:', err);
      } finally {
        this.loading = false;
      }
    },
    changePage(newPage) {
      this.currentPage = newPage;
    },
    getRaterName(rater) {
      if (!rater) return 'Unknown User';
      if (rater.first_name && rater.last_name) {
        return `${rater.first_name} ${rater.last_name}`;
      }
      return rater.username || 'Unknown User';
    },
    formatDate(dateString) {
      return new Date(dateString).toLocaleDateString();
    },
    getAuthToken() {
      // Implement your token retrieval logic
      return localStorage.getItem('auth_token');
    }
  }
};
</script>
```

## Key Points

1. **Same Endpoint for Both Cases**: The endpoint `/api/profile/{username}/ratings` works for both viewing your own profile and viewing others' profiles. Just pass the appropriate `username` (matches the profile route `/profile/{username}`).

2. **Rater Information**: Each rating includes full rater information (username, name, profile photo) so you can display who gave the rating.

3. **Event Context**: Each rating includes the event it was given for, providing context about when/where the rating was given.

4. **Pagination**: The endpoint supports pagination with `per_page` and `page` query parameters.

5. **Summary Stats**: The response includes summary statistics (total ratings, average rating) that you can display at the top of the ratings section.

6. **Comments**: Ratings may or may not have comments. Always check if `comment` exists before displaying it.

## Display Recommendations

- Show the rater's profile photo or avatar
- Display the rater's name (prefer full name if available, fallback to username)
- Show the event name and sport for context
- Display the rating value (1-5 stars) prominently
- Show the comment if available
- Include the date the rating was given
- Use pagination for better performance with many ratings
