<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Venue;
use App\Models\Tournament;
use App\Models\Event;
use App\Models\EventGame;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\PostComment;
use App\Models\Sport;
use App\Models\Role;

class MockDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Date range: July 1, 2025 to January 31, 2026
        $startDateRange = Carbon::create(2025, 7, 1); // July 1, 2025
        $endDateRange = Carbon::create(2026, 1, 31); // January 31, 2026

        // Get existing venues (DO NOT CREATE - only use existing)
        $venues = Venue::all();
        
        if ($venues->isEmpty()) {
            $this->command->error('No venues found in database. Please create venues first.');
            return;
        }

        $this->command->info("Using {$venues->count()} existing venue(s) from database.");

        // Get or create athlete role
        $athleteRole = Role::firstOrCreate(['name' => 'athletes']);

        // Get sports
        $basketball = Sport::where('name', 'Basketball')->first();
        $volleyball = Sport::where('name', 'Volleyball')->first();
        $tennis = Sport::where('name', 'Tennis')->first();
        $badminton = Sport::where('name', 'Badminton')->first();

        $sports = collect([$basketball, $volleyball, $tennis, $badminton])->filter();

        if ($sports->isEmpty()) {
            $this->command->error('No sports found. Please run SportSeeder first.');
            return;
        }

        // Get admin user for created_by fields
        $adminUser = User::first();

        if (!$adminUser) {
            $this->command->error('No admin user found. Please run AdminUserSeeder first.');
            return;
        }

        // ==========================================
        // 1. CREATE USERS WITH PROFILES
        // ==========================================
        $this->command->info('Creating users with profiles...');
        $createdUsers = collect();

        // Create 15-20 users
        $numUsers = rand(15, 20);

        for ($i = 0; $i < $numUsers; $i++) {
            $firstName = fake()->firstName();
            $lastName = fake()->lastName();
            $username = Str::slug(strtolower($firstName . '.' . $lastName . '.' . $i));
            $email = $username . '@example.com';

            // Check if user already exists
            $user = User::where('email', $email)->first();

            if (!$user) {
                $sport = $sports->random();
                $sex = fake()->randomElement(['male', 'female', 'other']);
                
                $user = User::create([
                    'first_name' => $firstName,
                    'middle_name' => fake()->optional()->firstName(),
                    'last_name' => $lastName,
                    'username' => $username,
                    'email' => $email,
                    'password' => Hash::make('password123'),
                    'birthday' => fake()->date('Y-m-d', '-18 years'),
                    'sex' => $sex,
                    'contact_number' => '09' . fake()->numerify('#########'),
                    'barangay' => fake()->streetName(),
                    'city' => 'Olongapo City',
                    'province' => 'Zambales',
                    'zip_code' => '2200',
                    'role_id' => $athleteRole->id,
                    'profile_photo' => null,
                ]);

                // Create user profile
                UserProfile::create([
                    'user_id' => $user->id,
                    'main_sport_id' => $sport->id,
                    'main_sport_level' => fake()->randomElement(['beginner', 'intermediate', 'competitive', 'professional']),
                    'bio' => fake()->optional(0.7)->sentence(rand(10, 20)),
                    'occupation' => fake()->optional(0.6)->jobTitle(),
                    'is_certified_pro' => fake()->boolean(20),
                ]);
            }

            $createdUsers->push($user);
        }

        $this->command->info("Created/Found {$createdUsers->count()} users with profiles.");

        // ==========================================
        // 2. CREATE TOURNAMENTS
        // ==========================================
        $this->command->info('Creating tournaments...');
        $tournaments = collect();

        $tournamentNames = [
            'Summer Basketball Championship 2025',
            'Volleyball League Tournament',
            'Tennis Open Championship',
            'Badminton Singles Tournament',
            'Multi-Sport Championship',
            'Basketball Summer League',
            'Volleyball Beach Tournament',
            'Tennis Doubles Championship',
        ];

        foreach ($tournamentNames as $tournamentName) {
            // Random date between July 1, 2025 and January 31, 2026
            $daysFromStart = rand(0, $startDateRange->diffInDays($endDateRange));
            $startDate = $startDateRange->copy()->addDays($daysFromStart);
            $endDate = $startDate->copy()->addDays(rand(3, 14)); // Tournament lasts 3-14 days
            
            // Ensure end date doesn't exceed January 31, 2026
            if ($endDate->gt($endDateRange)) {
                $endDate = $endDateRange->copy();
            }
            
            $tournament = Tournament::create([
                'name' => $tournamentName,
                'description' => fake()->sentence(rand(10, 20)),
                'location' => $venues->random()->address,
                'type' => fake()->randomElement(['single_sport', 'multisport']),
                'tournament_type' => fake()->randomElement(['team vs team', 'free for all']), // Fixed: use correct enum values
                'created_by' => $adminUser->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'registration_deadline' => $startDate->copy()->subDays(rand(1, 7)),
                'status' => fake()->randomElement(['open_registration', 'registration_closed', 'ongoing', 'completed']),
                'requires_documents' => fake()->boolean(30),
                'required_documents' => fake()->boolean(30) ? ['id', 'medical_certificate'] : null,
                'max_teams' => rand(8, 32),
                'min_teams' => 4,
                'registration_fee' => fake()->randomFloat(2, 0, 500),
                'rules' => fake()->paragraph(rand(3, 5)),
                'prizes' => fake()->paragraph(rand(2, 4)),
            ]);

            $tournaments->push($tournament);
        }

        // ==========================================
        // 3. CREATE EVENTS (Using existing venues)
        // ==========================================
        $this->command->info('Creating events using existing venues...');
        $events = collect();

        foreach ($tournaments as $tournament) {
            $venue = $venues->random(); // Use existing venue
            $sport = $sports->random();
            
            // Create 2-4 events per tournament
            $eventsPerTournament = rand(2, 4);

            for ($i = 0; $i < $eventsPerTournament; $i++) {
                // Event date within tournament date range
                $eventDate = $tournament->start_date->copy()->addDays($i);
                
                // Ensure event date is within July 1, 2025 - January 31, 2026
                if ($eventDate->lt($startDateRange)) {
                    $eventDate = $startDateRange->copy();
                }
                if ($eventDate->gt($endDateRange)) {
                    $eventDate = $endDateRange->copy();
                }
                
                $event = Event::create([
                    'name' => $tournament->name . ' - Game ' . ($i + 1),
                    'description' => 'Tournament game event for ' . $sport->name,
                    'event_type' => 'tournament',
                    'sport' => $sport->name,
                    'venue_id' => $venue->id, // Using existing venue
                    'slots' => rand(16, 32),
                    'date' => $eventDate,
                    'start_time' => fake()->time('H:i', '09:00'),
                    'end_time' => fake()->time('H:i', '18:00'),
                    'created_by' => $adminUser->id,
                    'tournament_id' => $tournament->id,
                    'game_number' => $i + 1,
                    'game_status' => fake()->randomElement(['scheduled', 'in_progress', 'completed']),
                    'is_tournament_game' => true,
                    'is_approved' => true,
                    'approved_at' => Carbon::now(),
                ]);

                $events->push($event);
            }
        }

        $this->command->info("Created {$events->count()} events using existing venues.");

        // ==========================================
        // 4. CREATE EVENT GAMES
        // ==========================================
        $this->command->info('Creating event games...');
        $gameCount = 0;

        foreach ($events as $event) {
            $tournament = $event->tournament;
            $availableUsers = $createdUsers->random(min(8, $createdUsers->count()));

            // Create 4-8 games per event
            $gamesPerEvent = rand(4, 8);
            $roundNumber = 1;
            $matchNumber = 1;

            for ($i = 0; $i < $gamesPerEvent; $i++) {
                if ($availableUsers->count() < 2) {
                    break;
                }

                $userA = $availableUsers->random();
                $userB = $availableUsers->reject(function ($user) use ($userA) {
                    return $user->id === $userA->id;
                })->random();

                // Game date is the event date (already within July 1, 2025 - January 31, 2026)
                $gameDate = $event->date;
                
                // Ensure game date is within range
                if ($gameDate->lt($startDateRange)) {
                    $gameDate = $startDateRange->copy();
                }
                if ($gameDate->gt($endDateRange)) {
                    $gameDate = $endDateRange->copy();
                }
                
                $status = fake()->randomElement(['scheduled', 'ongoing', 'completed']);
                
                $scoreA = null;
                $scoreB = null;
                $winnerUserId = null;

                if ($status === 'completed') {
                    $scoreA = rand(0, 25);
                    $scoreB = rand(0, 25);
                    // Ensure different scores for winner
                    if ($scoreA === $scoreB) {
                        $scoreA = max($scoreA, 1);
                        $scoreB = $scoreA - 1;
                    }
                    $winnerUserId = $scoreA > $scoreB ? $userA->id : $userB->id;
                }

                EventGame::create([
                    'event_id' => $event->id,
                    'tournament_id' => $tournament->id,
                    'round_number' => $roundNumber,
                    'match_number' => $matchNumber,
                    'match_stage' => fake()->randomElement(['winners', 'losers', 'grand_final']),
                    'team_a_id' => null,
                    'team_b_id' => null,
                    'user_a_id' => $userA->id,
                    'user_b_id' => $userB->id,
                    'score_a' => $scoreA,
                    'score_b' => $scoreB,
                    'winner_team_id' => null,
                    'winner_user_id' => $winnerUserId,
                    'game_date' => $gameDate,
                    'start_time' => fake()->time('H:i', '10:00'),
                    'end_time' => fake()->time('H:i', '12:00'),
                    'status' => $status,
                ]);

                $matchNumber++;
                if ($matchNumber > 4) {
                    $roundNumber++;
                    $matchNumber = 1;
                }

                $gameCount++;
            }
        }

        $this->command->info("Created {$gameCount} event games.");

        // ==========================================
        // 5. CREATE TEXT POSTS
        // ==========================================
        $this->command->info('Creating text posts...');
        $postCount = 0;
        $likeCount = 0;
        $commentCount = 0;

        $postCaptions = [
            'Just finished an amazing game at the court! 🏀',
            'Looking for players for this weekend\'s match. Anyone interested?',
            'Tournament was intense today! Great competition all around. 🎾',
            'Training hard for the upcoming championship. Let\'s go! 💪',
            'Had a great practice session today. Feeling stronger!',
            'Who\'s joining the volleyball league? Sign-ups are open! 🏐',
            'Badminton doubles tournament next month. Need a partner!',
            'The new basketball court is amazing. Highly recommend!',
            'Just watched an incredible match. These players are on fire! 🔥',
            'Weekend game results are in. Check them out!',
            'Looking forward to the championship finals next week!',
            'Practice makes perfect. Keep grinding everyone! 💯',
            'Amazing sports community here. Proud to be part of it!',
            'Big win today! Thanks to everyone who came out to support.',
            'Court bookings available for next week. Book early!',
            'Tournament brackets are up. Good luck to all participants!',
            'Training session was brutal but worth it. Progress! 🏋️',
            'New equipment arrived at the venue. So excited to try it out!',
            'Match highlights are live. Check out the action!',
            'Great turnout at today\'s event. Thanks everyone!',
            'Competition is heating up. Who\'s ready for the playoffs?',
            'Incredible sportsmanship shown today. This is what it\'s all about!',
            'Weekend warriors unite! Time to show what we\'ve got!',
            'The journey to victory starts with a single step. Let\'s go! 🏆',
            'Early morning practice paid off. Feeling ready for the tournament!',
        ];

        // Create posts for each user (2-5 posts per user)
        foreach ($createdUsers as $user) {
            $postsPerUser = rand(2, 5);

            for ($i = 0; $i < $postsPerUser; $i++) {
                // Random date within July 1, 2025 - January 31, 2026
                $daysFromStart = rand(0, $startDateRange->diffInDays($endDateRange));
                $postDate = $startDateRange->copy()->addDays($daysFromStart);
                
                // Random time during the day
                $postDate->setTime(rand(6, 23), rand(0, 59), 0);

                $caption = fake()->randomElement($postCaptions);
                
                // Optionally add location (some posts have location, some don't)
                $location = fake()->boolean(40) ? fake()->randomElement([
                    'Olongapo City Sports Complex',
                    'Brgy. Barretto Covered Court',
                    'Pag-Asa Covered Court',
                    'Sta. Rita Basketball Court',
                    'Olongapo City',
                    'Zambales',
                ]) : null;

                $post = Post::create([
                    'id' => (string) Str::uuid(),
                    'author_id' => $user->id,
                    'location' => $location,
                    'image_url' => null, // Text posts only, no images
                    'caption' => $caption,
                    'created_at' => $postDate,
                    'is_archived' => false,
                ]);

                $postCount++;

                // ==========================================
                // 6. CREATE POST LIKES
                // ==========================================
                $numLikes = rand(0, min(10, $createdUsers->count() - 1));
                if ($numLikes > 0 && $createdUsers->count() > 1) {
                    $likingUsers = $createdUsers->reject(function ($u) use ($user) {
                        return $u->id === $user->id;
                    })->random(min($numLikes, $createdUsers->count() - 1));

                    foreach ($likingUsers as $likingUser) {
                        PostLike::firstOrCreate(
                            [
                                'post_id' => $post->id,
                                'user_id' => $likingUser->id,
                            ],
                            [
                                'is_liked' => true,
                                'created_at' => $postDate->copy()->addMinutes(rand(5, 1440)), // Likes up to 24 hours after post
                            ]
                        );
                        $likeCount++;
                    }
                }

                // ==========================================
                // 7. CREATE POST COMMENTS
                // ==========================================
                $numComments = rand(0, 5);
                if ($numComments > 0 && $createdUsers->count() > 1) {
                    $commentingUsers = $createdUsers->reject(function ($u) use ($user) {
                        return $u->id === $user->id;
                    })->random(min($numComments, $createdUsers->count() - 1));

                    $commentTexts = [
                        'Great post! 👍',
                        'Totally agree with you!',
                        'See you there!',
                        'Count me in!',
                        'Amazing! Can\'t wait for this!',
                        'Good luck! 💪',
                        'Well said!',
                        'This is awesome!',
                        'Looking forward to it!',
                        'Nice one! 🔥',
                        'I\'ll be there!',
                        'Let\'s go!',
                        'Awesome!',
                        'Can\'t wait!',
                        'Sounds great!',
                        'Perfect timing!',
                        'Count me in for that!',
                    ];

                    foreach ($commentingUsers as $commentingUser) {
                        PostComment::create([
                            'id' => (string) Str::uuid(),
                            'post_id' => $post->id,
                            'author_id' => $commentingUser->id,
                            'body' => fake()->randomElement($commentTexts),
                            'created_at' => $postDate->copy()->addMinutes(rand(10, 2880)), // Comments up to 48 hours after post
                        ]);
                        $commentCount++;
                    }
                }
            }
        }

        $this->command->info("Created {$postCount} text posts.");
        $this->command->info("Created {$likeCount} post likes.");
        $this->command->info("Created {$commentCount} post comments.");
        $this->command->info('');
        $this->command->info('==========================================');
        $this->command->info('Mock data seeding completed successfully!');
        $this->command->info('All dates are within July 1, 2025 - January 31, 2026');
        $this->command->info('Events use existing venues from database');
        $this->command->info('==========================================');
    }
}