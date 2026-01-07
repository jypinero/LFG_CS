<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\PushSubscription;

echo "Checking subscription keys in database...\n";
echo "==========================================\n\n";

$subscriptions = PushSubscription::all();

if ($subscriptions->isEmpty()) {
    echo "❌ No subscriptions found in database\n";
    exit(1);
}

echo "Found " . $subscriptions->count() . " subscription(s)\n\n";

foreach ($subscriptions as $sub) {
    echo "Subscription ID: {$sub->id}\n";
    echo "User ID: {$sub->user_id}\n";
    echo "Endpoint: " . substr($sub->endpoint, 0, 60) . "...\n";
    echo "p256dh length: " . strlen($sub->p256dh) . "\n";
    echo "auth length: " . strlen($sub->auth) . "\n";
    echo "p256dh: {$sub->p256dh}\n";
    echo "auth: {$sub->auth}\n";
    echo "---\n\n";
    
    // Test if this subscription can be created
    try {
        $testSub = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $sub->endpoint,
            'keys' => [
                'p256dh' => $sub->p256dh,
                'auth' => $sub->auth,
            ],
        ]);
        echo "✅ Subscription object created successfully\n\n";
    } catch (\Exception $e) {
        echo "❌ Failed to create subscription object\n";
        echo "Error: " . $e->getMessage() . "\n\n";
    }
}
