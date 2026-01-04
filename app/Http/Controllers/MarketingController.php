<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\PostComment;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\MarketingPost;
use App\Models\Event;
use App\Models\Booking;
use App\Models\Venue;
use App\Models\VenueUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class MarketingController extends Controller
{
    public function __construct()
    {
        // require authenticated user for index/createpost (adjust middleware driver as used in your app)
        $this->middleware('auth')->only(['index','createpost']);
    }

    private const RATE_LIMIT_COUNT = 5;         // posts per minute
    private const DUPLICATE_WINDOW_MIN = 5;    // minutes
    private const MAX_FUTURE_YEARS = 1;        // how far in future event date allowed
    private const MAX_IMAGE_DIM = 5000;        // px
    private const MIN_IMAGE_DIM = 50;          // px
    private const MAX_SLOTS_DEFAULT = 500;     // fallback max slots

    private function generateCheckinCode()
    {
        do {
            $code = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        } while (Event::where('checkin_code', $code)->exists());

        return $code;
    }

    public function createpost(Request $request)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['status'=>'error','message'=>'Unauthenticated'], 401);
        }
        $userId = $user->id;

        // quick rate limit
        $recentCount = MarketingPost::where('author_id', $userId)
            ->where('created_at', '>=', now()->subMinute())
            ->count();
        if ($recentCount >= self::RATE_LIMIT_COUNT) {
            return response()->json(['status'=>'error','message'=>'Rate limit: too many posts, try later'], 429);
        }

        $validated = $request->validate([
            'venue_id' => 'required_if:create_event,1|integer|exists:venues,id',
            'location' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            'caption' => 'nullable|string|min:3|max:2000',
            'created_at' => 'nullable|date',
            'create_event' => 'nullable|boolean',

            // Event creation fields (required when create_event = 1)
            'event.slots' => 'required_if:create_event,1|integer|min:1',
            'event.facility_id' => 'required_if:create_event,1|integer|exists:facilities,id',
            'event.date' => 'required_if:create_event,1|date',
            'event.start_time' => 'required_if:create_event,1|date_format:H:i:s',
            'event.end_time' => 'required_if:create_event,1|date_format:H:i:s|after:event.start_time',
            'event.sport' => 'required_if:create_event,1|exists:sports,name',
            'event.purpose' => 'nullable|string|max:255',

            // Optional team vs team support if marketing creates team events
            'event.event_type' => 'nullable|in:free for all,team vs team,tournament,multisport',
            'event.team_ids' => 'required_if:event.event_type,team vs team|array|min:2',
            'event.team_ids.*' => 'integer|exists:teams,id',
        ]);

        // created_at sanity: not too far in future
        if (! empty($validated['created_at'])) {
            $createdAt = Carbon::parse($validated['created_at']);
            if ($createdAt->gt(now()->addMinutes(5))) {
                return response()->json(['status'=>'error','message'=>'created_at cannot be in the future'], 422);
            }
        }

        // require at least one content: image OR caption OR location
        if (! $request->hasFile('image') && empty($validated['caption']) && empty($validated['location'])) {
            return response()->json(['status' => 'error', 'message' => 'Please provide an image, caption, or location.'], 422);
        }

        // image checks (dimensions + mime already validated)
        $imageHash = null;
        $postImagePath = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');

            // dimension checks
            $size = @getimagesize($file->getPathname());
            if (! $size || ! isset($size[0], $size[1])) {
                return response()->json(['status'=>'error','message'=>'Invalid image file'], 422);
            }
            [$width, $height] = [$size[0], $size[1]];
            if ($width < self::MIN_IMAGE_DIM || $height < self::MIN_IMAGE_DIM) {
                return response()->json(['status'=>'error','message'=>'Image dimensions too small'], 422);
            }
            if ($width > self::MAX_IMAGE_DIM || $height > self::MAX_IMAGE_DIM) {
                return response()->json(['status'=>'error','message'=>'Image dimensions too large'], 422);
            }

            // compute hash to help detect duplicates
            $contents = file_get_contents($file->getPathname());
            $imageHash = md5($contents);

            $fileName = time() . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();
            $postImagePath = $file->storeAs('posts', $fileName, 'public');
        }

        // duplicate check (caption + venue + recent window)
        if (! empty($validated['caption'])) {
            $dupQuery = MarketingPost::where('author_id', $userId)
                ->where('caption', trim($validated['caption']))
                ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MIN));
            if (! empty($validated['venue_id'])) $dupQuery->where('venue_id', $validated['venue_id']);
            if ($postImagePath) $dupQuery->orWhere(function($q) use ($imageHash, $userId) {
                $q->where('author_id', $userId)->where('image_url', 'like', '%posts%'); // best-effort if stored path
            });
            if ($dupQuery->exists()) {
                return response()->json(['status'=>'error','message'=>'Duplicate post detected'], 409);
            }
        }

        $createEvent = !empty($validated['create_event']) && $validated['create_event'];
        // if creating event perform additional validations
        if ($createEvent) {
            $bookingVenueId = $validated['venue_id'] ?? null;
            $facilityId = data_get($validated, 'event.facility_id');
            $eventDate = data_get($validated, 'event.date');
            $startTime = data_get($validated, 'event.start_time');
            $endTime = data_get($validated, 'event.end_time');
            $slots = data_get($validated, 'event.slots');

            // venue basic existence & status
            $venue = Venue::find($bookingVenueId);
            if (! $venue) {
                return response()->json(['status'=>'error','message'=>'Venue not found'], 422);
            }
            if (isset($venue->is_closed) && $venue->is_closed) {
                return response()->json(['status'=>'error','message'=>'Venue is closed/unavailable'], 409);
            }
            if (isset($venue->status) && strtolower($venue->status) === 'closed') {
                return response()->json(['status'=>'error','message'=>'Venue is closed/unavailable'], 409);
            }

            // facility belongs to venue
            $facilityBelongs = DB::table('facilities')
                ->where('id', $facilityId)
                ->where('venue_id', $bookingVenueId)
                ->exists();
            if (! $facilityBelongs) {
                return response()->json(['status'=>'error','message'=>'Selected facility does not belong to the venue'], 422);
            }

            // slots capacity check (if facility has capacity)
            $facilityCapacity = DB::table('facilities')->where('id', $facilityId)->value('capacity') ?? null;
            $maxSlots = $facilityCapacity ?? self::MAX_SLOTS_DEFAULT;
            if ($slots && $slots > $maxSlots) {
                return response()->json(['status'=>'error','message'=>"Slots exceed facility capacity ({$maxSlots})"], 422);
            }

            // team-vs-team: ensure team ids provided, unique and at least 2
            $eventType = data_get($validated, 'event.event_type');
            if ($eventType === 'team vs team') {
                $teamIds = data_get($validated, 'event.team_ids', []);
                if (! is_array($teamIds) || count(array_unique($teamIds)) < 2) {
                    return response()->json(['status'=>'error','message'=>'team vs team requires at least two distinct team_ids'], 422);
                }
            }

            // combine date+time checks (no past, not too far future)
            try {
                $startDt = Carbon::createFromFormat('Y-m-d H:i:s', $eventDate . ' ' . $startTime);
                $endDt = Carbon::createFromFormat('Y-m-d H:i:s', $eventDate . ' ' . $endTime);
            } catch (\Exception $e) {
                return response()->json(['status'=>'error','message'=>'Invalid date/time combination'], 422);
            }
            if ($startDt->lt(now())) {
                return response()->json(['status'=>'error','message'=>'Event start cannot be in the past'], 422);
            }
            if ($startDt->gt(now()->addYears(self::MAX_FUTURE_YEARS))) {
                return response()->json(['status'=>'error','message'=>'Event date too far in the future'], 422);
            }

            // operating hours (if venue declares open_time/close_time)
            if (isset($venue->open_time) && isset($venue->close_time)) {
                // expected H:i:s
                try {
                    $open = Carbon::createFromFormat('H:i:s', $venue->open_time);
                    $close = Carbon::createFromFormat('H:i:s', $venue->close_time);
                    $st = Carbon::createFromFormat('H:i:s', $startTime);
                    $et = Carbon::createFromFormat('H:i:s', $endTime);
                    if ($st->lt($open) || $et->gt($close)) {
                        return response()->json(['status'=>'error','message'=>'Event time outside venue operating hours'], 422);
                    }
                } catch (\Exception $e) {
                    // ignore if venue times malformed
                }
            }

            // Conflict checks: Event table (non-cancelled) and Bookings (non-cancelled)
            $overlapQuery = function($q) use ($startTime, $endTime) {
                $q->whereBetween('start_time', [$startTime, $endTime])
                  ->orWhereBetween('end_time', [$startTime, $endTime])
                  ->orWhere(function($r) use ($startTime, $endTime) {
                      $r->where('start_time', '<=', $startTime)->where('end_time', '>=', $endTime);
                  });
            };

            $conflictAny = Event::where('venue_id', $bookingVenueId)
                ->where('facility_id', $facilityId)
                ->whereDate('date', $eventDate)
                ->whereNull('cancelled_at')
                ->where($overlapQuery)
                ->exists();

            if ($conflictAny) {
                return response()->json(['status'=>'error','message'=>'Venue/facility already has an event/booking at the selected date/time'], 409);
            }

            $bookingConflict = Booking::where('venue_id', $bookingVenueId)
                ->where('event_id', '!=', null)
                ->whereDate('date', $eventDate)
                ->where(function($q) use ($startTime, $endTime) {
                    $q->whereBetween('start_time', [$startTime, $endTime])
                      ->orWhereBetween('end_time', [$startTime, $endTime])
                      ->orWhere(function($r) use ($startTime, $endTime) {
                          $r->where('start_time', '<=', $startTime)->where('end_time', '>=', $endTime);
                      });
                })
                ->where('status', '!=', 'cancelled')
                ->exists();

            if ($bookingConflict) {
                return response()->json(['status'=>'error','message'=>'A booking overlaps this time slot'], 409);
            }

            // venue user role validation (case-insensitive)
            $vu = VenueUser::where('venue_id', $bookingVenueId)->where('user_id', $userId)->first();
            $isVenueStaff = false;
            if ($vu) {
                $roleLower = strtolower($vu->role ?? '');
                $isVenueStaff = in_array($roleLower, ['owner','staff','manager'], true);
            } else {
                $isVenueStaff = false;
            }

            // if auto-approving, ensure no overlapping approved event exists
            if ($isVenueStaff) {
                $conflictApproved = Event::where('venue_id', $bookingVenueId)
                    ->where('facility_id', $facilityId)
                    ->whereDate('date', $eventDate)
                    ->whereNull('cancelled_at')
                    ->where('is_approved', true)
                    ->where($overlapQuery)
                    ->exists();
                if ($conflictApproved) {
                    return response()->json(['status'=>'error','message'=>'Cannot auto-approve: overlapping approved event exists for this facility/time'], 409);
                }
            }
        } else {
            // Not creating an event: ensure required content exists (already checked)
            $bookingVenueId = $validated['venue_id'] ?? null;
            $isVenueStaff = false;
        }

        // now perform DB operations inside a transaction (post + optional event/booking + marketing)
        try {
            DB::beginTransaction();

            $venuename = $bookingVenueId ? Venue::where('id', $bookingVenueId)->value('name') : ($validated['location'] ?? null);

            // Create post
            $post = Post::create([
                'id' => (string) Str::uuid(),
                'author_id' => $userId,
                'venue_id' => $bookingVenueId ?? null,
                'location' => $venuename ?? null,
                'image_url' => $postImagePath,
                'caption' => $validated['caption'] ?? null,
                'is_venue_post' => !empty($bookingVenueId),
                'posted_by_role' => $vu->role ?? null,
                'created_at' => $validated['created_at'] ?? now(),
            ]);

            $createdBooking = null;
            $createdEvent = null;

            if ($createEvent) {
                $bookingStatus = $isVenueStaff ? 'approved' : 'pending';

                $createdEvent = Event::create([
                    'venue_id' => $bookingVenueId,
                    'facility_id' => data_get($validated, 'event.facility_id'),
                    'name' => data_get($validated, 'event.sport') ?? ('Event by ' . ($user->username ?? $user->id)),
                    'date' => data_get($validated, 'event.date'),
                    'event_type' => data_get($validated, 'event.event_type', 'free for all'),
                    'slots' => data_get($validated, 'event.slots'),
                    'sport' => data_get($validated, 'event.sport'),
                    'start_time' => data_get($validated, 'event.start_time'),
                    'end_time' => data_get($validated, 'event.end_time'),
                    'checkin_code' => $this->generateCheckinCode(),
                    'is_approved' => $bookingStatus === 'approved',
                    'approved_at' => $bookingStatus === 'approved' ? now() : null,
                    'approved_by' => $bookingStatus === 'approved' ? $userId : null,
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);

                $createdBooking = Booking::create([
                    'venue_id' => $bookingVenueId,
                    'event_id' => $createdEvent->id,
                    'user_id' => $userId,
                    'date' => data_get($validated, 'event.date'),
                    'start_time' => data_get($validated, 'event.start_time'),
                    'end_time' => data_get($validated, 'event.end_time'),
                    'status' => $bookingStatus,
                    'sport' => data_get($validated, 'event.sport'),
                    'purpose' => data_get($validated, 'event.purpose'),
                    'created_at' => now(),
                ]);

                if (empty($createdBooking->event_id)) {
                    $createdBooking->update(['event_id' => $createdEvent->id]);
                }
            }

            // insert into marketing_posts (works even when no event)
            $marketing = MarketingPost::create([
                'id' => (string) Str::uuid(),
                'post_id' => $post->id,
                'event_id' => $createdEvent->id ?? null,
                'booking_id' => $createdBooking->id ?? null,
                'author_id' => $userId,
                'venue_id' => $bookingVenueId ?? null,
                'image_url' => $postImagePath ?? null,
                'caption' => $post->caption ?? null,
                'create_event' => $createEvent,
                'created_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            // if file was stored, optionally delete to avoid orphaned uploads
            if ($postImagePath && Storage::disk('public')->exists($postImagePath)) {
                Storage::disk('public')->delete($postImagePath);
            }
            return response()->json(['status'=>'error','message'=>'Failed to create post','error'=>$e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Post created successfully',
            'profile_photo_url' => $user->profile_photo ? Storage::url($user->profile_photo) : null,
            'post' => $post,
            'image_url' => $postImagePath ? Storage::url($postImagePath) : null,
            'booking' => $createdBooking ? $createdBooking->fresh() : null,
            'event' => $createdEvent ? $createdEvent->fresh() : null,
            'marketing' => $marketing ? $marketing->fresh() : null,
        ], 201);
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['status'=>'error','message'=>'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'venue_id' => 'nullable|integer|exists:venues,id',
            'author_id' => 'nullable|integer|exists:users,id',
            'type' => 'nullable|in:post,event,booking',
            'create_event' => 'nullable|boolean',
        ]);

        // only return posts the current user is allowed to see:
        // - global/public posts (venue_id IS NULL)
        // - posts authored by the user
        // - posts for venues the user is a member of
        $userVenueIds = VenueUser::where('user_id', $user->id)->pluck('venue_id')->toArray();

        // include profile_photo so we can expose a full URL in the response
        $query = MarketingPost::with(['post','event','booking','author:id,username,profile_photo','venue:id,name'])
            ->where(function($q) use ($user, $userVenueIds) {
                $q->whereNull('venue_id')
                  ->orWhere('author_id', $user->id)
                  ->orWhereIn('venue_id', $userVenueIds);
            })
            ->orderByDesc('created_at');

        if (! empty($validated['venue_id'])) {
            $query->where('venue_id', $validated['venue_id']);
        }

        if (! empty($validated['author_id'])) {
            $query->where('author_id', $validated['author_id']);
        }

        if (isset($validated['create_event'])) {
            $query->where('create_event', $validated['create_event']);
        }

        if (! empty($validated['type'])) {
            if ($validated['type'] === 'post') {
                $query->whereNotNull('post_id');
            } elseif ($validated['type'] === 'event') {
                $query->whereNotNull('event_id');
            } elseif ($validated['type'] === 'booking') {
                $query->whereNotNull('booking_id');
            }
        }

        $results = $query->get()->map(function ($item) {
            if ($item->author) {
                $item->author->profile_photo_url = $item->author->profile_photo
                    ? Storage::url($item->author->profile_photo)
                    : null;
            }
            return $item;
        });

        return response()->json($results, 200);
    }
}
