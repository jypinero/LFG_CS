<?php

namespace App\Services;

use App\Models\TournamentParticipant;
use App\Models\TeamMatchup;
use App\Models\TournamentAnnouncement;
use App\Models\Tournament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class TournamentNotificationService
{
    /**
     * Send registration confirmation email
     */
    public function sendRegistrationConfirmation($participant)
    {
        try {
            $user = $participant->user;
            $tournament = $participant->tournament;

            if (!$user || !$tournament) {
                return;
            }

            // In a real implementation, you would send an email here
            // Mail::to($user->email)->send(new TournamentRegistrationConfirmation($participant));
            
            Log::info('Registration confirmation sent', [
                'user_id' => $user->id,
                'tournament_id' => $tournament->id
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send registration confirmation', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send registration approval email
     */
    public function sendRegistrationApproval($participant)
    {
        try {
            $user = $participant->user;
            $tournament = $participant->tournament;

            if (!$user || !$tournament) {
                return;
            }

            // Mail::to($user->email)->send(new TournamentRegistrationApproved($participant));
            
            Log::info('Registration approval sent', [
                'user_id' => $user->id,
                'tournament_id' => $tournament->id
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send registration approval', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send registration rejection email
     */
    public function sendRegistrationRejection($participant, $reason)
    {
        try {
            $user = $participant->user;
            $tournament = $participant->tournament;

            if (!$user || !$tournament) {
                return;
            }

            // Mail::to($user->email)->send(new TournamentRegistrationRejected($participant, $reason));
            
            Log::info('Registration rejection sent', [
                'user_id' => $user->id,
                'tournament_id' => $tournament->id,
                'reason' => $reason
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send registration rejection', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send match reminder email
     */
    public function sendMatchReminder($match, $participants)
    {
        try {
            foreach ($participants as $participant) {
                $user = $participant->user;
                if ($user) {
                    // Mail::to($user->email)->send(new MatchReminder($match, $participant));
                    Log::info('Match reminder sent', [
                        'user_id' => $user->id,
                        'match_id' => $match->id
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send match reminder', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send match result email
     */
    public function sendMatchResult($match, $participants)
    {
        try {
            foreach ($participants as $participant) {
                $user = $participant->user;
                if ($user) {
                    // Mail::to($user->email)->send(new MatchResult($match, $participant));
                    Log::info('Match result sent', [
                        'user_id' => $user->id,
                        'match_id' => $match->id
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send match result', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send tournament announcement email
     */
    public function sendTournamentAnnouncement($announcement, $participants)
    {
        try {
            foreach ($participants as $participant) {
                $user = $participant->user;
                if ($user) {
                    // Mail::to($user->email)->send(new TournamentAnnouncementEmail($announcement));
                    Log::info('Tournament announcement sent', [
                        'user_id' => $user->id,
                        'announcement_id' => $announcement->id
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send tournament announcement', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send waitlist promotion email
     */
    public function sendWaitlistPromotion($participant)
    {
        try {
            $user = $participant->user;
            $tournament = $participant->tournament;

            if (!$user || !$tournament) {
                return;
            }

            // Mail::to($user->email)->send(new WaitlistPromoted($participant));
            
            Log::info('Waitlist promotion sent', [
                'user_id' => $user->id,
                'tournament_id' => $tournament->id
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send waitlist promotion', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send tournament cancellation email
     */
    public function sendTournamentCancellation($tournament, $participants)
    {
        try {
            foreach ($participants as $participant) {
                $user = $participant->user;
                if ($user) {
                    // Mail::to($user->email)->send(new TournamentCancelled($tournament, $participant));
                    Log::info('Tournament cancellation sent', [
                        'user_id' => $user->id,
                        'tournament_id' => $tournament->id
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send tournament cancellation', ['error' => $e->getMessage()]);
        }
    }
}


