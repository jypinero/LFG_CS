<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\VenueController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\NotifController;
use App\Http\Controllers\TeamController;

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

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/roles', [AuthController::class, 'getRoles']);
Route::get('/sports', [AuthController::class, 'getSports']);


Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});



// Protected routes
Route::middleware('auth:api')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/users', [AuthController::class, 'showprofile']);
    Route::get('/profile/me', [AuthController::class, 'myprofile']);
    Route::post('/profile/update', [AuthController::class, 'updateProfile']);
    Route::get('/profile/{username}', [AuthController::class, 'showprofileByUsername']);

    // Route::get('/notifications', [NotifController::class, 'index']);
    Route::get('/users/notifications', [NotifController::class, 'userNotifications']);
    Route::post('/users/notifications/{id}/read', [NotifController::class, 'markAsRead']);
    Route::post('/users/notifications/{id}/unread', [NotifController::class, 'markAsUnread']);
    Route::post('/users/notifications/readall', [NotifController::class, 'markAllRead']);

     // User profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [AuthController::class, 'me']);
        Route::post('/photo', [AuthController::class, 'updateProfilePhoto']);
    });

    Route::get('/schedules', [EventController::class, 'allschedule']);
    Route::get('/schedules/user-created', [EventController::class, 'allusercreated']);
    Route::get('/schedules/{date}', [EventController::class, 'userschedule']);


    Route::post('/venues/games-played', [EventController::class, 'eventsByVenue']);


    Route::get('/events',[EventController::class, 'index']);
    Route::post('/events/create', [EventController::class, 'store']);
    Route::post('/events/join', [EventController::class, 'joinEvent']);
    Route::post('/events/join-team', [EventController::class, 'joinEventAsTeam']);
    Route::post('/events/invite-team', [EventController::class, 'inviteTeamToEvent']);
    Route::get('/events/{id}/participants', [EventController::class , 'eventlist']);

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
    Route::post('/venues/{venue}/facilities', [VenueController::class, 'storeFacility']);

    Route::get('/teams', [TeamController::class, 'index']);
    Route::post('/teams/create', [TeamController::class, 'store']);
    Route::patch('/teams/{teamId}', [TeamController::class, 'update']);
    Route::post('/teams/{teamId}/addmembers', [TeamController::class, 'addMember']);
    Route::post('/teams/{teamId}/transfer-ownership', [TeamController::class, 'transferOwnership']);
    Route::patch('/teams/{teamId}/members/{memberId}/role', [TeamController::class, 'editMemberRole']);
    Route::get('teams/{teamId}/members', [TeamController::class, 'members']);
    Route::delete('teams/{teamId}/members/{memberId}', [TeamController::class, 'removeMember']);
    Route::post('teams/{teamId}/request-join', [TeamController::class, 'requestJoinTeam']);
    Route::post('teams/{teamId}/requests/{memberId}/handle', [TeamController::class, 'handleJoinRequest']);
    

}); 