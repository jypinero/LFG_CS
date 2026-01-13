<?php

/**
 * Helper script to check which migrations might fail due to existing tables
 * Run this after importing a SQL dump to identify problematic migrations
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

$migrationsPath = __DIR__ . '/database/migrations';
$migrationFiles = glob($migrationsPath . '/*.php');

$issues = [];
$checked = 0;

foreach ($migrationFiles as $file) {
    $content = file_get_contents($file);
    
    // Check if migration uses Schema::create without hasTable check
    if (preg_match('/Schema::create\([\'"]([^\'"]+)[\'"]/', $content, $matches)) {
        $tableName = $matches[1];
        
        // Check if it has a hasTable check
        if (!preg_match('/Schema::hasTable\([\'"]' . preg_quote($tableName, '/') . '[\'"]/', $content)) {
            $checked++;
            
            // Check if table exists in database
            try {
                if (Schema::hasTable($tableName)) {
                    $issues[] = [
                        'file' => basename($file),
                        'table' => $tableName,
                        'status' => 'EXISTS - Will fail'
                    ];
                }
            } catch (\Exception $e) {
                // Ignore connection errors
            }
        }
    }
}

echo "Migration Check Results:\n";
echo "========================\n\n";
echo "Checked migrations: $checked\n";
echo "Potential issues found: " . count($issues) . "\n\n";

if (count($issues) > 0) {
    echo "Migrations that will fail (table already exists):\n";
    echo "------------------------------------------------\n";
    foreach ($issues as $issue) {
        echo sprintf("- %s (table: %s)\n", $issue['file'], $issue['table']);
    }
    echo "\n";
    echo "Fix: Add 'if (!Schema::hasTable(\"' . \$tableName . '\"))' check before Schema::create()\n";
} else {
    echo "No issues found! All migrations should run successfully.\n";
}
