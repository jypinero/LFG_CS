<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\VenueController;
use App\Http\Controllers\PostController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/roles', [AuthController::class, 'getRoles']);

// Protected routes
Route::middleware('auth:api')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/users', [AuthController::class, 'showprofile']);
    Route::get('/profile/me', [AuthController::class, 'myprofile']);
    Route::get('/profile/{username}', [AuthController::class, 'showprofileByUsername']);

    Route::get('/schedules/{date}', [EventController::class, 'userschedule']);

    // User profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [AuthController::class, 'me']);
        Route::post('/photo', [AuthController::class, 'updateProfilePhoto']);
    });

    Route::get('/events',[EventController::class, 'index']);
    Route::post('/events/create', [EventController::class, 'store']);
    Route::post('/events/join', [EventController::class, 'joinEvent']);
    Route::get('/events/{id}/participants', [EventController::class , 'eventlist']);

    Route::get('/posts', [PostController::class, 'index']);
    Route::get('/posts/see/{id}', [PostController::class, 'seepost']);
    Route::delete('/posts/delete/{postId}', [PostController::class, 'deletepost']);

    Route::post('/posts/create', [PostController::class, 'createpost']);
    Route::post('/posts/{postId}/like', [PostController::class, 'likepost']);
    Route::get('/posts/{postId}/view_likes', [PostController::class, 'seelike']);
    Route::post('/posts/{postId}/comment', [PostController::class, 'commentpost']);
    Route::get('/posts/{postId}/view_comments', [PostController::class, 'seecomments']);


    Route::get('/venues', [VenueController::class, 'index']);
    Route::post('/venues/create', [VenueController::class, 'store']);
    Route::post('/venues/{venue}/facilities', [VenueController::class, 'storeFacility']);
    


}); 