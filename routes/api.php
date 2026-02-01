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
use App\Http\Controllers\NewTournamentController;
use App\Http\Controllers\FinalTournamentController;
use App\Http\Controllers\ChallongeController;
use App\Http\Controllers\Auth\OtpAuthController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\TrainingSessionController;
use App\Http\Controllers\TrainingAnalyticsController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\LogAdminAction;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\AdditionalTournamentController;
use App\Http\Controllers\PushNotificationController;
use App\Http\Controllers\TeamAnalyticsController;
use App\Http\Controllers\ChallongeAuthController;
use App\Http\Controllers\ImageProxyController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\VenueSubscriptionController;
use App\Http\Controllers\PayMongoWebhookController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\WebhookController;

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

// Route::post('/webhook/paymongo', [WebhookController::class, 'handlePaymongoWebhook']);
// // Route::post('/subscription/create', [SubscriptionController::class, 'createSubscriptionIntent']);
// // Route::post('/webhook', [SubscriptionController::class, 'handleWebhook']); // webhook can be public or secured with secret header
// // Route::post('/retry-payment', [SubscriptionController::class, 'retryPaymentIntent']);
// // Route::post('/confirm-payment', [SubscriptionController::class, 'confirmPayment']);

Route::middleware(['auth:api'])->group(function () {
    Route::post('/subscriptions/start', [VenueSubscriptionController::class, 'start']);
});


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

// Public bracket route (no authentication required)
Route::get('/tournaments/events/{eventId}/bracket/public', [FinalTournamentController::class, 'getPublicBracket']);

// Public event share routes (no authentication required) - matching frontend routes
Route::get('/events/share/{token}', [EventController::class, 'viewByShareToken']);
Route::get('/games/friendlygames/{token}', [EventController::class, 'viewByShareToken']);
Route::get('/games/tune-ups/{token}', [EventController::class, 'viewByShareToken']);

Route::get('auth/challonge/redirect', [ChallongeAuthController::class, 'redirect'])->middleware('auth:api');
Route::get('auth/challonge/callback', [\App\Http\Controllers\ChallongeAuthController::class, 'callback']);
Route::post('auth/challonge/save-tokens', [\App\Http\Controllers\ChallongeAuthController::class, 'saveTokens'])->middleware('auth:api');

// Public push notification route - VAPID key is public (not secret, no auth required)
Route::get('/push/vapid', [PushNotificationController::class, 'getVapidKey']);

// Public image proxy route (no authentication required)
Route::get('/image-proxy', [ImageProxyController::class, 'proxy']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::post('webhooks/challonge', [ChallongeController::class, 'handleWebhook']);

// protected route to push tournament
Route::post('tournaments/{tournament}/push-challonge', [NewTournamentController::class, 'pushTournamentToChallonge'])->middleware('auth');

// Session validation route (before auth middleware to avoid loop)
Route::get('/auth/validate-session', [AuthController::class, 'validateSession'])->middleware('auth:api');


Route::post('/webhooks/paymongo', [PayMongoWebhookController::class, 'handle']);
Route::post('/subscription/webhook', [SubscriptionController::class, 'handleWebhook']);


// Protected routes
Route::middleware('auth:api')->group(function () {

    Route::post('/subscriptions/start', [VenueSubscriptionController::class, 'start']);

    Route::post('/subscription/create-intent', [SubscriptionController::class, 'createSubscriptionIntent']);
    
    // Subscription management endpoints
    Route::get('/subscriptions/status', [SubscriptionController::class, 'getSubscriptionStatus']);
    Route::get('/subscriptions/history', [SubscriptionController::class, 'getSubscriptionHistory']);
    Route::post('/subscriptions/cancel', [SubscriptionController::class, 'cancelSubscription']);
    Route::post('/subscriptions/upgrade', [SubscriptionController::class, 'upgradeSubscription']);
    Route::post('/subscriptions/check-payment', [SubscriptionController::class, 'checkPaymentStatus']);
    Route::post('/subscriptions/manual-activate', [SubscriptionController::class, 'manualActivate']);
    
    // Booking count endpoint
    Route::get('/venues/bookings/count', [VenueController::class, 'getBookingCount']);

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    
    // Onboarding routes
    Route::get('/onboarding/status', [AuthController::class, 'getOnboardingStatus']);
    Route::post('/onboarding/complete', [AuthController::class, 'completeOnboarding']);
    
    // Email verification routes
    Route::post('/email/verification/send', [EmailVerificationController::class, 'sendVerificationOtp'])->middleware('throttle:otp-send');
    Route::post('/email/verification/verify', [EmailVerificationController::class, 'verifyEmail'])->middleware('throttle:otp-verify');
    Route::post('/email/verification/resend', [EmailVerificationController::class, 'resendVerificationOtp'])->middleware('throttle:otp-send');
    
    // Notifications routes - MUST come before /users/{id} to avoid route conflict
    Route::get('/users/notifications', [NotifController::class, 'userNotifications']);
    Route::post('/users/notifications/{id}/read', [NotifController::class, 'markAsRead']);
    Route::post('/users/notifications/{id}/unread', [NotifController::class, 'markAsUnread']);
    Route::post('/users/notifications/readall', [NotifController::class, 'markAllRead']);

    // Home route - sends welcome notification
    Route::get('/home', [NotifController::class, 'sendHomeWelcome']);

    // Push notification routes (authenticated)
    Route::post('/push/subscribe', [PushNotificationController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [PushNotificationController::class, 'unsubscribe']);
    Route::post('/push/test-welcome', [PushNotificationController::class, 'sendTestWelcome']);

    // User search for messaging (exact username match)
    Route::get('/users/search', [AuthController::class, 'searchUsers']);

    // ✅ Add route for getting user by ID (for venue owners, etc.)
    // This must come AFTER /users/notifications and /users/search to avoid wildcard matching
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
    
    // Entity Documents (for venues, teams, coaches, pro athletes)
    Route::prefix('entity-documents')->group(function () {
        Route::post('/', [\App\Http\Controllers\EntityDocumentController::class, 'store']);
        Route::get('/', [\App\Http\Controllers\EntityDocumentController::class, 'index']);
        Route::get('/{id}/download', [\App\Http\Controllers\EntityDocumentController::class, 'download']);
        Route::delete('/{id}', [\App\Http\Controllers\EntityDocumentController::class, 'destroy']);
    });

    // Profile ratings route - MUST come before wildcard username route
    Route::get('/profile/{username}/ratings', [App\Http\Controllers\ProfileController::class, 'getRatings']);
    
    // Wildcard username route - MUST be after all specific /profile/* routes
    Route::get('/profile/{username}', [AuthController::class, 'showprofileByUsername']);

    Route::get('/schedules', [EventController::class, 'allschedule']);
    Route::get('/schedules/user-created', [EventController::class, 'allusercreated']);
    Route::get('/schedules/{date}', [EventController::class, 'userschedule']);
    Route::get('/my-games', [EventController::class, 'myGames']);


    Route::post('/venues/games-played', [EventController::class, 'eventsByVenue']);


    // Event routes
    Route::get('/events',[EventController::class, 'index']);
    Route::get('/events/suggested-tournaments', [EventController::class, 'getSuggestedTournamentGames']);
    Route::post('/events/create', [EventController::class, 'store']);
    Route::get('/events/{id}/share-link', [EventController::class, 'getShareableLink']);
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
    Route::get('/venues/show/{venueId}', [VenueController::class, 'show']);
    Route::get('/venues/search', [VenueController::class, 'search']);
    Route::get('/venues/{venueId}/facilities/list', [VenueController::class, 'getFacilitiesList']);
    Route::get('/venues/{venueId}/reviews', [VenueController::class, 'venueReviews']);
    Route::get('/venues/{venueId}/operating-hours', [VenueController::class, 'getOperatingHours']);
    Route::get('/venues/{venueId}/amenities', [VenueController::class, 'getAmenities']);
    Route::get('/venues/{venueId}/closure-dates', [VenueController::class, 'getClosureDates']);
    Route::get('/venues/{venueId}/facilities/{facilityId}', [VenueController::class, 'showFacilityByVenue']);
    Route::get('/venues/{venueId}/facilities/{facilityId}/booked-slots', [VenueController::class, 'getBookedSlots']);
    Route::get('venues/{venueId}/facilities/{facilityId}/is-booked', [VenueController::class, 'isBooked']);

    // Marketing posts (assuming public)
    Route::post('/marketing/posts/create', [MarketingController::class, 'createpost']);
    Route::get('/marketing/posts', [MarketingController::class, 'index']);

    // Read-only venue endpoints (accessible without subscription)
    Route::get('/venues/owner', [VenueController::class, 'OwnerVenues']);
    Route::get('/venues/created', [VenueController::class, 'CreatedVenues']);
    Route::get('/venues/owner/archived', [VenueController::class, 'OwnerArchivedVenues']);
    Route::get('/venues/member', [VenueController::class, 'memberVenues']);
    Route::get('/venues/analytics/{venueId?}', [VenueController::class, 'getAnalytics']);
    Route::get('/venues/bookings', [VenueController::class, 'getBookings']);
    Route::get('/venues/{venueId}/members', [VenueController::class, 'staff']);

    Route::middleware(['active.subscription'])->group(function () {
        // Venue management (write operations require subscription)
        Route::post('/venues/create', [VenueController::class, 'store']);
        Route::post('/venues/edit/{venueId}', [VenueController::class, 'update']);
        Route::delete('/venues/delete/{venueId}', [VenueController::class, 'destroy']);
        Route::post('/venues/{venueId}/photos', [VenueController::class, 'addVenuePhoto'])->name('venues.photos.store');
        Route::delete('/venues/{venueId}/photos/{photoId}', [VenueController::class, 'destroyVenuePhoto'])->name('venues.photos.destroy');

        Route::post('/venues/{venueId}/close', [VenueController::class, 'closeVenue']);
        Route::post('/venues/{venueId}/reopen', [VenueController::class, 'reopenVenue']);
        Route::post('/venues/{venueId}/transfer-ownership', [VenueController::class, 'transferOwnership']);

        // Facilities management
        Route::post('/venues/{venueId}/facilities', [VenueController::class, 'storeFacility']);
        Route::post('/venues/{venueId}/facilities/edit/{facilityId}', [VenueController::class, 'updateFacilityByVenue']);
        Route::delete('/venues/{venueId}/facilities/delete/{facilityId}', [VenueController::class, 'destroyFacilityByVenue']);
        Route::post('/venues/{venueId}/facilities/{facilityId}/photos', [VenueController::class, 'addFacilityPhoto'])->name('venues.facilities.photos.store');
        Route::delete('/venues/{venueId}/facilities/{facilityId}/photos/{photoId}', [VenueController::class, 'destroyFacilityPhoto'])->name('venues.facilities.photos.destroy');
        Route::post('/venues/{venueId}/facilities/{facilityId}/close', [VenueController::class, 'closeFacility']);
        Route::post('/venues/{venueId}/facilities/{facilityId}/reopen', [VenueController::class, 'reopenFacility']);

        // Members / Staff management (write operations)
        Route::post('/venues/{venueId}/addmembers', [VenueController::class, 'addMember']);

        // Booking management (write operations)
        Route::put('/venues/bookings/{id}/status', [VenueController::class, 'updateBookingStatus']);
        Route::post('/venues/bookings/{id}/cancel', [VenueController::class, 'cancelEventBooking']);
        Route::patch('/venues/bookings/{id}/reschedule', [VenueController::class, 'rescheduleEventBooking']);

        // Reviews
        Route::post('/venues/{venueId}/post-reviews', [VenueController::class, 'PostReview']);
        Route::post('/venues/{venueId}/reviews/{reviewId}/reply', [VenueController::class, 'replyToReview']);

        // Operating Hours Management
        Route::post('/venues/{venueId}/operating-hours', [VenueController::class, 'addOperatingHours']);
        Route::put('/venues/{venueId}/operating-hours/{hoursId}', [VenueController::class, 'updateOperatingHours']);
        Route::delete('/venues/{venueId}/operating-hours/{hoursId}', [VenueController::class, 'deleteOperatingHours']);

        // Amenities Management
        Route::post('/venues/{venueId}/amenities', [VenueController::class, 'addAmenity']);
        Route::put('/venues/{venueId}/amenities/{amenityId}', [VenueController::class, 'updateAmenity']);
        Route::delete('/venues/{venueId}/amenities/{amenityId}', [VenueController::class, 'deleteAmenity']);

        // Closure Dates Management
        Route::post('/venues/{venueId}/closure-dates', [VenueController::class, 'addClosureDate']);
        Route::put('/venues/{venueId}/closure-dates/{closureId}', [VenueController::class, 'updateClosureDate']);
        Route::delete('/venues/{venueId}/closure-dates/{closureId}', [VenueController::class, 'deleteClosureDate']);
    });

    // Route::get('/venues', [VenueController::class, 'index']);
    // Route::post('/venues/create', [VenueController::class, 'store']);
    // Route::post('/venues/{venueId}/facilities', [VenueController::class, 'storeFacility']);
    // Route::get('/venues/show/{venueId}', [VenueController::class, 'show']);
    // Route::post('/venues/edit/{venueId}', [VenueController::class, 'update']);
    // Route::delete('/venues/delete/{venueId}', [VenueController::class, 'destroy']);
    // Route::post('/venues/{venueId}/photos', [VenueController::class, 'addVenuePhoto'])->name('venues.photos.store');
    // Route::delete('/venues/{venueId}/photos/{photoId}', [VenueController::class, 'destroyVenuePhoto'])->name('venues.photos.destroy');
    // Route::get('/venues/owner', [VenueController::class, 'OwnerVenues']);
    // Route::get('/venues/created', [VenueController::class, 'CreatedVenues']);
    // Route::get('/venues/owner/archived', [VenueController::class, 'OwnerArchivedVenues']);
    // Route::post('/venues/{venueId}/close', [VenueController::class, 'closeVenue']);
    // Route::post('/venues/{venueId}/reopen', [VenueController::class, 'reopenVenue']);
    // Route::post('/venues/{venueId}/transfer-ownership', [VenueController::class, 'transferOwnership']);

    // Route::get('/venues/member', [VenueController::class, 'memberVenues']);
    
    // // Facilities list route must come before the {facilityId} route to avoid route conflict
    // Route::get('/venues/{venueId}/facilities/list', [VenueController::class, 'getFacilitiesList']);
    
    // Route::get('/venues/{venueId}/facilities/{facilityId}', [\App\Http\Controllers\VenueController::class, 'showFacilityByVenue']);
    // Route::get('/venues/{venueId}/facilities/{facilityId}/booked-slots', [VenueController::class, 'getBookedSlots']);
    // Route::post('/venues/{venueId}/facilities/edit/{facilityId}', [\App\Http\Controllers\VenueController::class, 'updateFacilityByVenue']);
    // Route::delete('/venues/{venueId}/facilities/delete/{facilityId}', [\App\Http\Controllers\VenueController::class, 'destroyFacilityByVenue']);

    // Route::post('/venues/{venueId}/facilities/{facilityId}/photos', [\App\Http\Controllers\VenueController::class, 'addFacilityPhoto'])->name('venues.facilities.photos.store');
    // Route::delete('/venues/{venueId}/facilities/{facilityId}/photos/{photoId}', [\App\Http\Controllers\VenueController::class, 'destroyFacilityPhoto'])->name('venues.facilities.photos.destroy');
    // Route::post('/venues/{venueId}/facilities/{facilityId}/close', [VenueController::class, 'closeFacility']);
    // Route::post('/venues/{venueId}/facilities/{facilityId}/reopen', [VenueController::class, 'reopenFacility']);
    // Route::post('/venues/{venueId}/addmembers', [\App\Http\Controllers\VenueController::class, 'addMember']);
    // Route::get('venues/{venueId}/members', [VenueController::class, 'staff']);

    // // Booking management routes
    // Route::get('/venues/bookings', [VenueController::class, 'getBookings']);
    // Route::put('/venues/bookings/{id}/status', [VenueController::class, 'updateBookingStatus']);
    // Route::post('/venues/bookings/{id}/cancel', [VenueController::class, 'cancelEventBooking']);
    // Route::patch('/venues/bookings/{id}/reschedule', [VenueController::class, 'rescheduleEventBooking']);

    // Route::post('/venues/{venueId}/post-reviews', [\App\Http\Controllers\VenueController::class, 'PostReview']);
    // Route::get('/venues/{venueId}/reviews', [\App\Http\Controllers\VenueController::class, 'venueReviews']);

    // Route::get('/venues/search', [\App\Http\Controllers\VenueController::class, 'search']);

    // Route::get('/venues/analytics/{venueId?}', [VenueController::class, 'getAnalytics']);

    // // Operating Hours Management
    // Route::get('/venues/{venueId}/operating-hours', [VenueController::class, 'getOperatingHours']);
    // Route::post('/venues/{venueId}/operating-hours', [VenueController::class, 'addOperatingHours']);
    // Route::put('/venues/{venueId}/operating-hours/{hoursId}', [VenueController::class, 'updateOperatingHours']);
    // Route::delete('/venues/{venueId}/operating-hours/{hoursId}', [VenueController::class, 'deleteOperatingHours']);

    // // Amenities Management
    // Route::get('/venues/{venueId}/amenities', [VenueController::class, 'getAmenities']);
    // Route::post('/venues/{venueId}/amenities', [VenueController::class, 'addAmenity']);
    // Route::put('/venues/{venueId}/amenities/{amenityId}', [VenueController::class, 'updateAmenity']);
    // Route::delete('/venues/{venueId}/amenities/{amenityId}', [VenueController::class, 'deleteAmenity']);

    // // Closure Dates Management
    // Route::get('/venues/{venueId}/closure-dates', [VenueController::class, 'getClosureDates']);
    // Route::post('/venues/{venueId}/closure-dates', [VenueController::class, 'addClosureDate']);
    // Route::put('/venues/{venueId}/closure-dates/{closureId}', [VenueController::class, 'updateClosureDate']);
    // Route::delete('/venues/{venueId}/closure-dates/{closureId}', [VenueController::class, 'deleteClosureDate']);


    // Route::get('venues/{venueId}/facilities/{facilityId}/is-booked', [VenueController::class, 'isBooked']);

    // // Marketing Controller Routes
    // Route::post('/marketing/posts/create', [MarketingController::class, 'createpost']);
    // Route::get('/marketing/posts', [MarketingController::class, 'index']);


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
    Route::post('teams/{teamId}/members/{memberId}/restore', [TeamController::class, 'restoreMember']);
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

   // Team Analytics
    Route::get('/teams/{teamId}/analytics/overview', [TeamAnalyticsController::class, 'overview']);
    Route::get('/teams/{teamId}/analytics/report', [TeamAnalyticsController::class, 'report']);


     // Tournaments
    Route::get('tournaments', [FinalTournamentController::class, 'index']);
    Route::post('tournaments/create', [FinalTournamentController::class, 'storeTournament']);
    Route::get('tournaments/{id}', [FinalTournamentController::class, 'showTournament']);
    Route::put('tournaments/{id}', [FinalTournamentController::class, 'updateTournament']);
    Route::get('tournaments/{tournamentId}/rulebook/download', [FinalTournamentController::class, 'downloadRulebook']);

    // Sub-events
    Route::post('tournaments/{tournamentId}/sub-events', [FinalTournamentController::class, 'storeSubEvent']);
    Route::post('tournaments/{tournamentId}/sub-events/{eventId}/cancel', [FinalTournamentController::class, 'cancelSubEvent']);
    Route::post('tournaments/events/{eventId}/games', [FinalTournamentController::class, 'storeEventGame']);
    Route::get('tournaments/events/{eventId}/games', [FinalTournamentController::class, 'getBracket']);

    // Events / registration / participants
    Route::post('tournaments/events/{eventId}/register', [FinalTournamentController::class, 'register']);
    Route::get('tournaments/events/{eventId}/participants', [FinalTournamentController::class, 'participants']);

    // Participant status (approve/decline)
    Route::patch('tournaments/event-participants/{participantId}/status', [FinalTournamentController::class, 'updateParticipantStatus']);
    
    // // Tournament Announcements
    Route::post('/tournaments/{tournamentId}/announcements/create', [\App\Http\Controllers\TournamentAnnouncementController::class, 'createAnnouncement']);
    Route::get('/tournaments/{tournamentId}/announcements/get', [\App\Http\Controllers\TournamentAnnouncementController::class, 'getAnnouncements']);
    Route::put('/tournaments/{tournamentId}/announcements/{announcementId}/put', [\App\Http\Controllers\TournamentAnnouncementController::class, 'updateAnnouncement']);
    Route::delete('/tournaments/{tournamentId}/announcements/{announcementId}/delete', [\App\Http\Controllers\TournamentAnnouncementController::class, 'deleteAnnouncement']);

    Route::get('my-tournaments', [\App\Http\Controllers\FinalTournamentController::class, 'myTournaments']);
    Route::get('joined-tournaments', [\App\Http\Controllers\FinalTournamentController::class, 'joinedTournaments']);

    //Challonge Integration Routes
    // Route::post('challonge/tournaments/{id}/start', [ChallongeController::class, 'startTournament']);
    // Route::post('challonge/tournaments/{id}/push-games', [ChallongeController::class, 'pushEventGames']);
    // Route::post('challonge/matches/{match_id}/sync-score', [ChallongeController::class, 'syncScoreToChallonge']);
    // Route::get('challonge/tournaments/{id}/bracket', [ChallongeController::class, 'fetchBracket']);

    // Tournament Management Routes
    // Tournament CRUD
    // Route::get('/tournaments', [NewTournamentController::class, 'index']);
    // Route::post('/tournaments/create', [NewTournamentController::class, 'create']);
    
    // Route::post('/tournaments/{tournamentId}/register', [NewTournamentController::class, 'registerParticipant']);
    // Route::post('/tournaments/participants/{participantId}/approve', [NewTournamentController::class, 'approveParticipant']);
    
    // // List participants by status per tournament
    // Route::get('tournaments/{tournamentId}/participants', [NewTournamentController::class, 'listParticipants']);

    // // List rejected participants (organizers only)
    // Route::get('tournaments/{tournamentId}/participants/rejected', [NewTournamentController::class, 'listRejectedParticipants']);

    // // Register participant already exists in your controller (assumed route)

    // // Withdraw registration by participant
    // Route::post('tournaments/participants/{participantId}/withdraw', [NewTournamentController::class, 'withdrawRegistration']);

    // // Upload participant document
    // Route::post('tournaments/participants/{participantId}/documents', [NewTournamentController::class, 'uploadDocument']);

    // Route::post('/tournaments/{tournamentId}/events', [NewTournamentController::class, 'createEvent']);

    // Route::get('/tournaments/{tournamentId}/events', [NewTournamentController::class, 'listEvents']);
    // Route::put('/tournaments/events/{eventId}', [NewTournamentController::class, 'updateEvent']);
    // Route::post('/tournaments/events/{eventId}/cancel', [NewTournamentController::class, 'cancelEvent']);

    // Route::post('tournaments/events/{eventId}/register', [NewTournamentController::class, 'registerForEvent']);
    // Route::post('tournaments/participants/{participantId}/event-approve', [NewTournamentController::class, 'approveEventParticipant']);
    // Route::get('tournaments/events/{eventId}/participants', [NewTournamentController::class, 'listEventParticipants']);

    // Route::post('/tournaments/events/{eventId}/generate-schedule', [NewTournamentController::class, 'generateSchedule']);
    // Route::post('/tournaments/event-game/{gameId}/submit-score', [NewTournamentController::class, 'submitScore']);

    // // Tournament CRUD - Additional routes
    // // Specific routes must come before parameterized routes
    // Route::get('/tournaments/my', [NewTournamentController::class, 'myTournaments']);
    
    // Route::get('/tournaments/{tournamentId}', [NewTournamentController::class, 'show']);
    // Route::put('/tournaments/{tournamentId}', [NewTournamentController::class, 'update']);
    // Route::patch('/tournaments/{tournamentId}', [NewTournamentController::class, 'update']);
    // Route::delete('/tournaments/{tournamentId}', [NewTournamentController::class, 'destroy']);

    // // Games/Matches
    // Route::get('/tournaments/events/{eventId}/games', [NewTournamentController::class, 'listEventGames']);
    // Route::get('/tournaments/events/{eventId}/bracket', [NewTournamentController::class, 'getBracket']);
    // Route::get('/tournaments/event-game/{gameId}', [NewTournamentController::class, 'getGame']);
    // Route::get('/tournaments/{tournamentId}/schedule', [NewTournamentController::class, 'getTournamentSchedule']);

    // // Results
    // Route::get('/tournaments/events/{eventId}/champion', [NewTournamentController::class, 'getEventChampion']);
    // Route::get('/tournaments/{tournamentId}/results', [NewTournamentController::class, 'getTournamentResults']);

    // // Public
    // Route::get('/tournaments/public/{tournamentId}', [NewTournamentController::class, 'getPublicTournament']);

    // // Announcements
    // Route::post('/tournaments/{tournamentId}/announcements', [NewTournamentController::class, 'createAnnouncement']);
    // Route::get('/tournaments/{tournamentId}/announcements', [NewTournamentController::class, 'getAnnouncements']);
    // Route::put('/tournaments/{tournamentId}/announcements/{announcementId}', [NewTournamentController::class, 'updateAnnouncement']);
    // Route::delete('/tournaments/{tournamentId}/announcements/{announcementId}', [NewTournamentController::class, 'deleteAnnouncement']);

    // // Challonge UI Integration - GET endpoints (read/display)
    // Route::get('/tournaments/{tournamentId}/challonge/status', [ChallongeController::class, 'getTournamentStatus']);
    // Route::get('/tournaments/{tournamentId}/challonge/tournament', [ChallongeController::class, 'getChallongeTournament']);
    // Route::get('/tournaments/{tournamentId}/challonge/bracket', [ChallongeController::class, 'getChallongeBracket']);
    // Route::get('/tournaments/{tournamentId}/challonge/matches', [ChallongeController::class, 'getChallongeMatches']);
    // Route::get('/tournaments/{tournamentId}/challonge/embed', [ChallongeController::class, 'getChallongeEmbed']);
    // Route::post('/tournaments/{tournamentId}/challonge/refresh', [ChallongeController::class, 'refreshChallongeData']);
    // Route::get('/challonge/connection-status', [ChallongeController::class, 'checkChallongeConnection']);

    // // Challonge UI Integration - POST/PUT endpoints (push/update)
    // Route::put('/tournaments/{tournamentId}/challonge/tournament', [ChallongeController::class, 'updateChallongeTournament']);
    // Route::post('/tournaments/{tournamentId}/challonge/sync-participants', [ChallongeController::class, 'syncParticipants']);
    // Route::post('/tournaments/event-game/{gameId}/challonge/sync-score', [ChallongeController::class, 'syncMatchScore']);
    // Route::post('/tournaments/events/{eventId}/challonge/push-games', [ChallongeController::class, 'pushEventGamesToChallonge']);
    // Route::post('/tournaments/events/{eventId}/challonge/sync-bracket', [ChallongeController::class, 'syncBracket']);


    // Route::get('/tournaments', [TournamentController::class, 'index']);
    // Route::post('tournaments/create', [TournamentController::class, 'create']);
    // Route::get('tournaments/show/{id}', [TournamentController::class, 'show']);
    // Route::put('tournaments/update/{id}', [TournamentController::class, 'update']);
    // Route::delete('tournaments/delete/{id}', [TournamentController::class, 'destroy']);
    // Route::get('/tournaments/my', [TournamentController::class, 'myTournaments']);
    // Route::get('tournaments/{tournamentId}/schedule', [TournamentController::class, 'getSchedule']);
    
    // // Games (existing)
    // Route::post('tournaments/{tournamentid}/creategames', [TournamentController::class, 'createGame']);
    // Route::get('tournaments/{tournamentid}/getgames', [TournamentController::class, 'getGames']);
    // Route::put('tournaments/{tournamentid}/updategames/{gameid}', [TournamentController::class, 'updateGame']);
    // Route::patch('tournaments/{tournamentid}/updategames/{gameid}', [TournamentController::class, 'updateGame']);
    // Route::delete('tournaments/{tournamentid}/deletegames/{gameid}', [TournamentController::class, 'deleteGame']);

    // // Registration & participant management routes (added)
    // // Single registration endpoint (handles individual OR team registration)
    // Route::post('tournaments/{tournamentid}/register/{eventid}', [TournamentController::class, 'register']);

    // // Participants list and admin actions
    // Route::get('tournaments/{tournamentid}/participants', [TournamentController::class, 'getParticipants']);
    // Route::post('tournaments/{tournamentid}/participants/{participantid}/approve', [TournamentController::class, 'approveParticipant']);
    // Route::post('tournaments/{tournamentid}/participants/bulk-approve', [TournamentController::class, 'bulkApproveParticipants']);
    // Route::post('tournaments/{tournamentid}/participants/{participantid}/reject', [TournamentController::class, 'rejectParticipant']);
    // Route::post('tournaments/{tournamentid}/participants/{participantid}/ban', [TournamentController::class, 'banParticipant']);

    //  // Document Management
    // Route::post('tournaments/{tournamentid}/documents/upload', [TournamentController::class, 'uploadDocument']);
    // Route::get('tournaments/{tournamentid}/documents', [TournamentController::class, 'getDocuments']);
    // Route::get('tournaments/{tournamentid}/participants/{participantId}/documents', [TournamentController::class, 'getParticipantDocuments']);
    // Route::post('tournaments/{tournamentid}/documents/{documentId}/verify', [TournamentController::class, 'verifyDocument']);
    // Route::delete('tournaments/{tournamentid}/documents/{documentId}/delete', [TournamentController::class, 'deleteDocument']);

    // // Team Management within Tournaments (organizers)
    // Route::post('tournaments/{tournamentid}/events/{eventid}/assign-teams', [TournamentController::class, 'assignTeams']);
    // Route::post('tournaments/{tournamentid}/events/{eventid}/auto-balance', [TournamentController::class, 'autoBalanceTeams']);
    // Route::post('tournaments/{tournamentid}/teams/{teamid}/replace-player', [TournamentController::class, 'replacePlayer']);
    // Route::post('tournaments/{tournamentid}/participants/{participantid}/no-show', [TournamentController::class, 'markNoShow']);

    // // Match Management
    // Route::get('tournaments/{tournamentid}/matches', [\App\Http\Controllers\TournamentController::class, 'getMatches']);
    // Route::get('tournaments/{tournamentid}/matches/live', [\App\Http\Controllers\TournamentController::class, 'getLiveMatches']);
    // Route::get('tournaments/{tournamentid}/matches/{match}', [\App\Http\Controllers\TournamentController::class, 'getMatchDetails']);
    // Route::post('tournaments/{tournamentid}/matches/{match}/start', [\App\Http\Controllers\TournamentController::class, 'startMatch']);
    // Route::post('tournaments/{tournamentid}/matches/{match}/end', [\App\Http\Controllers\TournamentController::class, 'endMatch']);
    // Route::post('tournaments/{tournamentid}/matches/{match}/score', [\App\Http\Controllers\TournamentController::class, 'updateScore']);
    // Route::post('tournaments/{tournamentid}/matches/{match}/penalty', [\App\Http\Controllers\TournamentController::class, 'issuePenalty']);
    // Route::post('tournaments/{tournamentid}/matches/{match}/forfeit', [\App\Http\Controllers\TournamentController::class, 'markForfeit']);
    // Route::post('tournaments/{tournamentid}/matches/{match}/results', [\App\Http\Controllers\TournamentController::class, 'uploadResult']);
    // Route::post('tournaments/{tournamentId}/matches/{match}/dispute', [\App\Http\Controllers\TournamentController::class, 'disputeResult']);
    // Route::post('tournaments/{tournamentId}/matches/{match}/resolve-dispute', [\App\Http\Controllers\TournamentController::class, 'resolveDispute']);

    // // Bracket Generation
    // Route::post('tournaments/{tournament}/events/{event}/generate-brackets', [TournamentController::class, 'generateBrackets']);
    // Route::post('tournaments/{tournamentId}/matches/{matchId}/advance', [TournamentController::class, 'advanceBracket']);


    // // Analytics routes
    // Route::get('/tournaments/{tournamentId}/analytics', [\App\Http\Controllers\AnalyticsController::class, 'getAnalytics']);
    // Route::get('/tournaments/{tournamentId}/standings', [\App\Http\Controllers\AnalyticsController::class, 'getStandings']);
    // Route::get('/tournaments/{tournamentId}/leaderboard', [\App\Http\Controllers\AnalyticsController::class, 'getLeaderboard']);
    
    // // Activity log
    // Route::get('/tournaments/{tournamentId}/activity-log', [TournamentController::class, 'getActivityLog']);
    
    // // Spectator count
    // Route::get('/tournaments/{tournamentId}/spectator-count', [TournamentController::class, 'getSpectatorCount']);
    
    // // Tournament settings
    // Route::patch('/tournaments/{tournamentId}/settings', [TournamentController::class, 'updateTournamentSettings']);

    // // Organizer Management
    // Route::post('/tournaments/{tournamentId}/organizers', [TournamentController::class, 'addOrganizer']);
    // Route::delete('/tournaments/{tournamentId}/organizers/{userId}', [TournamentController::class, 'removeOrganizer']);
    // Route::get('/tournaments/{tournamentId}/organizers', [TournamentController::class, 'listOrganizers']);
    // Route::put('/tournaments/{tournamentId}/organizers/{userId}/role', [TournamentController::class, 'updateOrganizerRole']);

    // // Registration Withdrawal
    // Route::delete('/tournaments/{tournamentId}/withdraw', [TournamentController::class, 'withdraw']);

    // // Waitlist Management
    // Route::post('/tournaments/{tournamentId}/waitlist', [TournamentController::class, 'joinWaitlist']);
    // Route::delete('/tournaments/{tournamentId}/waitlist', [TournamentController::class, 'removeFromWaitlist']);
    // Route::get('/tournaments/{tournamentId}/waitlist', [TournamentController::class, 'getWaitlist']);
    // Route::post('/tournaments/{tournamentId}/waitlist/promote', [TournamentController::class, 'promoteFromWaitlist']);

    // // Tournament Phases Managementv
    // Route::post('/tournaments/{tournamentId}/phases', [TournamentController::class, 'createPhase']);
    // Route::get('/tournaments/{tournamentId}/phases', [TournamentController::class, 'listPhases']);
    // Route::put('/tournaments/{tournamentId}/phases/{phaseId}', [TournamentController::class, 'updatePhase']);
    // Route::delete('/tournaments/{tournamentId}/phases/{phaseId}', [TournamentController::class, 'deletePhase']);
    // Route::post('/tournaments/{tournamentId}/phases/reorder', [TournamentController::class, 'reorderPhases']);

    // // Tournament Status Management
    // Route::post('/tournaments/{tournamentId}/open-registration', [TournamentController::class, 'openRegistration']);
    // Route::post('/tournaments/{tournamentId}/close-registration', [TournamentController::class, 'closeRegistration']);
    // Route::post('/tournaments/{tournamentId}/start', [TournamentController::class, 'startTournament']);
    // Route::post('/tournaments/{tournamentId}/complete', [TournamentController::class, 'completeTournament']);
    
    // // Tournament Cancellation
    // Route::post('/tournaments/{tournamentId}/cancel', [TournamentController::class, 'cancelTournament']);

    // // Tournament Templates
    // Tournament Lifecycle Management Routes (NewTournamentController)
    // Route::post('/tournaments/{tournamentId}/open-registration', [NewTournamentController::class, 'openRegistration']);
    // Route::post('/tournaments/{tournamentId}/close-registration', [NewTournamentController::class, 'closeRegistration']);
    // Route::post('/tournaments/{tournamentId}/start', [NewTournamentController::class, 'startTournament']);
    // Route::post('/tournaments/{tournamentId}/complete', [NewTournamentController::class, 'completeTournament']);

    
    // Event check-in routes within tournaments

    // Route::post('events/{event}/checkin', [TournamentController::class, 'checkinEvent']); // code-based checkin (if present)
    // Route::post('events/checkin/qr', [TournamentController::class, 'checkinQR']);
    // Route::post('events/checkin/code', [TournamentController::class, 'checkinCode']);
    // Route::post('events/checkin/manual', [TournamentController::class, 'checkinManual']);
    // Route::get('events/{event}/checkins', [TournamentController::class, 'viewCheckins']);


    // Route::post('tournaments/{tournamentId}/matches/{matchId}/reset', [TournamentController::class,'resetMatch']);
    // Route::post('tournaments/{tournamentId}/bracket/reset/{eventId}', [TournamentController::class,'resetBracket']);


    // Coach Application Routes
    Route::post('/coach/createprofile', [CoachController::class, 'createProfile']);
    Route::get('/coach/getmyprofile', [CoachController::class, 'getMyProfile']);
    Route::put('/coach/updateprofile', [CoachController::class, 'updateProfile']);

    Route::get('/coach/swipe/card', [CoachController::class, 'getSwipeCard']);
    Route::get('/coach/matches/pending', [CoachController::class, 'getPendingMatches']);
    Route::post('/coach/matches/{matchId}/respond', [CoachController::class, 'respondToMatch']);
    Route::get('/coach/students', [CoachController::class, 'getStudents']);
    Route::get('/coach/students/{studentId}', [CoachController::class, 'getStudentDetail']);
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


    Route::post('/events/{event}/ratings',[RatingController::class, 'submit']);
    Route::post('/events/{event}/team-ratings',[RatingController::class, 'submitTeamRating']);
    Route::get('/profile/rating/{userId}', [App\Http\Controllers\ProfileController::class, 'show']);

    
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
    Route::post('/venues/{id}/reset-verification', [\App\Http\Controllers\Admin\VenueAdminController::class, 'resetVerification']);
    Route::get('/venues/{id}/documents', [\App\Http\Controllers\Admin\VenueAdminController::class, 'documents']);
    // Teams admin
    Route::get('/teams', [\App\Http\Controllers\Admin\TeamAdminController::class, 'index']);
    Route::get('/teams/{id}', [\App\Http\Controllers\Admin\TeamAdminController::class, 'show']);
    Route::post('/teams/{id}/approve', [\App\Http\Controllers\Admin\TeamAdminController::class, 'approve']);
    Route::post('/teams/{id}/reject', [\App\Http\Controllers\Admin\TeamAdminController::class, 'reject']);
    Route::post('/teams/{id}/reset-verification', [\App\Http\Controllers\Admin\TeamAdminController::class, 'resetVerification']);
    Route::get('/teams/{id}/documents', [\App\Http\Controllers\Admin\TeamAdminController::class, 'documents']);
    Route::get('/teams/statistics', [\App\Http\Controllers\Admin\TeamAdminController::class, 'statistics']);
    // Coaches admin
    Route::get('/coaches', [\App\Http\Controllers\Admin\CoachAdminController::class, 'index']);
    Route::get('/coaches/{id}', [\App\Http\Controllers\Admin\CoachAdminController::class, 'show']);
    Route::post('/coaches/{id}/approve', [\App\Http\Controllers\Admin\CoachAdminController::class, 'approve']);
    Route::post('/coaches/{id}/reject', [\App\Http\Controllers\Admin\CoachAdminController::class, 'reject']);
    Route::post('/coaches/{id}/reset-verification', [\App\Http\Controllers\Admin\CoachAdminController::class, 'resetVerification']);
    Route::get('/coaches/{id}/documents', [\App\Http\Controllers\Admin\CoachAdminController::class, 'documents']);
    Route::get('/coaches/statistics', [\App\Http\Controllers\Admin\CoachAdminController::class, 'statistics']);
    // Users (Pro Athletes) - update existing
    Route::post('/users/{id}/approve', [\App\Http\Controllers\Admin\UserAdminController::class, 'approve']);
    Route::post('/users/{id}/reject', [\App\Http\Controllers\Admin\UserAdminController::class, 'reject']);
    Route::get('/users/{id}/documents', [\App\Http\Controllers\Admin\UserAdminController::class, 'documents']);
    Route::get('/users/statistics', [\App\Http\Controllers\Admin\UserAdminController::class, 'statistics']);
    // Entity Documents admin (polymorphic)
    Route::get('/entity-documents', [\App\Http\Controllers\Admin\EntityDocumentAdminController::class, 'index']);
    Route::get('/entity-documents/{id}', [\App\Http\Controllers\Admin\EntityDocumentAdminController::class, 'show']);
    Route::post('/entity-documents/{id}/verify', [\App\Http\Controllers\Admin\EntityDocumentAdminController::class, 'verify']);
    Route::post('/entity-documents/{id}/reject', [\App\Http\Controllers\Admin\EntityDocumentAdminController::class, 'reject']);
    Route::post('/entity-documents/{id}/reset', [\App\Http\Controllers\Admin\EntityDocumentAdminController::class, 'resetVerification']);
    Route::get('/entity-documents/{id}/download', [\App\Http\Controllers\Admin\EntityDocumentAdminController::class, 'download']);
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
// Route::middleware('auth:api')->group(function () {
//     Route::get('/events/{id}/pending-ratings', [\App\Http\Controllers\EventRatingController::class, 'pending']);
//     Route::post('/events/{id}/ratings', [\App\Http\Controllers\EventRatingController::class, 'submit']);
//     Route::get('/events/{id}/ratings/summary', [\App\Http\Controllers\EventRatingController::class, 'summary']);
// });