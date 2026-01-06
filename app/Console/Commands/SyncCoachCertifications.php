<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CoachProfile;
use App\Models\UserDocument;
use App\Models\User;

class SyncCoachCertifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'coach:sync-certifications 
                            {--user-id= : Sync certifications for a specific user ID}
                            {--all : Sync certifications for all coaches}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync coach profile certifications from uploaded certification documents';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->option('user-id');
        $all = $this->option('all');

        if ($userId) {
            // Sync for specific user
            $this->syncUserCertifications($userId);
        } elseif ($all) {
            // Sync for all coaches
            $this->syncAllCoaches();
        } else {
            // Default: sync for all coaches
            $this->info('Syncing certifications for all coaches...');
            $this->syncAllCoaches();
        }

        return Command::SUCCESS;
    }

    /**
     * Sync certifications for all coaches
     */
    private function syncAllCoaches()
    {
        $coaches = CoachProfile::with('user')->get();
        $total = $coaches->count();
        $synced = 0;
        $updated = 0;

        $this->info("Found {$total} coach(es) to process...");
        $this->newLine();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($coaches as $coach) {
            $result = $this->syncUserCertifications($coach->user_id, false);
            if ($result['synced']) {
                $synced++;
            }
            if ($result['updated']) {
                $updated++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✓ Processed {$total} coach(es)");
        $this->info("✓ Synced {$synced} coach(es) with certifications");
        $this->info("✓ Updated {$updated} coach profile(s)");
    }

    /**
     * Sync certifications for a specific user
     */
    private function syncUserCertifications($userId, $verbose = true)
    {
        $user = User::find($userId);
        
        if (!$user) {
            if ($verbose) {
                $this->error("User with ID {$userId} not found");
            }
            return ['synced' => false, 'updated' => false];
        }

        $coachProfile = CoachProfile::where('user_id', $userId)->first();
        
        if (!$coachProfile) {
            if ($verbose) {
                $this->warn("User {$userId} ({$user->email}) is not a coach");
            }
            return ['synced' => false, 'updated' => false];
        }

        // Get all certification documents for this user
        $certificationDocuments = UserDocument::where('user_id', $userId)
            ->where(function($query) {
                $query->where(function($q) {
                    $q->where('document_type', 'other')
                      ->whereNotNull('custom_type');
                })->orWhere('document_type', 'medical_certificate');
            })
            ->get();

        if ($certificationDocuments->isEmpty()) {
            if ($verbose) {
                $this->info("No certification documents found for coach {$userId}");
            }
            return ['synced' => true, 'updated' => false];
        }

        // Build certifications array from documents
        $certifications = [];
        foreach ($certificationDocuments as $document) {
            $certificationName = null;

            if ($document->document_type === 'other' && $document->custom_type) {
                $certificationName = $document->custom_type;
            } elseif ($document->document_type === 'medical_certificate') {
                $certificationName = $document->document_name ?: 'Medical Certificate';
            }

            if ($certificationName && !in_array($certificationName, $certifications)) {
                $certifications[] = $certificationName;
            }
        }

        // Update coach profile
        $oldCertifications = $coachProfile->certifications ?? [];
        $coachProfile->certifications = $certifications;
        $coachProfile->save();

        $wasUpdated = $oldCertifications !== $certifications;

        if ($verbose) {
            $this->info("Coach {$userId} ({$user->email}):");
            $this->line("  Found {$certificationDocuments->count()} certification document(s)");
            $this->line("  Certifications: " . (count($certifications) > 0 ? implode(', ', $certifications) : 'None'));
            if ($wasUpdated) {
                $this->line("  ✓ Profile updated");
            } else {
                $this->line("  - No changes needed");
            }
        }

        return ['synced' => true, 'updated' => $wasUpdated];
    }
}















