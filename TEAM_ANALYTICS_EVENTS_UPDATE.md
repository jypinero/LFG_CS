# Team Analytics Report API - Events Enhancement Update

## Summary
The Team Analytics Report API has been enhanced to include **venue and facility details** for all events. The frontend should update to display this additional information.

## What Changed

### Before
Events only included basic information:
- `venue_id` (just the ID)
- Basic event details (name, date, times, etc.)

### After
Events now include:
- ✅ **Full venue details** (name, address, coordinates)
- ✅ **Facility details** (name, type, venue_id)
- ✅ **Event status** (upcoming, ongoing, completed, cancelled)
- ✅ **Additional event fields** (is_approved, cancelled_at, slots, tournament_id)

## Updated Response Structure

### Events Object (Enhanced)
```json
{
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
        "status": "upcoming",
        "is_approved": true,
        "cancelled_at": null,
        "slots": 20,
        "tournament_id": 123,
        "venue": {
          "id": 201,
          "name": "Sports Arena",
          "address": "123 Main St, City, State",
          "latitude": 40.7128,
          "longitude": -74.0060
        },
        "facility": {
          "id": 301,
          "name": "Court 1",
          "type": "basketball_court",
          "venue_id": 201
        },
        "venue_id": 201,
        "facility_id": 301,
        "group": "A",
        "participated_at": "2024-01-05T12:00:00.000000Z"
      }
    ],
    "upcoming": [...],
    "past": [...]
  }
}
```

## New Fields Reference

### Event Status
- `"upcoming"` - Event date/time is in the future
- `"ongoing"` - Event is currently happening (between start and end time)
- `"completed"` - Event date/time has passed
- `"cancelled"` - Event was cancelled (cancelled_at is not null)
- `"scheduled"` - Default status if date/time logic cannot be determined

### Venue Object
- `id` - Venue ID
- `name` - Venue name
- `address` - Venue address
- `latitude` - Venue latitude coordinate
- `longitude` - Venue longitude coordinate
- **Note**: `venue` can be `null` if event has no venue

### Facility Object
- `id` - Facility ID
- `name` - Facility name
- `type` - Facility type (e.g., "basketball_court", "soccer_field")
- `venue_id` - Parent venue ID
- **Note**: `facility` can be `null` if event has no facility

### Additional Event Fields
- `status` - Computed event status (string)
- `is_approved` - Boolean indicating if event is approved
- `cancelled_at` - Cancellation timestamp (null if not cancelled)
- `slots` - Number of available slots
- `tournament_id` - Tournament ID if event is part of a tournament (null otherwise)

## Frontend Implementation Guide

### 1. Update Event Display Component

```javascript
function EventCard({ event }) {
  return (
    <div className="event-card">
      <h3>{event.name}</h3>
      <p>{event.description}</p>
      
      {/* Display Status Badge */}
      <span className={`status-badge status-${event.status}`}>
        {event.status}
      </span>
      
      {/* Display Venue Information */}
      {event.venue && (
        <div className="venue-info">
          <strong>Venue:</strong> {event.venue.name}
          <br />
          <small>{event.venue.address}</small>
        </div>
      )}
      
      {/* Display Facility Information */}
      {event.facility && (
        <div className="facility-info">
          <strong>Facility:</strong> {event.facility.name} 
          <span className="facility-type">({event.facility.type})</span>
        </div>
      )}
      
      {/* Display Date and Time */}
      <div className="event-datetime">
        <strong>Date:</strong> {new Date(event.date).toLocaleDateString()}
        <br />
        <strong>Time:</strong> {event.start_time} - {event.end_time}
      </div>
      
      {/* Display Tournament Link if applicable */}
      {event.tournament_id && (
        <div className="tournament-link">
          Part of Tournament #{event.tournament_id}
        </div>
      )}
      
      {/* Show cancellation notice */}
      {event.cancelled_at && (
        <div className="cancelled-notice">
          ⚠️ This event was cancelled
        </div>
      )}
    </div>
  );
}
```

### 2. Update Report Display

```javascript
function TeamReport({ reportData }) {
  const { events } = reportData;
  
  return (
    <div className="team-report">
      {/* Upcoming Events Section */}
      <section className="upcoming-events">
        <h2>Upcoming Events ({events.upcoming.length})</h2>
        {events.upcoming.map(event => (
          <EventCard key={event.event_id} event={event} />
        ))}
      </section>
      
      {/* Past Events Section */}
      <section className="past-events">
        <h2>Past Events ({events.past.length})</h2>
        {events.past.map(event => (
          <EventCard key={event.event_id} event={event} />
        ))}
      </section>
    </div>
  );
}
```

### 3. Add Status Styling

```css
.status-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: bold;
  text-transform: uppercase;
}

.status-upcoming {
  background-color: #4CAF50;
  color: white;
}

.status-ongoing {
  background-color: #FF9800;
  color: white;
}

.status-completed {
  background-color: #9E9E9E;
  color: white;
}

.status-cancelled {
  background-color: #F44336;
  color: white;
}

.status-scheduled {
  background-color: #2196F3;
  color: white;
}
```

### 4. Handle Null Values

```javascript
// Always check for null values before displaying
{event.venue && (
  <div>Venue: {event.venue.name}</div>
)}

{event.facility && (
  <div>Facility: {event.facility.name}</div>
)}

// Or use optional chaining
<div>Venue: {event.venue?.name || 'Not specified'}</div>
<div>Facility: {event.facility?.name || 'Not specified'}</div>
```

### 5. Display Venue on Map (if applicable)

```javascript
function EventMap({ event }) {
  if (!event.venue || !event.venue.latitude || !event.venue.longitude) {
    return <div>No location available</div>;
  }
  
  return (
    <Map
      center={[event.venue.latitude, event.venue.longitude]}
      zoom={15}
    >
      <Marker
        position={[event.venue.latitude, event.venue.longitude]}
        title={event.venue.name}
      />
    </Map>
  );
}
```

## Migration Checklist

- [ ] Update TypeScript interfaces/types for Event objects
- [ ] Update event display components to show venue details
- [ ] Update event display components to show facility details
- [ ] Add status badges/styling for event status
- [ ] Handle null venue/facility gracefully
- [ ] Update report print layout to include venue/facility info
- [ ] Test with events that have no venue/facility
- [ ] Test with cancelled events
- [ ] Test with tournament events
- [ ] Update any event filtering/sorting logic if needed

## Breaking Changes

**None** - This is a backward-compatible enhancement. Existing code will continue to work, but you should update to take advantage of the new fields.

## Notes

1. **Null Safety**: Always check if `venue` or `facility` exists before accessing their properties
2. **Status Logic**: The status is computed on the backend, so you can trust it directly
3. **Backward Compatibility**: Old code using `venue_id` will still work, but you should migrate to using the `venue` object
4. **Performance**: The venue and facility data is eager-loaded, so there are no additional API calls needed

## Example: Complete Event Display

```javascript
function EnhancedEventCard({ event }) {
  const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    });
  };

  const formatTime = (timeString) => {
    return new Date(`2000-01-01T${timeString}`).toLocaleTimeString('en-US', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
    });
  };

  return (
    <div className="enhanced-event-card">
      <div className="event-header">
        <h3>{event.name}</h3>
        <span className={`status-badge status-${event.status}`}>
          {event.status}
        </span>
      </div>
      
      {event.description && (
        <p className="event-description">{event.description}</p>
      )}
      
      <div className="event-details">
        <div className="detail-row">
          <strong>Sport:</strong> {event.sport}
        </div>
        <div className="detail-row">
          <strong>Type:</strong> {event.event_type}
        </div>
        <div className="detail-row">
          <strong>Date:</strong> {formatDate(event.date)}
        </div>
        <div className="detail-row">
          <strong>Time:</strong> {formatTime(event.start_time)} - {formatTime(event.end_time)}
        </div>
        
        {event.venue && (
          <div className="detail-row venue-row">
            <strong>Venue:</strong>
            <div className="venue-details">
              <div>{event.venue.name}</div>
              {event.venue.address && (
                <small>{event.venue.address}</small>
              )}
            </div>
          </div>
        )}
        
        {event.facility && (
          <div className="detail-row facility-row">
            <strong>Facility:</strong> {event.facility.name}
            <span className="facility-type">({event.facility.type})</span>
          </div>
        )}
        
        {event.tournament_id && (
          <div className="detail-row">
            <strong>Tournament:</strong> #{event.tournament_id}
          </div>
        )}
      </div>
      
      {event.cancelled_at && (
        <div className="cancelled-notice">
          ⚠️ This event was cancelled on {new Date(event.cancelled_at).toLocaleDateString()}
        </div>
      )}
    </div>
  );
}
```

## Support

For questions or issues, contact the backend development team.
