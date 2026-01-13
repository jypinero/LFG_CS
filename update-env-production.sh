#!/bin/bash

# Auto-update .env for production
# Run this on your server: bash update-env-production.sh

APP_DIR="/home/user/htdocs/srv1266167.hstgr.cloud"
ENV_FILE="$APP_DIR/.env"

echo "Updating .env file for production..."

cd "$APP_DIR"

if [ ! -f "$ENV_FILE" ]; then
    echo "Error: .env file not found at $ENV_FILE"
    exit 1
fi

# Backup .env first
cp "$ENV_FILE" "$ENV_FILE.backup.$(date +%Y%m%d_%H%M%S)"
echo "Backup created"

# Update APP_ENV
sed -i 's/^APP_ENV=.*/APP_ENV=production/' "$ENV_FILE"

# Update APP_DEBUG
sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' "$ENV_FILE"

# Update APP_URL
sed -i 's|^APP_URL=.*|APP_URL=https://www.lfg-ph.games|' "$ENV_FILE"

# Update FRONTEND_URL (remove trailing slash if present)
sed -i 's|^FRONTEND_URL=.*|FRONTEND_URL=https://lfg-theta.vercel.app|' "$ENV_FILE"

# Update GOOGLE_REDIRECT_URI
sed -i 's|^GOOGLE_REDIRECT_URI=.*|GOOGLE_REDIRECT_URI=https://www.lfg-ph.games/api/auth/google/callback|' "$ENV_FILE"

# Update CHALLONGE_REDIRECT_URI
sed -i 's|^CHALLONGE_REDIRECT_URI=.*|CHALLONGE_REDIRECT_URI=https://www.lfg-ph.games/api/auth/challonge/callback|' "$ENV_FILE"

# Update LOG_LEVEL
sed -i 's/^LOG_LEVEL=.*/LOG_LEVEL=error/' "$ENV_FILE"

# Disable AI (optional - uncomment if needed)
# sed -i 's/^AI_ENABLED=.*/AI_ENABLED=false/' "$ENV_FILE"
# sed -i 's/^AI_PROCESS_ON_UPLOAD=.*/AI_PROCESS_ON_UPLOAD=false/' "$ENV_FILE"

echo "✓ .env file updated!"

# Clear and cache config
echo "Clearing and caching configuration..."
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo ""
echo "✓ Done! Configuration updated."
echo ""
echo "Test your API:"
echo "curl https://www.lfg-ph.games/api/me"
echo ""
