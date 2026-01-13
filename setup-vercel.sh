#!/bin/bash

# Vercel Connection Setup Script
# Run this on your VPS: bash setup-vercel.sh

echo "========================================="
echo "Vercel Connection Setup"
echo "========================================="

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Find Laravel app directory
echo -e "${YELLOW}Searching for Laravel app...${NC}"

APP_DIR=""
if [ -f "/var/www/html/artisan" ]; then
    APP_DIR="/var/www/html"
elif [ -f "/var/www/artisan" ]; then
    APP_DIR="/var/www"
elif [ -f "$HOME/artisan" ]; then
    APP_DIR="$HOME"
elif [ -f "./artisan" ]; then
    APP_DIR="$(pwd)"
else
    echo -e "${RED}Laravel app not found!${NC}"
    echo "Please run this script from your Laravel app directory"
    echo "Or update APP_DIR variable in this script"
    exit 1
fi

echo -e "${GREEN}Found Laravel app at: $APP_DIR${NC}"
cd "$APP_DIR"

# Check if .env exists
if [ ! -f ".env" ]; then
    echo -e "${YELLOW}Creating .env from .env.example...${NC}"
    if [ -f ".env.example" ]; then
        cp .env.example .env
    else
        echo -e "${RED}.env.example not found!${NC}"
        exit 1
    fi
fi

# Update APP_URL
echo -e "${YELLOW}Updating APP_URL...${NC}"
if grep -q "APP_URL=" .env; then
    sed -i 's|APP_URL=.*|APP_URL=http://72.62.242.66|' .env
else
    echo "APP_URL=http://72.62.242.66" >> .env
fi

# Update FRONTEND_URL
echo -e "${YELLOW}Updating FRONTEND_URL...${NC}"
if grep -q "FRONTEND_URL=" .env; then
    sed -i 's|FRONTEND_URL=.*|FRONTEND_URL=https://lfg-theta.vercel.app|' .env
else
    echo "FRONTEND_URL=https://lfg-theta.vercel.app" >> .env
fi

# Generate key if not set
if ! grep -q "APP_KEY=base64:" .env; then
    echo -e "${YELLOW}Generating application key...${NC}"
    php artisan key:generate --force
fi

# Set permissions
echo -e "${YELLOW}Setting permissions...${NC}"
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || chown -R $USER:www-data storage bootstrap/cache 2>/dev/null

# Link storage
if [ ! -L "public/storage" ]; then
    echo -e "${YELLOW}Linking storage...${NC}"
    php artisan storage:link
fi

# Clear and cache config
echo -e "${YELLOW}Clearing and caching configuration...${NC}"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Test API
echo -e "${YELLOW}Testing API endpoint...${NC}"
RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" http://72.62.242.66/api/me 2>/dev/null || echo "000")

if [ "$RESPONSE" = "401" ] || [ "$RESPONSE" = "200" ]; then
    echo -e "${GREEN}✓ API is responding! (Status: $RESPONSE)${NC}"
elif [ "$RESPONSE" = "000" ]; then
    echo -e "${YELLOW}⚠ Could not test API (might need web server restart)${NC}"
else
    echo -e "${YELLOW}⚠ API returned status: $RESPONSE${NC}"
fi

echo ""
echo -e "${GREEN}========================================="
echo "Setup Complete!"
echo "=========================================${NC}"
echo ""
echo "Next steps:"
echo "1. Add to Vercel Environment Variables:"
echo "   NEXT_PUBLIC_API_URL=http://72.62.242.66/api"
echo ""
echo "2. Restart web server (if needed):"
echo "   systemctl restart nginx"
echo "   # or"
echo "   systemctl restart apache2"
echo ""
echo "3. Test from frontend!"
echo ""
