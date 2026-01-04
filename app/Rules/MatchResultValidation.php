<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use App\Models\TeamMatchup;
use App\Models\Event;

class MatchResultValidation implements Rule
{
    protected $match;
    protected $tournamentType;
    protected $message = '';

    public function __construct($match, $tournamentType = 'team vs team')
    {
        $this->match = $match;
        $this->tournamentType = $tournamentType;
    }

    /**
     * Determine if the validation rule passes.
     */
    public function passes($attribute, $value)
    {
        // This rule is used for validating the entire request, not a single attribute
        return true;
    }

    /**
     * Validate match result data
     */
    public static function validate($match, $data, $tournamentType = 'team vs team')
    {
        $errors = [];

        if ($tournamentType === 'team vs team') {
            // Validate both teams present (unless bye/forfeit)
            if ($match->status !== 'bye' && $match->status !== 'forfeited') {
                if (!$match->team_a_id || !$match->team_b_id) {
                    $errors[] = 'Both teams must be present for the match';
                }
            }

            // Validate scores are non-negative integers
            if (isset($data['score_home']) && ($data['score_home'] < 0 || !is_int($data['score_home']))) {
                $errors[] = 'Home score must be a non-negative integer';
            }
            if (isset($data['score_away']) && ($data['score_away'] < 0 || !is_int($data['score_away']))) {
                $errors[] = 'Away score must be a non-negative integer';
            }

            // Validate winner matches score
            if (isset($data['score_home']) && isset($data['score_away']) && isset($data['winner_team_id'])) {
                $homeWins = $data['score_home'] > $data['score_away'];
                $awayWins = $data['score_away'] > $data['score_home'];
                $isDraw = $data['score_home'] === $data['score_away'];

                if ($isDraw && $data['winner_team_id'] !== null) {
                    $errors[] = 'Cannot set winner for a draw match';
                } elseif ($homeWins && $data['winner_team_id'] !== $match->team_a_id) {
                    $errors[] = 'Winner must match the team with higher score';
                } elseif ($awayWins && $data['winner_team_id'] !== $match->team_b_id) {
                    $errors[] = 'Winner must match the team with higher score';
                }
            }

            // Validate match status allows score update
            if ($match->status === 'completed' && !isset($data['force_update'])) {
                $errors[] = 'Cannot update scores for completed match without force_update flag';
            }
        } else {
            // Free for all validation
            if (isset($data['scores']) && is_array($data['scores'])) {
                foreach ($data['scores'] as $score) {
                    if (!isset($score['user_id']) || !isset($score['score'])) {
                        $errors[] = 'Each score entry must have user_id and score';
                    }
                    if (isset($score['score']) && ($score['score'] < 0 || !is_int($score['score']))) {
                        $errors[] = 'Score must be a non-negative integer';
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Get the validation error message.
     */
    public function message()
    {
        return $this->message ?: 'Match result validation failed';
    }
}














