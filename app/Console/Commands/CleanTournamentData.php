<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanTournamentData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tournament:clean {--force : Force clean without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean all tournament-related data from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!$this->option('force') && !$this->confirm('Are you sure you want to delete ALL tournament data? This cannot be undone!')) {
            $this->info('Operation cancelled.');
            return;
        }

        try {
            $this->info('Starting tournament data cleanup...');
            
            // Disable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // Delete in order of dependencies (child tables first)
            $tables = [
                'match_notes',
                'tournament_documents',
                'tournament_announcements',
                'tournament_analytics',
                'tournament_waitlist',
                'tournament_participants',
                'tournament_organizers',
                'tournament_templates',
                'tournament_phases',
                'event_games',
                'event_results',
                'event_penalties',
                'team_matchups',
                'standings',
                'leaderboards',
                'tournaments',
            ];

            foreach ($tables as $table) {
                $count = DB::table($table)->count();
                if ($count > 0) {
                    DB::table($table)->truncate();
                    $this->info("✓ Cleaned {$table} ({$count} records deleted)");
                } else {
                    $this->line("  {$table} is already empty");
                }
            }

            // Clean tournament photos from storage
            $tournamentPhotosPath = 'tournaments';
            if (Storage::disk('public')->exists($tournamentPhotosPath)) {
                Storage::disk('public')->deleteDirectory($tournamentPhotosPath);
                $this->info("✓ Cleaned tournament photos from storage");
            }

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            // Reset auto-increment values
            foreach ($tables as $table) {
                DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = 1");
            }

            $this->info("\n✓ Tournament data cleanup completed successfully!");
            $this->line('All tournament-related data has been removed.');

        } catch (\Exception $e) {
            // Re-enable foreign key checks on error
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->error('Error during cleanup: ' . $e->getMessage());
            return;
        }
    }
}
