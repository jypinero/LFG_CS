<?php

namespace App\Http\Controllers;

use App\Models\TournamentAnnouncement;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TournamentAnnouncementController extends Controller
{
    /**
     * Create announcement for tournament
     * POST /api/tournaments/{tournamentId}/announcements
     * Body: { "title": "...", "content": "...", "priority": "high", "is_pinned": true }
     */
    public function createAnnouncement(Request $request, $tournamentId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Check organizer/creator permission
        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'priority' => 'sometimes|in:low,medium,high',
            'is_pinned' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();
        try {
            $announcement = TournamentAnnouncement::create([
                'tournament_id' => $tournament->id,
                'title' => $data['title'],
                'content' => $data['content'],
                'created_by' => $user->id,
                'priority' => $data['priority'] ?? 'medium',
                'is_pinned' => $data['is_pinned'] ?? false,
                'published_at' => now(),
            ]);

            // Get all tournament participants (teams + individuals)
            $participantUserIds = TournamentParticipant::where('tournament_id', $tournament->id)
                ->whereIn('status', ['approved', 'confirmed', 'pending'])
                ->get()
                ->map(function($p) {
                    if ($p->participant_type === 'individual') {
                        return $p->user_id;
                    } else {
                        // get team owner
                        return Team::find($p->team_id)?->owner_id;
                    }
                })
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            // Send notifications to all participants
            if (!empty($participantUserIds)) {
                $notification = Notification::create([
                    'type' => 'tournament_announcement',
                    'data' => [
                        'tournament_id' => $tournament->id,
                        'tournament_name' => $tournament->name,
                        'announcement_id' => $announcement->id,
                        'title' => $announcement->title,
                        'content' => $announcement->content,
                        'priority' => $announcement->priority,
                    ],
                    'created_by' => $user->id,
                ]);

                foreach ($participantUserIds as $userId) {
                    UserNotification::create([
                        'notification_id' => $notification->id,
                        'user_id' => $userId,
                        'is_read' => false,
                    ]);
                }
            }

            DB::commit();
            return response()->json([
                'status' => 'success',
                'announcement' => $announcement,
                'message' => 'Announcement created and notifications sent',
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create announcement',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all announcements for tournament
     * GET /api/tournaments/{tournamentId}/announcements
     */
    public function getAnnouncements($tournamentId)
    {
        $tournament = Tournament::find($tournamentId);
        
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        $announcements = TournamentAnnouncement::where('tournament_id', $tournament->id)
            ->with(['creator'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->get()
            ->map(function($a) {
                return [
                    'id' => $a->id,
                    'tournament_id' => $a->tournament_id,
                    'title' => $a->title,
                    'content' => $a->content,
                    'priority' => $a->priority,
                    'is_pinned' => $a->is_pinned,
                    'created_by' => $a->created_by,
                    'creator_name' => $a->creator ? $a->creator->first_name . ' ' . $a->creator->last_name : 'Unknown',
                    'published_at' => $a->published_at,
                    'created_at' => $a->created_at,
                    'updated_at' => $a->updated_at,
                ];
            });

        return response()->json([
            'status' => 'success',
            'announcements' => $announcements,
            'count' => $announcements->count(),
        ]);
    }

    /**
     * Update announcement
     * PUT /api/tournaments/{tournamentId}/announcements/{announcementId}
     * Body: { "title": "...", "content": "...", "priority": "...", "is_pinned": true }
     */
    public function updateAnnouncement(Request $request, $tournamentId, $announcementId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Check permission
        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $announcement = TournamentAnnouncement::where('id', $announcementId)
            ->where('tournament_id', $tournament->id)
            ->first();

        if (!$announcement) {
            return response()->json(['status' => 'error', 'message' => 'Announcement not found'], 404);
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'priority' => 'sometimes|in:low,medium,high',
            'is_pinned' => 'sometimes|boolean',
        ]);

        $announcement->update([
            'title' => $data['title'] ?? $announcement->title,
            'content' => $data['content'] ?? $announcement->content,
            'priority' => $data['priority'] ?? $announcement->priority,
            'is_pinned' => $data['is_pinned'] ?? $announcement->is_pinned,
        ]);

        return response()->json([
            'status' => 'success',
            'announcement' => $announcement,
            'message' => 'Announcement updated',
        ]);
    }

    /**
     * Delete announcement
     * DELETE /api/tournaments/{tournamentId}/announcements/{announcementId}
     */
    public function deleteAnnouncement($tournamentId, $announcementId)
    {
        $user = auth()->user();
        $tournament = Tournament::find($tournamentId);
        
        if (!$tournament) {
            return response()->json(['status' => 'error', 'message' => 'Tournament not found'], 404);
        }

        // Check permission
        $isCreator = $tournament->created_by === $user->id;
        $isOrganizer = TournamentOrganizer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'organizer'])
            ->exists();

        if (!$isCreator && !$isOrganizer) {
            return response()->json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }

        $announcement = TournamentAnnouncement::where('id', $announcementId)
            ->where('tournament_id', $tournament->id)
            ->first();

        if (!$announcement) {
            return response()->json(['status' => 'error', 'message' => 'Announcement not found'], 404);
        }

        $announcement->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Announcement deleted',
        ]);
    }
}
