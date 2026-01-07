<?php

namespace App\Services;

use App\Models\EntityDocument;
use App\Models\User;
use App\Models\Venue;
use App\Models\Team;
use App\Models\CoachProfile;
use Illuminate\Support\Facades\Log;

class EntityVerificationService
{
    /**
     * Check if entity has enough verified documents and auto-verify if so
     */
    public function checkAndVerifyEntity($entityType, $entityId)
    {
        $config = $this->getRequiredDocuments($entityType);
        
        if (!$config) {
            Log::warning("No verification config found for entity type: {$entityType}");
            return false;
        }

        if (!$this->hasEnoughDocuments($entityType, $entityId, $config)) {
            return false;
        }

        $entity = $this->getEntity($entityType, $entityId);
        if (!$entity) {
            Log::warning("Entity not found: {$entityType} #{$entityId}");
            return false;
        }

        // Get the document that triggered verification
        $document = EntityDocument::where('documentable_type', $this->getModelClass($entityType))
            ->where('documentable_id', $entityId)
            ->where('verification_status', 'verified')
            ->where('ai_auto_verified', true)
            ->latest('verified_at')
            ->first();

        return $this->autoVerifyEntity($entity, $document, $entityType);
    }

    /**
     * Get required documents configuration for entity type
     */
    public function getRequiredDocuments($entityType)
    {
        $config = config('ai-verification.entity_verification');
        
        $typeMap = [
            'User' => 'athlete',
            'user' => 'athlete',
            'Venue' => 'venue',
            'venue' => 'venue',
            'Team' => 'team',
            'team' => 'team',
            'CoachProfile' => 'coach',
            'coach_profile' => 'coach',
            'coach' => 'coach',
        ];

        $key = $typeMap[$entityType] ?? $entityType;
        return $config[$key] ?? null;
    }

    /**
     * Check if entity has enough verified documents
     */
    public function hasEnoughDocuments($entityType, $entityId, $config)
    {
        $modelClass = $this->getModelClass($entityType);
        
        $query = EntityDocument::where('documentable_type', $modelClass)
            ->where('documentable_id', $entityId)
            ->where('verification_status', 'verified')
            ->whereIn('document_category', $config['required_categories'] ?? []);

        $count = $query->count();
        $minDocuments = $config['min_documents'] ?? 1;

        return $count >= $minDocuments;
    }

    /**
     * Auto-verify entity based on document verification
     */
    public function autoVerifyEntity($entity, $document, $entityType)
    {
        try {
            $aiConfidence = $document ? $document->ai_confidence_score : null;
            $notes = "Auto-verified by AI based on verified documents. Confidence: " . ($aiConfidence ?? 'N/A') . "%";

            switch ($entityType) {
                case 'User':
                case 'user':
                    $entity->update([
                        'is_pro_athlete' => true,
                        'verified_at' => now(),
                        'verified_by' => 1, // System/AI user ID
                        'verification_notes' => $notes,
                        'verified_by_ai' => true,
                    ]);
                    break;

                case 'Venue':
                case 'venue':
                    $entity->update([
                        'verified_at' => now(),
                        'verification_expires_at' => now()->addYear(),
                        'verified_by' => 1, // System/AI user ID
                        'verified_by_ai' => true,
                    ]);
                    break;

                case 'Team':
                case 'team':
                    $entity->update([
                        'certification_status' => 'verified',
                        'certification_verified_at' => now(),
                        'certification_verified_by' => 1, // System/AI user ID
                        'certification_ai_notes' => $notes,
                        'verified_by_ai' => true,
                    ]);
                    break;

                case 'CoachProfile':
                case 'coach_profile':
                case 'coach':
                    $entity->update([
                        'is_verified' => true,
                        'verified_at' => now(),
                        'verified_by' => 1, // System/AI user ID
                        'verification_notes' => $notes,
                        'verified_by_ai' => true,
                    ]);
                    break;
            }

            Log::info("Entity auto-verified: {$entityType} #{$entity->id} by AI");
            
            // Send notification
            $this->sendVerificationNotification($entity, $entityType);

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to auto-verify entity {$entityType} #{$entity->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get entity model instance
     */
    protected function getEntity($entityType, $entityId)
    {
        $modelClass = $this->getModelClass($entityType);
        return $modelClass::find($entityId);
    }

    /**
     * Get model class name from entity type
     */
    protected function getModelClass($entityType)
    {
        $typeMap = [
            'User' => User::class,
            'user' => User::class,
            'Venue' => Venue::class,
            'venue' => Venue::class,
            'Team' => Team::class,
            'team' => Team::class,
            'CoachProfile' => CoachProfile::class,
            'coach_profile' => CoachProfile::class,
            'coach' => CoachProfile::class,
        ];

        return $typeMap[$entityType] ?? null;
    }

    /**
     * Send verification notification
     */
    protected function sendVerificationNotification($entity, $entityType)
    {
        try {
            $userId = null;
            $message = '';

            switch ($entityType) {
                case 'User':
                case 'user':
                    $userId = $entity->id;
                    $message = "Congratulations! You have been verified as a Pro Athlete.";
                    break;
                case 'Venue':
                case 'venue':
                    $userId = $entity->created_by;
                    $message = "Your venue '{$entity->name}' has been verified.";
                    break;
                case 'Team':
                case 'team':
                    $userId = $entity->created_by;
                    $message = "Your team '{$entity->name}' has been verified.";
                    break;
                case 'CoachProfile':
                case 'coach_profile':
                case 'coach':
                    $userId = $entity->user_id;
                    $message = "Your coach profile has been verified.";
                    break;
            }

            if ($userId) {
                $notification = \App\Models\Notification::create([
                    'type' => 'entity_verified',
                    'data' => [
                        'message' => $message,
                        'entity_type' => $entityType,
                        'entity_id' => $entity->id,
                        'verified_by_ai' => true,
                    ],
                    'created_by' => 1, // System
                ]);

                \App\Models\UserNotification::create([
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'pinned' => false,
                    'is_read' => false,
                    'action_state' => 'none',
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to send verification notification: " . $e->getMessage());
        }
    }
}

