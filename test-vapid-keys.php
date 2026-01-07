<?php

require __DIR__.'/vendor/autoload.php';

use Dotenv\Dotenv;
use Minishlink\WebPush\WebPush;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$publicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? null;
$privateKey = $_ENV['VAPID_PRIVATE_KEY'] ?? null;
$email = $_ENV['VAPID_EMAIL'] ?? 'mailto:admin@example.com';

echo "Testing VAPID keys...\n";
echo "==================\n\n";

if (!$publicKey || !$privateKey) {
    echo "❌ VAPID keys not found in .env\n";
    exit(1);
}

echo "Public Key Length: " . strlen($publicKey) . "\n";
echo "Private Key Length: " . strlen($privateKey) . "\n";
echo "Public Key Sample: " . substr($publicKey, 0, 30) . "...\n";
echo "Private Key Sample: " . substr($privateKey, 0, 30) . "...\n";
echo "Email: $email\n\n";

// Test if keys work with WebPush
echo "Testing WebPush initialization...\n";

try {
    $webPush = new WebPush([
        'VAPID' => [
            'subject' => $email,
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
        ],
    ]);
    
    echo "✅ WebPush initialized successfully!\n";
    echo "✅ VAPID keys are valid!\n\n";
    
    echo "Now testing with a dummy subscription...\n";
    
    // Create a test subscription (won't actually send, just test encryption)
    $testSubscription = \Minishlink\WebPush\Subscription::create([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test',
        'keys' => [
            'p256dh' => 'BCKZb0bp1JtqFJn7n4XghhuDMf9T2YWrN_q2eGgF8vL7M0dGvWqT6wE',
            'auth' => 'Mpx3ARinYcVTIxBdJA',
        ],
    ]);
    
    $webPush->queueNotification($testSubscription, json_encode(['title' => 'Test']));
    echo "✅ Test notification queued (not sent)\n";
    echo "✅ All systems working!\n";
    
} catch (\Exception $e) {
    echo "❌ VAPID keys or WebPush configuration is INVALID!\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    echo "\n🔧 Fix: The keys might need to be regenerated using:\n";
    echo "  php artisan push:generate-vapid-keys\n";
}

