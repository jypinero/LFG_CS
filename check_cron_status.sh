#!/bin/bash

echo "=== Cron Status Check ==="
echo ""

echo "1. Checking crontab entries:"
echo "------------------------------"
crontab -l 2>/dev/null || echo "No crontab entries found"
echo ""

echo "2. Checking if Laravel scheduler is in crontab:"
echo "------------------------------"
if crontab -l 2>/dev/null | grep -q "schedule:run"; then
    echo "✓ Laravel scheduler found in crontab"
    crontab -l 2>/dev/null | grep "schedule:run"
else
    echo "✗ Laravel scheduler NOT found in crontab"
    echo "  You need to add: * * * * * cd /home/user/htdocs/srv1266167.hstgr.cloud && php artisan schedule:run >> /dev/null 2>&1"
fi
echo ""

echo "3. Testing scheduler manually:"
echo "------------------------------"
php artisan schedule:run -v
echo ""

echo "4. Checking scheduled tasks:"
echo "------------------------------"
php artisan schedule:list | grep -i "NotifyParticipantsToRate" || echo "Job not found in schedule list"
echo ""

echo "5. Recent scheduler activity in logs (last 50 lines):"
echo "------------------------------"
tail -n 50 storage/logs/laravel.log | grep -i "schedule\|NotifyParticipantsToRate\|venue rating" || echo "No recent scheduler activity found"
echo ""

echo "6. Recent venue rating notifications (last hour):"
echo "------------------------------"
php artisan tinker --execute="
\$count = \App\Models\Notification::where('type', 'rate_venue')
    ->where('created_at', '>=', now()->subHour())
    ->count();
echo 'Notifications created in last hour: ' . \$count . PHP_EOL;
"
echo ""

echo "7. Events marked as notified (last hour):"
echo "------------------------------"
php artisan tinker --execute="
\$count = \App\Models\Event::where('is_rating_notified', true)
    ->where('updated_at', '>=', now()->subHour())
    ->count();
echo 'Events marked as notified in last hour: ' . \$count . PHP_EOL;
"
echo ""

echo "8. Eligible events waiting to be processed:"
echo "------------------------------"
php artisan tinker --execute="
\$now = now();
\$count = \App\Models\Event::where('is_approved', true)
    ->whereNull('cancelled_at')
    ->whereRaw(\"CONCAT(date,' ',end_time) < ?\", [\$now->format('Y-m-d H:i:s')])
    ->where('is_rating_notified', false)
    ->count();
echo 'Events waiting to be processed: ' . \$count . PHP_EOL;
"
echo ""

echo "=== Check Complete ==="
