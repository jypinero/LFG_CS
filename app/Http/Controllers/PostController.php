<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Post;
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

        // Get posts from those users with like and comment counts
        $posts = Post::whereIn('author_id', $userIds)
            ->withCount(['likes', 'comments']) // adds likes_count, comments_count
            ->get();

        return response()->json($posts);
    }
    
    public function createpost(Request $request)
    {

        $validated = $request->validate([
            'location' => 'nullable|string',
            'image_url' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // Required image
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

        // Prevent duplicate likes
        $alreadyLiked = \App\Models\PostLike::where('post_id', $postId)
            ->where('user_id', $userId)
            ->exists();

        if ($alreadyLiked) {
            return response()->json(['message' => 'Already liked'], 409);
        }

        $like = \App\Models\PostLike::create([
            'post_id' => $postId,
            'user_id' => $userId,
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Post liked', 'like' => $like], 201);
    }

    public function commentpost(Request $request, $postId)
    {
        $validated = $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $comment = \App\Models\PostComment::create([
            'id' => (string) Str::uuid(),
            'post_id' => $postId,
            'author_id' => auth()->id(),
            'body' => $validated['body'],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Comment added', 'comment' => $comment], 201);
    }

    public function seecomments($postId)
    {
        // Find post and eager load users who liked it
        $post = \App\Models\Post::with(['comments.author'])->findOrFail($postId);

        // Extract the users from likes
        $comments = $post->comments->map(function ($comment) {
            return [
                'id' => $comment->id,
                'author_id' => $comment->author->id,
                'username' => $comment->author->username,
                'body' => $comment->body,
                'commented_at' => $comment->created_at,
            ];
        });

        return response()->json([
            'post_id' => $post->id,
            'comments_count' => $post->comments->count(),
            'comments_by' => $comments
        ]);
    }
}
