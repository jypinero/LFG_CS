# Deployment Notes for Push Notifications

## Important: Vercel is for Frontend, Not Laravel Backend

**Vercel** is designed for frontend applications (Next.js, React, Vue, etc.), not Laravel PHP backends. Your Laravel backend needs to be deployed elsewhere.

### Recommended Backend Deployment Options:

1. **Railway** (Recommended for easy setup)
   - https://railway.app
   - Easy Laravel deployment
   - Built-in database support
   - Environment variable management

2. **Fly.io**
   - https://fly.io
   - Good for Laravel apps
   - Global edge deployment

3. **Laravel Forge**
   - https://forge.laravel.com
   - Managed Laravel hosting
   - Easy deployments

4. **DigitalOcean App Platform**
   - https://www.digitalocean.com/products/app-platform
   - Simple Laravel deployment

5. **Traditional VPS** (DigitalOcean, Linode, AWS EC2)
   - Full control
   - Requires server management

## Backend Deployment Checklist

### 1. Environment Variables
Make sure these are set in your backend deployment:

```env
VAPID_PUBLIC_KEY=your_public_key_here
VAPID_PRIVATE_KEY=your_private_key_here
VAPID_EMAIL=mailto:your-email@example.com
```

**Important:** Use the **same VAPID keys** in production as you used in development, or users will need to re-subscribe.

### 2. Database Migration
Run migrations on your production server:

```bash
php artisan migrate
```

This will create the `push_subscriptions` table.

### 3. HTTPS Required
Push notifications **only work over HTTPS**. Your backend must:
- Have a valid SSL certificate
- Be accessible via `https://` (not `http://`)

### 4. CORS Configuration
If your frontend is on a different domain (e.g., Vercel), ensure CORS is configured:

Check `config/cors.php`:
```php
'allowed_origins' => [
    'https://your-frontend.vercel.app',
    'https://your-production-domain.com',
],
```

### 5. Queue Configuration (Optional but Recommended)
For better performance, you might want to queue push notifications:

1. Set up queue driver in `.env`:
```env
QUEUE_CONNECTION=database
```

2. Run queue worker:
```bash
php artisan queue:work
```

Or use a process manager like Supervisor.

## Frontend Deployment (Vercel)

### 1. Environment Variables
Set your backend API URL:

```env
NEXT_PUBLIC_API_URL=https://your-backend-api.com
# or
VITE_API_URL=https://your-backend-api.com
```

### 2. Service Worker
Ensure your service worker is properly registered and accessible:
- Service worker must be served from the root domain
- Must be accessible via HTTPS
- Vercel automatically handles this for Next.js PWA

### 3. API Endpoints
Update your frontend API calls to point to your production backend:
- `/api/push/vapid` → `https://your-backend-api.com/api/push/vapid`
- `/api/push/subscribe` → `https://your-backend-api.com/api/push/subscribe`
- `/api/push/unsubscribe` → `https://your-backend-api.com/api/push/unsubscribe`

## Testing in Production

1. **Subscribe to Push Notifications**
   - Visit your production frontend
   - Click "Enable Push Notifications"
   - Grant permission

2. **Test Welcome Notification**
   - Log in to your production app
   - Or visit `/home` page
   - You should receive a push notification

3. **Test Other Notifications**
   - Send a message
   - Join an event
   - Any action that creates a notification

## Common Issues

### Push Notifications Not Working

1. **Check HTTPS**
   - Both frontend and backend must use HTTPS
   - Localhost is exception for development

2. **Check VAPID Keys**
   - Keys must be set in production environment
   - Keys must match between environments (or users re-subscribe)

3. **Check Service Worker**
   - Service worker must be registered
   - Check browser console for errors

4. **Check Subscriptions**
   - Verify subscription exists in database:
   ```sql
   SELECT * FROM push_subscriptions WHERE user_id = ?;
   ```

5. **Check Backend Logs**
   - Look for errors in Laravel logs
   - Check if push service is being called

### CORS Errors

If you see CORS errors:
- Update `config/cors.php` with your frontend domain
- Clear config cache: `php artisan config:clear`

### 404/410 Errors

If subscriptions return 404/410:
- This is normal - expired subscriptions are automatically cleaned up
- User needs to re-subscribe

## Vercel-Specific Notes

Since Vercel is for frontend:

1. **Your Laravel backend must be deployed separately** (not on Vercel)
2. **Frontend on Vercel** can call your backend API
3. **Service Worker** works fine on Vercel for Next.js PWA
4. **Environment Variables** in Vercel should point to your backend API

## Quick Deployment Test

1. Deploy backend with VAPID keys configured
2. Deploy frontend to Vercel
3. Test push notification subscription
4. Log in or visit `/home` to trigger welcome notification
5. Verify push notification appears

---

**Remember:** Push notifications require HTTPS, so both your frontend (Vercel) and backend must be served over HTTPS. Vercel automatically provides HTTPS, but you need to ensure your backend also has SSL configured.

