# Web Push Notifications Implementation

## Overview
This document describes the web push notification implementation for the Laravel backend. The system automatically sends push notifications to users when `UserNotification` records are created.

## Implementation Summary

### Backend Components Created

1. **Database Migration** (`database/migrations/*_create_push_subscriptions_table.php`)
   - Stores user push subscriptions with `user_id`, `endpoint`, `p256dh`, and `auth` fields
   - Indexed by `user_id` for efficient lookups

2. **PushSubscription Model** (`app/Models/PushSubscription.php`)
   - Eloquent model for managing push subscriptions
   - Belongs to User

3. **PushNotificationController** (`app/Http/Controllers/PushNotificationController.php`)
   - `GET /api/push/vapid` - Returns VAPID public key from environment
   - `POST /api/push/subscribe` - Stores user's push subscription
   - `POST /api/push/unsubscribe` - Removes user's push subscription

4. **PushNotificationService** (`app/Services/PushNotificationService.php`)
   - Handles sending push notifications using `web-push` library
   - Automatically maps notification types to titles, bodies, and URLs
   - Handles invalid subscriptions (404/410) by removing them from database

5. **UserNotificationObserver** (`app/Observers/UserNotificationObserver.php`)
   - Automatically sends push notifications when `UserNotification` records are created
   - Registered in `AppServiceProvider`

6. **Routes** (`routes/api.php`)
   - All push routes are protected with `auth:api` middleware

## Setup Instructions

### 1. Install Dependencies

```bash
composer install
```

This will install the `minishlink/web-push` package added to `composer.json`.

### 2. Generate VAPID Keys

You need to generate VAPID keys **once** and store them in your `.env` file. You can use any of the following methods:

**Option A: Online generator (Recommended - Works on all platforms)**
Visit: https://web-push-codelab.glitch.me/

This is the most reliable option, especially on Windows where OpenSSL may not be properly configured. Simply visit the link, click "Generate VAPID Keys", and copy the keys.

**Option B: Using npx (Node.js required)**
```bash
npx web-push generate-vapid-keys
```

**Option C: Using Artisan command**
```bash
php artisan push:generate-vapid-keys
```

**Note:** The Artisan command may fail on Windows due to OpenSSL configuration issues. If you get an error, use Option A (online generator) instead.

**Option D: Using PHP script (may not work on Windows)**
Create a temporary file `generate-vapid.php`:
```php
<?php
require 'vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();
echo "VAPID_PUBLIC_KEY=" . $keys['publicKey'] . "\n";
echo "VAPID_PRIVATE_KEY=" . $keys['privateKey'] . "\n";
```

Run: `php generate-vapid.php`

**Note:** If you get OpenSSL errors on Windows, use Option C (online generator) instead.

### 3. Add to .env File

Add the following to your `.env` file:

```env
VAPID_PUBLIC_KEY=your_public_key_here
VAPID_PRIVATE_KEY=your_private_key_here
VAPID_EMAIL=mailto:your-email@example.com
```

**Important Notes:**
- The `VAPID_EMAIL` should be a `mailto:` URL (e.g., `mailto:admin@example.com`)
- **DO NOT** generate new keys per request - use the same keys for all requests
- Keep the private key secure and never commit it to version control

### 4. Run Migration

```bash
php artisan migrate
```

This will create the `push_subscriptions` table.

## How It Works

### Frontend Flow

1. **Service Worker Registration**: Frontend registers service worker (already done)
2. **Request Permission**: Frontend calls `Notification.requestPermission()`
3. **Get VAPID Key**: Frontend calls `GET /api/push/vapid` to get public key
4. **Subscribe**: Frontend calls `navigator.serviceWorker.ready.then(registration => registration.pushManager.subscribe(...))`
5. **Send Subscription**: Frontend calls `POST /api/push/subscribe` with subscription JSON
6. **Service Worker Handles Push**: Service worker listens for `push` events and shows notifications

### Backend Flow

1. **Notification Created**: When a `UserNotification` is created (anywhere in the codebase)
2. **Observer Triggered**: `UserNotificationObserver::created()` is automatically called
3. **Push Sent**: Observer calls `PushNotificationService::sendToUser()` which:
   - Loads all push subscriptions for that user
   - Queues push notifications for each subscription
   - Sends all queued notifications
   - Removes invalid subscriptions (404/410 responses)

## API Endpoints

### GET /api/push/vapid
Returns the VAPID public key.

**Response:**
```json
{
  "status": "success",
  "publicKey": "BEl62iUYgUivxIkv69yViEuiBIa40HI..."
}
```

### POST /api/push/subscribe
Stores a user's push subscription.

**Request Body:**
```json
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/...",
  "keys": {
    "p256dh": "BNJxwH...",
    "auth": "8GDH..."
  }
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Subscription saved successfully",
  "subscription": { ... }
}
```

### POST /api/push/unsubscribe
Removes a user's push subscription.

**Request Body:**
```json
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/..."
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Subscription removed successfully",
  "deleted": true
}
```

## Notification Payload Structure

Push notifications are sent with the following payload:

```json
{
  "title": "Notification Title",
  "body": "Notification message",
  "data": {
    "url": "/events/123",
    "notificationId": "uuid",
    "type": "event_joined"
  },
  "icon": "/favicon.ico"
}
```

The `data.url` field is used by the service worker's `notificationclick` handler to navigate to the correct page.

## Supported Notification Types

The system automatically maps notification types to titles, bodies, and URLs. All notification types that create `UserNotification` records will automatically trigger push notifications, including:

- Message notifications (`message_received`)
- Event notifications (`event_joined`, `team_invitation`, etc.)
- Booking notifications (`booking_approved`, `booking_rejected`, etc.)
- Post notifications (`post_liked`, `post_commented`)
- Document notifications (`document_verified`, `document_rejected`, etc.)
- Tournament notifications (`tournament_announcement`, etc.)
- Coach notifications (`coach_match`, etc.)
- And all other notification types

## Error Handling

- Invalid subscriptions (404/410 from push service) are automatically removed
- Push notification failures are logged but don't prevent notification creation
- Missing VAPID keys are logged as warnings

## Testing

To test push notifications:

1. Ensure VAPID keys are configured in `.env`
2. Subscribe a user via the frontend
3. Create a notification (e.g., send a message, join an event)
4. Check browser console and Laravel logs for any errors

## Troubleshooting

### Push notifications not sending
- Check that VAPID keys are set in `.env`
- Verify subscriptions exist in `push_subscriptions` table
- Check Laravel logs for errors
- Ensure service worker is registered and active

### Invalid subscription errors
- Subscriptions are automatically cleaned up when they return 404/410
- User needs to re-subscribe if their subscription expires

### VAPID key errors
- Ensure keys are properly formatted (no extra spaces/newlines)
- Verify `VAPID_EMAIL` starts with `mailto:`
- Keys must be the same across all requests (don't regenerate)

## Security Notes

- All push routes require authentication (`auth:api` middleware)
- Subscriptions are tied to authenticated users
- VAPID private key should never be exposed to frontend
- Only the public key is returned to frontend

