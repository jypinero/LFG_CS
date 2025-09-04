<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMessagingTables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('is_group')->default(false);
            $table->string('title')->nullable();
            $table->timestamps(); // created_at, updated_at
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('thread_id')->constrained('message_threads');
            $table->foreignId('sender_id')->constrained('users'); // FIXED
            $table->text('body');
            $table->timestamp('sent_at');
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('thread_participants', function (Blueprint $table) {
            $table->foreignUuid('thread_id')->constrained('message_threads');
            $table->foreignId('user_id')->constrained('users'); // FIXED
            $table->enum('role', ['owner', 'admin', 'member'])->default('member');
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->foreignUuid('last_read_message_id')->nullable()->constrained('messages');
            $table->timestamp('mute_until')->nullable();
            $table->boolean('notifications')->default(true);
            $table->primary(['thread_id', 'user_id']);
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('author_id')->constrained('users'); // FIXED
            $table->string('location')->nullable();
            $table->string('image_url')->nullable();
            $table->text('caption');
            $table->timestamp('created_at')->useCurrent(); // FIXED
        });

        Schema::create('post_likes', function (Blueprint $table) {
            $table->foreignUuid('post_id')->constrained('posts');
            $table->foreignId('user_id')->constrained('users'); // FIXED
            $table->timestamp('created_at')->useCurrent(); // FIXED
            $table->primary(['post_id', 'user_id']);
        });

        Schema::create('post_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('posts');
            $table->foreignId('author_id')->constrained('users'); // FIXED
            $table->text('body');
            $table->timestamp('created_at')->useCurrent(); // FIXED
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->json('data');
            $table->foreignId('created_by')->nullable()->constrained('users'); // FIXED
            $table->timestamp('created_at')->useCurrent(); // FIXED
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained('notifications');
            $table->foreignId('user_id')->constrained('users'); // FIXED
            $table->timestamp('read_at')->nullable();
            $table->boolean('pinned')->default(false);
            $table->enum('action_state', ['none', 'pending', 'accepted', 'ignored', 'completed'])->default('none');
            $table->timestamp('action_taken_at')->nullable();
            $table->timestamp('created_at')->useCurrent(); // FIXED
        });

        Schema::create('user_notification_action_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_notification_id')->constrained('user_notifications');
            $table->string('action_key');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
    }

    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_notification_action_events');
        Schema::dropIfExists('notification_actions');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('post_likes');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('thread_participants');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
    }
};
