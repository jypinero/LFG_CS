<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'push:generate-vapid-keys';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate VAPID keys for web push notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $this->info('Generating VAPID keys...');
            
            $keys = VAPID::createVapidKeys();
            
            $this->newLine();
            $this->info('VAPID keys generated successfully!');
            $this->newLine();
            $this->line('Add these to your .env file:');
            $this->newLine();
            $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
            $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
            $this->line('VAPID_EMAIL=mailto:your-email@example.com');
            $this->newLine();
            $this->warn('⚠️  Keep the private key secure and never commit it to version control!');
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to generate VAPID keys: ' . $e->getMessage());
            $this->newLine();
            $this->line('If you\'re on Windows and getting OpenSSL errors, try:');
            $this->line('1. Use the online generator: https://web-push-codelab.glitch.me/');
            $this->line('2. Use npx: npx web-push generate-vapid-keys');
            
            return Command::FAILURE;
        }
    }
}
