<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Notification;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CleanupOldNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:cleanup {--days=30 : Number of days to keep notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup notifications older than specified days (default: 30 days)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("Starting cleanup of notifications older than {$days} days (before {$cutoffDate->toDateString()})...");

        // Use database transaction for safety
        DB::beginTransaction();
        
        try {
            // Count notifications to be deleted (using query for efficiency)
            $count = Notification::where('created_at', '<', $cutoffDate)->count();
            
            if ($count === 0) {
                $this->info("No old notifications found to cleanup.");
                DB::rollBack();
                return Command::SUCCESS;
            }

            $this->info("Found {$count} notification(s) to cleanup.");

            // Get notification IDs in chunks to avoid memory issues
            $notificationIds = Notification::where('created_at', '<', $cutoffDate)
                ->pluck('id')
                ->toArray();

            // Delete UserNotifications first (foreign key constraint)
            // Use chunked deletion for large datasets
            $userNotificationsDeleted = 0;
            if (!empty($notificationIds)) {
                $chunks = array_chunk($notificationIds, 1000);
                foreach ($chunks as $chunk) {
                    $userNotificationsDeleted += UserNotification::whereIn('notification_id', $chunk)->delete();
                }
            }
            $this->info("Deleted {$userNotificationsDeleted} user notification record(s).");

            // Delete the notifications in chunks
            $notificationsDeleted = 0;
            if (!empty($notificationIds)) {
                $chunks = array_chunk($notificationIds, 1000);
                foreach ($chunks as $chunk) {
                    $notificationsDeleted += Notification::whereIn('id', $chunk)->delete();
                }
            }
            
            DB::commit();

            $this->info("Successfully cleaned up {$notificationsDeleted} notification(s) and {$userNotificationsDeleted} user notification record(s).");
            Log::info("Notification cleanup completed", [
                'notifications_deleted' => $notificationsDeleted,
                'user_notifications_deleted' => $userNotificationsDeleted,
                'cutoff_date' => $cutoffDate->toDateString(),
                'days' => $days,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error during cleanup: " . $e->getMessage());
            Log::error("Notification cleanup failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}
