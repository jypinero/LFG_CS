<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UserDocument;
use App\Jobs\ProcessDocumentWithFreeAI;

class RequeueDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:requeue 
                            {--status= : Filter by verification status (pending, verified, rejected)}
                            {--all : Requeue all processed documents}
                            {--limit= : Limit number of documents to requeue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Requeue processed documents for AI verification with enhanced name matching';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $status = $this->option('status');
        $all = $this->option('all');
        $limit = $this->option('limit');

        $this->info('🔄 Requeuing documents for AI verification with enhanced name matching...');
        $this->newLine();

        // Build query
        $query = UserDocument::where('ai_processed', true);

        // Filter by status if provided
        if ($status) {
            if (!in_array($status, ['pending', 'verified', 'rejected'])) {
                $this->error("Invalid status. Must be: pending, verified, or rejected");
                return Command::FAILURE;
            }
            $query->where('verification_status', $status);
            $this->info("Filtering by status: {$status}");
        }

        // Apply limit if provided
        if ($limit) {
            $query->limit((int)$limit);
            $this->info("Limiting to {$limit} document(s)");
        }

        $documents = $query->get();
        $total = $documents->count();

        if ($total === 0) {
            $this->warn('No documents found to requeue.');
            return Command::SUCCESS;
        }

        $this->info("Found {$total} document(s) to requeue");
        $this->newLine();

        // Confirm if requeuing many documents
        if ($total > 10 && !$all) {
            if (!$this->confirm("Are you sure you want to requeue {$total} document(s)?", true)) {
                $this->info('Cancelled.');
                return Command::SUCCESS;
            }
        }

        // Process documents
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $requeued = 0;
        foreach ($documents as $document) {
            // Reset AI fields
            $document->update([
                'ai_processed' => false,
                'ai_confidence_score' => null,
                'ai_extracted_data' => null,
                'ai_validation_notes' => null,
                'ai_flags' => null,
                'ai_quality_score' => null,
                'ai_name_matches' => null,
                'ai_auto_verified' => false,
                'ai_processed_at' => null,
                'ai_ocr_text' => null,
            ]);

            // Queue for reprocessing
            ProcessDocumentWithFreeAI::dispatch($document->id);
            $requeued++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Successfully requeued {$requeued} document(s)");
        $this->info("📋 Documents will be processed with enhanced name matching and document type validation");
        $this->newLine();

        return Command::SUCCESS;
    }
}
