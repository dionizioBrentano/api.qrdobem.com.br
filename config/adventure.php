<?php

return [
    'position_max_age_minutes' => env('ADVENTURE_POSITION_MAX_AGE_MINUTES', 15),
    'idle_minutes' => env('ADVENTURE_IDLE_MINUTES', 30),
    'idle_tolerance_meters' => env('ADVENTURE_IDLE_TOLERANCE_METERS', 40),
    'escalate_minutes' => env('ADVENTURE_ESCALATE_MINUTES', 10),
    'companion_max_meters' => env('ADVENTURE_COMPANION_MAX_METERS', 300),
    'companion_fresh_minutes' => env('ADVENTURE_COMPANION_FRESH_MINUTES', 5),
    'default_radius_meters' => env('ADVENTURE_DEFAULT_RADIUS_METERS', 50),
    'min_radius_meters' => env('ADVENTURE_MIN_RADIUS_METERS', 10),
    'default_tolerance_minutes' => env('ADVENTURE_DEFAULT_TOLERANCE_MINUTES', 15),
    'max_tolerance_minutes' => env('ADVENTURE_MAX_TOLERANCE_MINUTES', 240),
];
