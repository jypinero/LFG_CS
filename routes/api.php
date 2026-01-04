<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\VenueController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\NotifController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\MessagingController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\Auth\OtpAuthController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\TrainingSessionController;
use App\Http\Controllers\TrainingAnalyticsController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\LogAdminAction;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\MarketingController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Support contact form route
Route::post('/support/contact', [SupportController::class, 'submitContact']);

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::get('/register/check-availability', [AuthController::class, 'checkAvailability']);
Route::post('/login', [AuthController::class, 'login']);
// Password reset routes
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
// OTP login flow
Route::post('/auth/login', [OtpAuthController::class, 'login'])->middleware('throttle:otp-send');
Route::post('/auth/verify-otp', [OtpAuthController::class, 'verify'])->middleware('throttle:otp-verify');
Route::post('/auth/resend-otp', [OtpAuthController::class, 'resend'])->middleware('throttle:otp-send');
// Google OAuth routes
Route::get('/auth/google', [SocialiteController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [SocialiteController::class, 'handleGoogleCallback']);
Route::post('/auth/google/complete', [SocialiteController::class, 'completeSocialRegistration'])->middleware('auth:api');
Route::get('/roles', [AuthController::class, 'getRoles']);
Route::get('/sports', [AuthController::class, 'getSports']);

// Public tournament route (no authentication required)
Route::get('/tournaments/public/{id}', [TournamentController::class, 'getPublicTournament']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});



// Session validation route (before auth middleware to avoid loop)
Route::get('/auth/validate-session', [AuthController::class, 'validateSession'])->middleware('auth:api');

// Protected routes
Route::middleware('auth:api')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    
    // Notifications routes - MUST come before /users/{id} to avoid route conflict
    Route::get('/users/notifications', [NotifController::class, 'userNotifications']);
    Route::post('/users/notifications/{id}/read', [NotifController::class, 'markAsRead']);
    Route::post('/users/notifications/{id}/unread', [NotifController::class, 'markAsUnread']);
    Route::post('/users/notifications/readall', [NotifController::class, 'markAllRead']);

    // ✅ Add route for getting user by ID (for venue owners, etc.)
    // This must come AFTER /users/notifications to avoid wildcard matching
    Route::get('/users/{id}', [AuthController::class, 'showprofile']);
    
    // ❌ Remove or fix this line - showprofile requires an ID parameter
    // Route::get('/users', [AuthController::class, 'showprofile']);
    
    Route::get('/profile/me', [AuthController::class, 'myprofile']);
    Route::post('/profile/update', [AuthController::class, 'updateProfile']);
    // NOTE: /profile/{username} moved below /profile/documents routes to prevent wildcard matching "documents" as a username

    // Messaging
    Route::prefix('messaging')->group(function () {
        Route::get('/threads', [MessagingController::class, 'threads']);
        Route::get('/threads/{threadId}', [MessagingController::class, 'show']);
        Route::get('/threads/{threadId}/messages', [MessagingController::class, 'threadMessages']);
        Route::post('/threads/create-one', [MessagingController::class, 'createOneToOneByUsername']);
        Route::post('/threads/create-group', [MessagingController::class, 'createGroup']);
        Route::post('/threads/{threadId}/messages', [MessagingController::class, 'sendMessage']);
        Route::put('/threads/{threadId}/messages/{messageId}', [MessagingController::class, 'editMessage']);
        Route::delete('/threads/{threadId}/messages/{messageId}', [MessagingController::class, 'deleteMessage']);
        Route::post('/threads/{threadId}/archive', [MessagingController::class, 'archive']);
        Route::post('/threads/{threadId}/unarchive', [MessagingController::class, 'unarchive']);
        Route::post('/threads/{threadId}/leave', [MessagingController::class, 'leave']);
        Route::post('/threads/{threadId}/read', [MessagingController::class, 'markRead']);
        Route::put('/threads/{threadId}/title', [MessagingController::class, 'updateTitle']);
        Route::post('/threads/{threadId}/participants', [MessagingController::class, 'addParticipant']);
        Route::delete('/threads/{threadId}/participants/{participantUserId}', [MessagingController::class, 'removeParticipant']);
        // Auto create helpers
        Route::post('/auto/team/{teamId}', [MessagingController::class, 'createTeamThread']);
        Route::post('/auto/venue/{venueId}', [MessagingController::class, 'createVenueThread']);
        Route::post('/auto/game/{eventId}', [MessagingController::class, 'createGameThread']);
    });

     // User profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [AuthController::class, 'me']);
        Route::post('/photo', [AuthController::class, 'updateProfilePhoto']);
    });

    // User documents management
    Route::prefix('profile/documents')->group(function () {
        Route::get('/', [\App\Http\Controllers\UserDocumentController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\UserDocumentController::class, 'store']);
        Route::get('/statistics', [\App\Http\Controllers\UserDocumentController::class, 'statistics']);
        Route::post('/sync-certifications', [\App\Http\Controllers\UserDocumentController::class, 'syncCertifications']);
        Route::get('/{id}', [\App\Http\Controllers\UserDocumentController::class, 'show']);
        Route::post('/{id}', [\App\Http\Controllers\UserDocumentController::class, 'update']); // POST for file upload support
        Route::delete('/{id}', [\App\Http\Controllers\UserDocumentController::class, 'destroy']);
        Route::get('/{id}/download', [\App\Http\Controllers\UserDocumentController::class, 'download']);
    });

    // Wildcard username route - MUST be after all specific /profile/* routes
    Route::get('/profile/{username}', [AuthController::class, 'showprofileByUsername']);

    Route::get('/schedules', [EventController::class, 'allschedule']);
    Route::get('/schedules/user-created', [EventController::class, 'allusercreated']);
    Route::get('/schedules/{date}', [EventController::class, 'userschedule']);


    Route::post('/venues/games-played', [EventController::class, 'eventsByVenue']);


    // Event routes
    Route::get('/events',[EventController::class, 'index']);
    Route::get('/events/suggested-tournaments', [EventController::class, 'getSuggestedTournamentGames']);
    Route::post('/events/create', [EventController::class, 'store']);
    Route::get('/events/{id}', [EventController::class, 'show']);
    Route::put('/events/{id}', [EventController::class, 'update']);
    Route::delete('/events/{id}', [EventController::class, 'destroy']);
    
    // Event participation
    Route::post('/events/join', [EventController::class, 'joinEvent']);
    Route::post('/events/leave', [EventController::class, 'leaveEvent']);
    Route::post('/events/join-team', [EventController::class, 'joinEventAsTeam']);
    Route::post('/events/invite-team', [EventController::class, 'inviteTeamToEvent']);
    Route::post('/events/respond-invitation', [EventController::class, 'respondTeamInvitation']);
    Route::post('/events/remove-participant', [EventController::class, 'removeParticipant']);
    Route::get('/events/{id}/participants', [EventController::class , 'eventlist']);
    
    // Event check-in
    Route::post('/events/checkin/qr', [EventController::class, 'checkinQR']);
    Route::post('/events/checkin/code', [EventController::class, 'checkinCode']);
    Route::post('/events/checkin/manual', [EventController::class, 'checkinManual']);
    Route::get('/events/{id}/checkins', [EventController::class, 'viewCheckins']);
    
    // QR Code generation
    Route::get('/events/{id}/qr-code', [EventController::class, 'generateQRCode']);
    Route::get('/events/{id}/qr-code-png', [EventController::class, 'generateQRCodePNG']);
    
    // Event scoring (for tournaments & team vs team only)
    Route::post('/events/scores/record', [EventController::class, 'recordScore']);
    Route::put('/events/scores/update', [EventController::class, 'updateScore']);
    Route::get('/events/{id}/scores', [EventController::class, 'viewScores']);

    Route::get('/posts', [PostController::class, 'index']);
    Route::get('/posts/see/{id}', [PostController::class, 'seepost']);
    Route::delete('/posts/archived/{postId}', [PostController::class, 'deletepost']);
    Route::get('/posts/archived', [PostController::class, 'archivedPosts']);
    Route::put('/posts/restore/{postId}', [PostController::class, 'restorepost']);

    Route::post('/posts/create', [PostController::class, 'createpost']);
    Route::post('/posts/{postId}/like', [PostController::class, 'likepost']);
    Route::post('/posts/{postId}/unlike', [PostController::class, 'unlikepost']);
    Route::get('/posts/{postId}/view_likes', [PostController::class, 'seelike']);
    Route::post('/posts/{postId}/comment', [PostController::class, 'commentpost']);
    Route::get('/posts/{postId}/view_comments', [PostController::class, 'seecomments']);


    Route::get('/venues', [VenueController::class, 'index']);
    Route::post('/venues/create', [VenueController::class, 'store']);
    Route::post('/venues/{venueId}/facilities', [VenueController::class, 'storeFacility']);
    Route::get('/venues/show/{venueId}', [VenueController::class, 'show']);
    Route::post('/venues/edit/{venueId}', [VenueController::class, 'update']);
    Route::delete('/venues/delete/{venueId}', [VenueController::class, 'destroy']);
    Route::get('/venues/owner', [VenueController::class, 'OwnerVenues']);
    Route::get('/venues/owner/archived', [VenueController::class, 'OwnerArchivedVenues']);
    Route::post('/venues/{venueId}/close', [VenueController::class, 'closeVenue']);
    Route::post('/venues/{venueId}/reopen', [VenueController::class, 'reopenVenue']);
    Route::post('/venues/{venueId}/transfer-ownership', [VenueController::class, 'transferOwnership']);

    Route::get('/venues/member', [VenueController::class, 'memberVenues']);
    
    // Facilities list route must come before the {facilityId} route to avoid route conflict
    Route::get('/venues/{venueId}/facilities/list', [VenueController::class, 'getFacilitiesList']);
    
    Route::get('/venues/{venueId}/facilities/{facilityId}', [\App\Http\Controllers\VenueController::class, 'showFacilityByVenue']);
    Route::get('/venues/{venueId}/facilities/{facilityId}/booked-slots', [VenueController::class, 'getBookedSlots']);
    Route::post('/venues/{venueId}/facilities/edit/{facilityId}', [\App\Http\Controllers\VenueController::class, 'updateFacilityByVenue']);
    Route::delete('/venues/{venueId}/facilities/delete/{facilityId}', [\App\Http\Controllers\VenueController::class, 'destroyFacilityByVenue']);

    Route::post('/venues/{venueId}/facilities/{facilityId}/photos', [\App\Http\Controllers\VenueController::class, 'addFacilityPhoto'])->name('venues.facilities.photos.store');
    Route::delete('/venues/{venueId}/facilities/{facilityId}/photos/{photoId}', [\App\Http\Controllers\VenueController::class, 'destroyFacilityPhoto'])->name('venues.facilities.photos.destroy');
    Route::post('/venues/{venueId}/addmembers', [\App\Http\Controllers\VenueController::class, 'addMember']);
    Route::get('venues/{venueId}/members', [VenueController::class, 'staff']);

    // Booking management routes
    Route::get('/venues/bookings', [VenueController::class, 'getBookings']);
    Route::put('/venues/bookings/{id}/status', [VenueController::class, 'updateBookingStatus']);
    Route::post('/venues/bookings/{id}/cancel', [VenueController::class, 'cancelEventBooking']);
    Route::patch('/venues/bookings/{id}/reschedule', [VenueController::class, 'rescheduleEventBooking']);

    Route::post('/venues/{venueId}/post-reviews', [\App\Http\Controllers\VenueController::class, 'PostReview']);
    Route::get('/venues/{venueId}/reviews', [\App\Http\Controllers\VenueController::class, 'venueReviews']);

    Route::get('/venues/search', [\App\Http\Controllers\VenueController::class, 'search']);

    Route::get('/venues/analytics/{venueId?}', [VenueController::class, 'getAnalytics']);

    // Operating Hours Management
    Route::get('/venues/{venueId}/operating-hours', [VenueController::class, 'getOperatingHours']);
    Route::post('/venues/{venueId}/operating-hours', [VenueController::class, 'addOperatingHours']);
    Route::put('/venues/{venueId}/operating-hours/{hoursId}', [VenueController::class, 'updateOperatingHours']);
    Route::delete('/venues/{venueId}/operating-hours/{hoursId}', [VenueController::class, 'deleteOperatingHours']);

    // Amenities Management
    Route::get('/venues/{venueId}/amenities', [VenueController::class, 'getAmenities']);
    Route::post('/venues/{venueId}/amenities', [VenueController::class, 'addAmenity']);
    Route::put('/venues/{venueId}/amenities/{amenityId}', [VenueController::class, 'updateAmenity']);
    Route::delete('/venues/{venueId}/amenities/{amenityId}', [VenueController::class, 'deleteAmenity']);

    // Closure Dates Management
    Route::get('/venues/{venueId}/closure-dates', [VenueController::class, 'getClosureDates']);
    Route::post('/venues/{venueId}/closure-dates', [VenueController::class, 'addClosureDate']);
    Route::put('/venues/{venueId}/closure-dates/{closureId}', [VenueController::class, 'updateClosureDate']);
    Route::delete('/venues/{venueId}/closure-dates/{closureId}', [VenueController::class, 'deleteClosureDate']);

    // Marketing Controller Routes
    Route::post('/marketing/posts/create', [MarketingController::class, 'createpost']);
    Route::get('/marketing/posts', [MarketingController::class, 'index']);


    // Team Management Routes
    Route::get('/teams', [TeamController::class, 'index']);
    // Discover teams open to new members
    Route::get('/lookingfor/teams', [TeamController::class, 'discoverLookingForTeams']);
    // My outgoing (pending) join requests
    Route::get('/me/team-join-requests', [TeamController::class, 'myJoinRequests']);
    Route::post('/teams/create', [TeamController::class, 'store']);
    Route::patch('/teams/{teamId}', [TeamController::class, 'update']);
    Route::post('/teams/{teamId}/photo', [TeamController::class, 'updatePhoto']);
    Route::post('/teams/{teamId}/addmembers', [TeamController::class, 'addMember']);
    Route::post('/teams/{teamId}/transfer-ownership', [TeamController::class, 'transferOwnership']);
    Route::patch('/teams/{teamId}/members/{memberId}/role', [TeamController::class, 'editMemberRole']);
    Route::get('teams/{teamId}/members', [TeamController::class, 'members']);
    Route::delete('teams/{teamId}/members/{memberId}', [TeamController::class, 'removeMember']);
    Route::post('teams/{teamId}/request-join', [TeamController::class, 'requestJoinTeam']);
    Route::post('teams/{teamId}/requests/{memberId}/handle', [TeamController::class, 'handleJoinRequest']);
    Route::get('teams/{teamId}/requests/pending', [TeamController::class, 'getPendingRequests']);
    Route::get('teams/{teamId}/requests/history', [TeamController::class, 'getRequestHistory']);
    Route::post('teams/{teamId}/requests/handle-bulk', [TeamController::class, 'handleBulkRequests']);
    Route::post('teams/{teamId}/request-cancel', [TeamController::class, 'cancelJoinRequest']);
    
    // Roster management
    Route::patch('/teams/{teamId}/members/{memberId}/roster', [TeamController::class, 'updateRoster']);
    Route::get('/teams/{teamId}/roster', [TeamController::class, 'getRoster']);
    Route::patch('/teams/{teamId}/roster-limit', [TeamController::class, 'setRosterLimit']);
    Route::get('/teams/my', [TeamController::class, 'myTeams']);

    // Invite links
    Route::post('/teams/{teamId}/invites/generate', [TeamController::class, 'generateInvite']);
    Route::post('/teams/invites/{token}/accept', [TeamController::class, 'acceptInvite']);
    Route::get('/teams/{teamId}/invites', [TeamController::class, 'listInvites']);
    Route::delete('/teams/{teamId}/invites/{inviteId}', [TeamController::class, 'revokeInvite']);
    
    // Certification verification
    Route::post('/teams/{teamId}/certification/upload', [TeamController::class, 'uploadCertification']);
    Route::post('/teams/{teamId}/certification/verify-ai', [TeamController::class, 'verifyCertificationAI']);
    Route::get('/teams/{teamId}/certification/status', [TeamController::class, 'getCertificationStatus']);
    
    // Team events and leave
    Route::get('/teams/{teamId}/events', [TeamController::class, 'getTeamEvents']);
    Route::post('/teams/{teamId}/leave', [TeamController::class, 'leaveTeam']);
    
    // Delete team
    Route::delete('/teams/{teamId}', [TeamController::class, 'destroy']);


    // Tournament Management Routes
    // Tournament CRUD
    Route::get('/tournaments', [TournamentController::class, 'index']);
    Route::post('tournaments/create', [TournamentController::class, 'create']);
    Route::get('tournaments/show/{id}', [TournamentController::class, 'show']);
    Route::put('tournaments/update/{id}', [TournamentController::class, 'update']);
    Route::delete('tournaments/delete/{id}', [TournamentController::class, 'destroy']);
    Route::get('/tournaments/my', [TournamentController::class, 'myTournaments']);
    Route::get('tournaments/{tournamentId}/schedule', [TournamentController::class, 'getSchedule']);
    
    // Games (existing)
    Route::post('tournaments/{tournamentid}/creategames', [TournamentController::class, 'createGame']);
    Route::get('tournaments/{tournamentid}/getgames', [TournamentController::class, 'getGames']);
    Route::put('tournaments/{tournamentid}/updategames/{gameid}', [TournamentController::class, 'updateGame']);
    Route::patch('tournaments/{tournamentid}/updategames/{gameid}', [TournamentController::class, 'updateGame']);
    Route::delete('tournaments/{tournamentid}/deletegames/{gameid}', [TournamentController::class, 'deleteGame']);

    // Registration & participant management routes (added)
    // Single registration endpoint (handles individual OR team registration)
    Route::post('tournaments/{tournamentid}/register/{eventid}', [TournamentController::class, 'register']);

    // Participants list and admin actions
    Route::get('tournaments/{tournamentid}/participants', [TournamentController::class, 'getParticipants']);
    Route::post('tournaments/{tournamentid}/participants/{participantid}/approve', [TournamentController::class, 'approveParticipant']);
    Route::post('tournaments/{tournamentid}/participants/bulk-approve', [TournamentController::class, 'bulkApproveParticipants']);
    Route::post('tournaments/{tournamentid}/participants/{participantid}/reject', [TournamentController::class, 'rejectParticipant']);
    Route::post('tournaments/{tournamentid}/participants/{participantid}/ban', [TournamentController::class, 'banParticipant']);

     // Document Management
    Route::post('tournaments/{tournamentid}/documents/upload', [TournamentController::class, 'uploadDocument']);
    Route::get('tournaments/{tournamentid}/documents', [TournamentController::class, 'getDocuments']);
    Route::get('tournaments/{tournamentid}/participants/{participantId}/documents', [TournamentController::class, 'getParticipantDocuments']);
    Route::post('tournaments/{tournamentid}/documents/{documentId}/verify', [TournamentController::class, 'verifyDocument']);
    Route::delete('tournaments/{tournamentid}/documents/{documentId}/delete', [TournamentController::class, 'deleteDocument']);

    // Team Management within Tournaments (organizers)
    Route::post('tournaments/{tournamentid}/events/{eventid}/assign-teams', [TournamentController::class, 'assignTeams']);
    Route::post('tournaments/{tournamentid}/events/{eventid}/auto-balance', [TournamentController::class, 'autoBalanceTeams']);
    Route::post('tournaments/{tournamentid}/teams/{teamid}/replace-player', [TournamentController::class, 'replacePlayer']);
    Route::post('tournaments/{tournamentid}/participants/{participantid}/no-show', [TournamentController::class, 'markNoShow']);

    // Match Management
    Route::get('tournaments/{tournamentid}/matches', [\App\Http\Controllers\TournamentController::class, 'getMatches']);
    Route::get('tournaments/{tournamentid}/matches/live', [\App\Http\Controllers\TournamentController::class, 'getLiveMatches']);
    Route::get('tournaments/{tournamentid}/matches/{match}', [\App\Http\Controllers\TournamentController::class, 'getMatchDetails']);
    Route::post('tournaments/{tournamentid}/matches/{match}/start', [\App\Http\Controllers\TournamentController::class, 'startMatch']);
    Route::post('tournaments/{tournamentid}/matches/{match}/end', [\App\Http\Controllers\TournamentController::class, 'endMatch']);
    Route::post('tournaments/{tournamentid}/matches/{match}/score', [\App\Http\Controllers\TournamentController::class, 'updateScore']);
    Route::post('tournaments/{tournamentid}/matches/{match}/penalty', [\App\Http\Controllers\TournamentController::class, 'issuePenalty']);
    Route::post('tournaments/{tournamentid}/matches/{match}/forfeit', [\App\Http\Controllers\TournamentController::class, 'markForfeit']);
    Route::post('tournaments/{tournamentid}/matches/{match}/results', [\App\Http\Controllers\TournamentController::class, 'uploadResult']);
    Route::post('tournaments/{tournamentId}/matches/{match}/dispute', [\App\Http\Controllers\TournamentController::class, 'disputeResult']);
    Route::post('tournaments/{tournamentId}/matches/{match}/resolve-dispute', [\App\Http\Controllers\TournamentController::class, 'resolveDispute']);

    // Bracket Generation
    Route::post('tournaments/{tournament}/events/{event}/generate-brackets', [TournamentController::class, 'generateBrackets']);
    Route::post('tournaments/{tournamentId}/matches/{matchId}/advance', [TournamentController::class, 'advanceBracket']);

    // Tournament Announcements
    Route::post('/tournaments/{tournamentId}/announcements/create', [\App\Http\Controllers\TournamentAnnouncementController::class, 'createAnnouncement']);
    Route::get('/tournaments/{tournamentId}/announcements/get', [\App\Http\Controllers\TournamentAnnouncementController::class, 'getAnnouncements']);
    Route::put('/tournaments/{tournamentId}/announcements/{announcementId}/put', [\App\Http\Controllers\TournamentAnnouncementController::class, 'updateAnnouncement']);
    Route::delete('/tournaments/{tournamentId}/announcements/{announcementId}/delete', [\App\Http\Controllers\TournamentAnnouncementController::class, 'deleteAnnouncement']);

    // Analytics routes
    Route::get('/tournaments/{tournamentId}/analytics', [\App\Http\Controllers\AnalyticsController::class, 'getAnalytics']);
    Route::get('/tournaments/{tournamentId}/standings', [\App\Http\Controllers\AnalyticsController::class, 'getStandings']);
    Route::get('/tournaments/{tournamentId}/leaderboard', [\App\Http\Controllers\AnalyticsController::class, 'getLeaderboard']);
    
    // Activity log
    Route::get('/tournaments/{tournamentId}/activity-log', [TournamentController::class, 'getActivityLog']);
    
    // Spectator count
    Route::get('/tournaments/{tournamentId}/spectator-count', [TournamentController::class, 'getSpectatorCount']);
    
    // Tournament settings
    Route::patch('/tournaments/{tournamentId}/settings', [TournamentController::class, 'updateTournamentSettings']);

    // Organizer Management
    Route::post('/tournaments/{tournamentId}/organizers', [TournamentController::class, 'addOrganizer']);
    Route::delete('/tournaments/{tournamentId}/organizers/{userId}', [TournamentController::class, 'removeOrganizer']);
    Route::get('/tournaments/{tournamentId}/organizers', [TournamentController::class, 'listOrganizers']);
    Route::put('/tournaments/{tournamentId}/organizers/{userId}/role', [TournamentController::class, 'updateOrganizerRole']);

    // Registration Withdrawal
    Route::delete('/tournaments/{tournamentId}/withdraw', [TournamentController::class, 'withdraw']);

    // Waitlist Management
    Route::post('/tournaments/{tournamentId}/waitlist', [TournamentController::class, 'joinWaitlist']);
    Route::delete('/tournaments/{tournamentId}/waitlist', [TournamentController::class, 'removeFromWaitlist']);
    Route::get('/tournaments/{tournamentId}/waitlist', [TournamentController::class, 'getWaitlist']);
    Route::post('/tournaments/{tournamentId}/waitlist/promote', [TournamentController::class, 'promoteFromWaitlist']);

    // Tournament Phases Management
    Route::post('/tournaments/{tournamentId}/phases', [TournamentController::class, 'createPhase']);
    Route::get('/tournaments/{tournamentId}/phases', [TournamentController::class, 'listPhases']);
    Route::put('/tournaments/{tournamentId}/phases/{phaseId}', [TournamentController::class, 'updatePhase']);
    Route::delete('/tournaments/{tournamentId}/phases/{phaseId}', [TournamentController::class, 'deletePhase']);
    Route::post('/tournaments/{tournamentId}/phases/reorder', [TournamentController::class, 'reorderPhases']);

    // Tournament Status Management
    Route::post('/tournaments/{tournamentId}/open-registration', [TournamentController::class, 'openRegistration']);
    Route::post('/tournaments/{tournamentId}/close-registration', [TournamentController::class, 'closeRegistration']);
    Route::post('/tournaments/{tournamentId}/start', [TournamentController::class, 'startTournament']);
    Route::post('/tournaments/{tournamentId}/complete', [TournamentController::class, 'completeTournament']);
    
    // Tournament Cancellation
    Route::post('/tournaments/{tournamentId}/cancel', [TournamentController::class, 'cancelTournament']);

    // Tournament Templates
    Route::post('/tournaments/templates', [TournamentController::class, 'createTemplate']);
    Route::get('/tournaments/templates', [TournamentController::class, 'listTemplates']);
    Route::post('/tournaments/create-from-template/{templateId}', [TournamentController::class, 'createFromTemplate']);
    Route::put('/tournaments/templates/{templateId}', [TournamentController::class, 'updateTemplate']);
    Route::delete('/tournaments/templates/{templateId}', [TournamentController::class, 'deleteTemplate']);

    // Additional utility routes
    Route::post('tournaments/{tournamentId}/participants/bulk', [TournamentController::class,'bulkImportParticipants']);
    Route::post('tournaments/{tournamentId}/invite-link', [TournamentController::class,'createInviteLink']);
    Route::patch('tournaments/{tournamentId}/lock-participants', [TournamentController::class,'setParticipantLock']);
    Route::get('tournaments/{tournamentId}/bracket-preview/{eventId}', [TournamentController::class,'generateBracketsPreview']);
    Route::post('tournaments/{tournamentId}/participants/export', [TournamentController::class,'exportParticipants']);
    Route::post('tournaments/{tournamentId}/results/export', [TournamentController::class,'exportResults']);

    Route::post('events/{event}/checkin', [TournamentController::class, 'checkinEvent']); // code-based checkin (if present)
    Route::post('events/checkin/qr', [TournamentController::class, 'checkinQR']);
    Route::post('events/checkin/code', [TournamentController::class, 'checkinCode']);
    Route::post('events/checkin/manual', [TournamentController::class, 'checkinManual']);
    Route::get('events/{event}/checkins', [TournamentController::class, 'viewCheckins']);


    Route::post('tournaments/{tournamentId}/matches/{matchId}/reset', [TournamentController::class,'resetMatch']);
    Route::post('tournaments/{tournamentId}/bracket/reset/{eventId}', [TournamentController::class,'resetBracket']);


    // Coach Application Routes
    Route::post('/coach/createprofile', [CoachController::class, 'createProfile']);
    Route::get('/coach/getmyprofile', [CoachController::class, 'getMyProfile']);
    Route::put('/coach/updateprofile', [CoachController::class, 'updateProfile']);

    Route::get('/coach/swipe/card', [CoachController::class, 'getSwipeCard']);
    Route::get('/coach/matches/pending', [CoachController::class, 'getPendingMatches']);
    Route::post('/coach/matches/{matchId}/respond', [CoachController::class, 'respondToMatch']);
    Route::get('/coach/students', [CoachController::class, 'getStudents']);
    Route::get('/coach/sessions', [CoachController::class, 'getDashboardSessions']);
    Route::get('/coach/analytics', [CoachController::class, 'getAnalytics']);
    
    // Public/consumer coach endpoints (students)
    Route::get('/coaches/discover', [CoachController::class, 'discover']);
    Route::get('/coaches/{coachId}', [CoachController::class, 'show']);
    Route::post('/coaches/{coachId}/swipe', [CoachController::class, 'swipe']);
    Route::get('/student/matches', [CoachController::class, 'getMatches']);

     // Training session routes
    Route::post('/sessions/request/{coachId}', [\App\Http\Controllers\TrainingSessionController::class, 'requestSession']);
    Route::get('/sessions', [\App\Http\Controllers\TrainingSessionController::class, 'index']);
    Route::get('/sessions/upcoming', [\App\Http\Controllers\TrainingSessionController::class, 'getUpcoming']);
    Route::get('/sessions/pending', [\App\Http\Controllers\TrainingSessionController::class, 'getPending']);
    Route::post('/sessions/{sessionId}/accept', [\App\Http\Controllers\TrainingSessionController::class, 'accept']);
    Route::post('/sessions/{sessionId}/reject', [\App\Http\Controllers\TrainingSessionController::class, 'reject']);
    Route::post('/sessions/{sessionId}/cancel', [\App\Http\Controllers\TrainingSessionController::class, 'cancel']);
    Route::post('/sessions/{sessionId}/complete', [\App\Http\Controllers\TrainingSessionController::class, 'complete']);
    Route::post('/sessions/{sessionId}/reschedule', [\App\Http\Controllers\TrainingSessionController::class, 'reschedule']);

    //create review for coach
    Route::post('/sessions/{sessionId}/createreviews', [\App\Http\Controllers\CoachReviewController::class, 'create']);
    Route::get('/coach/{coachId}/reviews', [\App\Http\Controllers\CoachReviewController::class, 'getCoachReviews']);

    // Training analytics routes
    Route::get('students/{studentId}/analytics', [TrainingAnalyticsController::class, 'getStudentAnalytics']);
    Route::get('coaches/{coachId}/analytics',  [TrainingAnalyticsController::class, 'getCoachAnalytics']);
    // Use POST for force-calc (or GET if you prefer)
    Route::post('analytics/calculate/{userId}/{userType}', [TrainingAnalyticsController::class, 'calculateAnalytics']);

}); 

// Admin routes (JWT protected + admin-only)
Route::middleware(['auth:api', EnsureAdmin::class, LogAdminAction::class, 'throttle:admin-writes'])->prefix('admin')->group(function () {
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    // Users admin
    Route::get('/users', [\App\Http\Controllers\Admin\UserAdminController::class, 'index']);
    Route::get('/users/{id}', [\App\Http\Controllers\Admin\UserAdminController::class, 'show']);
    Route::post('/users', [\App\Http\Controllers\Admin\UserAdminController::class, 'store']);
    Route::patch('/users/{id}', [\App\Http\Controllers\Admin\UserAdminController::class, 'update']);
    Route::delete('/users/{id}', [\App\Http\Controllers\Admin\UserAdminController::class, 'destroy']);
    Route::post('/users/{id}/ban', [\App\Http\Controllers\Admin\UserAdminController::class, 'ban']);
    Route::post('/users/{id}/unban', [\App\Http\Controllers\Admin\UserAdminController::class, 'unban']);
    Route::get('/users/{id}/activity', [\App\Http\Controllers\Admin\UserAdminController::class, 'activity']);
    // User documents admin (verification)
    Route::get('/documents', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'index']);
    Route::get('/documents/statistics', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'statistics']);
    Route::get('/documents/{id}', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'show']);
    Route::get('/users/{userId}/documents', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'userDocuments']);
    Route::post('/documents/{id}/verify', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'verify']);
    Route::post('/documents/{id}/reject', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'reject']);
    Route::post('/documents/{id}/reset', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'resetVerification']);
    Route::post('/documents/bulk-verify', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'bulkVerify']);
    Route::post('/documents/bulk-reject', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'bulkReject']);
    Route::get('/documents/{id}/download', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'download']);
    Route::delete('/documents/{id}', [\App\Http\Controllers\Admin\UserDocumentAdminController::class, 'destroy']);
    // AI Document Verification (admin)
    Route::get('/documents/ai/smart-queue', [\App\Http\Controllers\AIDocumentController::class, 'smartQueue']);
    Route::get('/documents/ai/statistics', [\App\Http\Controllers\AIDocumentController::class, 'statistics']);
    Route::get('/documents/{id}/ai-analysis', [\App\Http\Controllers\AIDocumentController::class, 'getAnalysis']);
    Route::post('/documents/{id}/ai-reprocess', [\App\Http\Controllers\AIDocumentController::class, 'reprocess']);
    Route::post('/documents/ai/bulk-requeue', [\App\Http\Controllers\AIDocumentController::class, 'bulkRequeue']);
    Route::get('/ai/check-service', [\App\Http\Controllers\AIDocumentController::class, 'checkService']);
    // Tickets admin
    Route::get('/tickets', [\App\Http\Controllers\Admin\TicketAdminController::class, 'index']);
    Route::get('/tickets/{id}', [\App\Http\Controllers\Admin\TicketAdminController::class, 'show']);
    Route::post('/tickets', [\App\Http\Controllers\Admin\TicketAdminController::class, 'store']);
    Route::patch('/tickets/{id}', [\App\Http\Controllers\Admin\TicketAdminController::class, 'update']);
    Route::post('/tickets/{id}/close', [\App\Http\Controllers\Admin\TicketAdminController::class, 'close']);
    // Venues admin
    Route::get('/venues', [\App\Http\Controllers\Admin\VenueAdminController::class, 'index']);
    Route::get('/venues/{id}', [\App\Http\Controllers\Admin\VenueAdminController::class, 'show']);
    Route::patch('/venues/{id}', [\App\Http\Controllers\Admin\VenueAdminController::class, 'update']);
    Route::post('/venues/{id}/approve', [\App\Http\Controllers\Admin\VenueAdminController::class, 'approve']);
    Route::post('/venues/{id}/reject', [\App\Http\Controllers\Admin\VenueAdminController::class, 'reject']);
    // Events admin
    Route::get('/events', [\App\Http\Controllers\Admin\EventAdminController::class, 'index']);
    Route::get('/events/{id}', [\App\Http\Controllers\Admin\EventAdminController::class, 'show']);
    Route::get('/events/{id}/participants', [\App\Http\Controllers\Admin\EventAdminController::class, 'participants']);
    Route::get('/events/{id}/scores', [\App\Http\Controllers\Admin\EventAdminController::class, 'scores']);
    Route::patch('/events/{id}', [\App\Http\Controllers\Admin\EventAdminController::class, 'update']);
    // Dashboards
    Route::get('/dashboards/overview', [\App\Http\Controllers\Admin\DashboardAdminController::class, 'overview']);
    Route::get('/dashboards/events', [\App\Http\Controllers\Admin\DashboardAdminController::class, 'events']);
    Route::get('/dashboards/venues', [\App\Http\Controllers\Admin\DashboardAdminController::class, 'venues']);
    Route::get('/dashboards/support', [\App\Http\Controllers\Admin\DashboardAdminController::class, 'support']);
    Route::get('/dashboards/ratings', [\App\Http\Controllers\Admin\DashboardAdminController::class, 'ratings']);
    // Exports
    Route::get('/exports/users', [\App\Http\Controllers\Admin\ExportAdminController::class, 'users']);
    Route::get('/exports/venues', [\App\Http\Controllers\Admin\ExportAdminController::class, 'venues']);
    Route::get('/exports/events', [\App\Http\Controllers\Admin\ExportAdminController::class, 'events']);
    Route::get('/exports/tickets', [\App\Http\Controllers\Admin\ExportAdminController::class, 'tickets']);
    Route::get('/exports/ratings', [\App\Http\Controllers\Admin\ExportAdminController::class, 'ratings']);
    // Admin ratings views
    Route::get('/events/{id}/ratings', [\App\Http\Controllers\Admin\RatingAdminController::class, 'listByEvent']);
    Route::get('/ratings/leaderboard', [\App\Http\Controllers\Admin\RatingAdminController::class, 'leaderboard']);
});

// Ratings (user-facing, protected)
Route::middleware('auth:api')->group(function () {
    Route::get('/events/{id}/pending-ratings', [\App\Http\Controllers\EventRatingController::class, 'pending']);
    Route::post('/events/{id}/ratings', [\App\Http\Controllers\EventRatingController::class, 'submit']);
    Route::get('/events/{id}/ratings/summary', [\App\Http\Controllers\EventRatingController::class, 'summary']);
});