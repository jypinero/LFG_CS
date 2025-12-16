<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\PostComment;
use App\Models\Notification;
use App\Models\UserNotification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    public function index()
    {
        $userId = auth()->id();

        // Get event IDs where the user participated
        $eventIds = \App\Models\EventParticipant::where('user_id', $userId)->pluck('event_id');

        // Get all user IDs who participated in those events
        $userIds = \App\Models\EventParticipant::whereIn('event_id', $eventIds)
            ->pluck('user_id')
            ->unique();

        // Make sure the user sees their own posts too
        $userIds->push($userId);

        // Get posts from those users with like and comment counts, only not archived
        $posts = Post::whereIn('author_id', $userIds)
            ->where('is_archived', false) // Only show not archived posts
            ->with(['author'])
            ->withCount(['likes', 'comments'])
            ->get()
            ->map(function($post) use ($userId) {
                // Check if the current user liked this post
                $isLiked = $post->likes()->where('user_id', $userId)->where('is_liked', true)->exists();

                return [
                    'id' => $post->id,
                    'author' => [
                        'id' => $post->author->id,
                        'username' => $post->author->username,
                        'profile_photo' => $post->author->profile_photo ? \Storage::url($post->author->profile_photo) : null,
                    ],
                    'location' => $post->location,
                    'image_url' => $post->image_url ? \Storage::url($post->image_url) : null,
                    'caption' => $post->caption,
                    'created_at' => $post->created_at,
                    'likes_count' => $post->likes_count,
                    'comments_count' => $post->comments_count,
                    'is_liked' => $isLiked, // Add this field
                ];
            });

        return response()->json([
            'message' => 'See Posts successfully',
            'posts' => $posts
        ], 200);
    }

    public function seepost($id)
    {
        $userId = auth()->id();

        // Eager load author, likes, and comments
        $post = Post::with(['author', 'likes', 'comments'])
            ->withCount(['likes', 'comments'])
            ->findOrFail($id);

        return response()->json([
            'message' => 'See Post successfully',
            'post' => [
                'id' => $post->id,
                'author' => [
                    'id' => $post->author->id,
                    'username' => $post->author->username,
                    'profile_photo' => $post->author->profile_photo ? \Storage::url($post->author->profile_photo) : null,
                  
                  
                ],
                'location' => $post->location,
                'image_url' => $post->image_url ? \Storage::url($post->image_url) : null,
                'caption' => $post->caption,
                'created_at' => $post->created_at,
                'likes_count' => $post->likes_count,
                'comments_count' => $post->comments_count,
                
            ],
        ], 200);
    }
    
    public function createpost(Request $request)
    {

        $validated = $request->validate([
            'location' => 'nullable|string',
            'image_url' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Required image
            'caption' => 'nullable|string',
            'created_at' => 'required|date',
        ]);

        $postImagePath = null;
        if ($request->hasFile('image_url')) {
            $file = $request->file('image_url');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $postImagePath = $file->storeAs('posts', $fileName, 'public');
        }

        $post = Post::create([
            'id' => (string) Str::uuid(),
            'author_id' => auth()->id(),
            'location' => $validated['location'] ?? null,
            'image_url' => $postImagePath, // Store path only, not full URL
            'caption' => $validated['caption'] ?? null,
            'created_at' => $validated['created_at'],
        ]);

        return response()->json([
            'message' => 'Post created successfully',
            'post' => $post,
            'image_url' => Storage::url($postImagePath)
        ], 201);

    }

    public function seelike($postId)
    {
        // Find post and eager load users who liked it
        $post = \App\Models\Post::with(['likes.user'])->findOrFail($postId);

        // Extract the users from likes
        $users = $post->likes->map(function ($like) {
            return [
                'id' => $like->user->id,
                'username' => $like->user->username,
                'liked_at' => $like->created_at,
            ];
        });

        return response()->json([
            'post_id' => $post->id,
            'likes_count' => $post->likes->count(),
            'liked_by' => $users
        ]);
    }

    public function likepost(Request $request, $postId)
    {
        $userId = auth()->id();

        // ✅ Make sure the post exists
        $post = Post::findOrFail($postId);

        // ✅ Prevent duplicate likes
        $alreadyLiked = PostLike::where('post_id', $postId)
            ->where('user_id', $userId)
            ->exists();

        if ($alreadyLiked) {
            return response()->json(['message' => 'Already liked'], 409);
        }

        // ✅ Create like
        $like = PostLike::create([
            'post_id' => $postId,
            'user_id' => $userId,
            'is_liked' => true,
            'created_at' => now(),
        ]);

        // ✅ Notify post owner (but not if the liker is the same user)
        if ($post->author_id != $userId) {
            $notification = Notification::create([
                'type' => 'post_liked',
                'data' => [
                    'message' => auth()->user()->username . ' liked your post.',
                    'post_id' => $post->id,
                    'user_id' => $userId,
                ],
                'created_by' => $userId,
            ]);

            UserNotification::create([
                'notification_id' => $notification->id,
                'user_id' => $post->author_id, // ✅ FIX: use author_id, not user_id/created_by
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);
        }

        return response()->json([
            'message' => 'Post liked',
            'like' => $like,
        ], 201);
    }

    public function unlikepost(Request $request, $postId)
    {
        $userId = auth()->id();

        // Make sure the post exists
        $post = Post::findOrFail($postId);

        // Find the like record
        $like = PostLike::where('post_id', $postId)
            ->where('user_id', $userId)
            ->where('is_liked', true)
            ->first();

        if (!$like) {
            return response()->json(['message' => 'You have not liked this post'], 404);
        }

        // Delete the like record
        PostLike::where('post_id', $postId)
            ->where('user_id', $userId)
            ->delete();

        // Delete related notification
        $notification = Notification::where('type', 'post_liked')
            ->where('data->post_id', $postId)
            ->where('data->user_id', $userId)
            ->first();

        if ($notification) {
            UserNotification::where('notification_id', $notification->id)->delete();
            $notification->delete();
        }

        return response()->json([
            'message' => 'Post unliked and notification deleted',
        ], 200);
    }


    public function commentpost(Request $request, $postId)
    {
        $validated = $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $userId = auth()->id();
        $post = Post::findOrFail($postId);

        $comment = \App\Models\PostComment::create([
            'id' => (string) Str::uuid(),
            'post_id' => $postId,
            'author_id' => $userId,
            'body' => $validated['body'],
            'created_at' => now(),
        ]);

        // ✅ Notify post owner (but not if they commented on their own post)
        if ($post->author_id != $userId) {
            $notification = Notification::create([
                'type' => 'post_commented',
                'data' => [
                    'message' => auth()->user()->username . ' commented on your post.',
                    'post_id' => $post->id,
                    'user_id' => $userId,
                ],
                'created_by' => $userId,
            ]);

            UserNotification::create([
                'notification_id' => $notification->id,
                'user_id' => $post->author_id, // ✅ owner of the post
                'pinned' => false,
                'is_read' => false,
                'action_state' => 'none',
            ]);
        }

        return response()->json([
            'message' => 'Comment added',
            'comment' => $comment,
        ], 201);
    }

    public function seecomments($postId)
    {
        // Find post and eager load users who liked it
        $post = \App\Models\Post::with(['comments.author'])->findOrFail($postId);

        // Sort comments by created_at ascending
        $comments = $post->comments->sortByDesc('created_at')->map(function ($comment) {
            return [
                'id' => $comment->id,
                'author_id' => $comment->author->id,
                'username' => $comment->author->username,
                'body' => $comment->body,
                'commented_at' => $comment->created_at,
            ];
        })->values();

        return response()->json([
            'post_id' => $post->id,
            'comments_count' => $post->comments->count(),
            'comments_by' => $comments
        ]);
    }

    public function deletepost($postId)
    {
        $userId = auth()->id();
        $post = Post::findOrFail($postId);

        // Only the post owner can archive
        if ($post->author_id !== $userId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Archive the post
        $post->is_archived = true;
        $post->save();

        return response()->json([
            'message' => 'Post archived successfully'
        ], 200);
    }


    public function archivedPosts()
    {
        $userId = auth()->id();

        // Get archived posts for the user
        $posts = Post::where('author_id', $userId)
            ->where('is_archived', true)
            ->get();

        // Delete posts archived more than 15 days ago
        $posts->each(function ($post) {
            // Use updated_at if you don't have archived_at
            $archivedDate = $post->updated_at;
            if ($archivedDate && $archivedDate->lt(now()->subDays(15))) {
                $post->delete();
            }
        });

        // Get posts again after deletion
        $posts = Post::where('author_id', $userId)
            ->where('is_archived', true)
            ->get();

        return response()->json([
            'status' => 'success',
            'archived_posts' => $posts
        ]);
    }

    public function restorePost($postId)
    {
        $userId = auth()->id();
        $post = Post::findOrFail($postId);

        // Only the post owner can restore
        if ($post->author_id !== $userId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Restore the post
        $post->is_archived = false;
        $post->save();

        return response()->json([
            'message' => 'Post restored successfully'
        ], 200);
    }


}
