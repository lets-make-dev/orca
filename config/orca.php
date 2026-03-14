<?php

return [
    'name' => 'Orca',
    'enabled' => env('ORCA_ENABLED', true),
    'timeout' => env('ORCA_TIMEOUT', 300),
    'queue' => env('ORCA_QUEUE', 'default'),

    'redis' => [
        'connection' => env('ORCA_REDIS_CONNECTION', 'default'),
        'ttl' => env('ORCA_REDIS_TTL', 7200),
    ],

    'claude' => [
        'binary' => env('CLAUDE_BINARY', 'claude'),
        'default_model' => env('CLAUDE_MODEL', 'claude-opus-4-6'),
        'default_permission_mode' => env('CLAUDE_PERMISSION_MODE', 'plan'),
        'max_turns' => env('CLAUDE_MAX_TURNS', 50),
        'timeout' => env('CLAUDE_TIMEOUT', 3600),
        'default_allowed_tools' => [],
    ],

    'popout' => [
        'enabled' => env('ORCA_POPOUT_ENABLED', true),
        'transcript_max_kb' => 50,
        'cleanup_on_return' => true,
        'screenshot_interval' => 5,
        'screenshot_initial_delay' => 4,
        'prompt_delay' => 3,
        'heartbeat_interval' => 10,
        'heartbeat_stale_seconds' => 30,
    ],

    'auto_login' => [
        'enabled' => env('ORCA_AUTO_LOGIN_ENABLED', true),
        'expiry_minutes' => env('ORCA_AUTO_LOGIN_EXPIRY', 30),
    ],

    'screenshots' => [
        'disk' => env('ORCA_SCREENSHOT_DISK', 'local'),
        'directory' => 'orca/screenshots',
        'max_size_kb' => 10240,
        'cleanup_hours' => 24,
    ],

    'agents' => [
        'tools' => [
            'labels' => [
                'ExitPlanMode' => [
                    'Surfacing from strategy waters...',
                    'Breaking formation and making the move...',
                    'The pod has a plan. Time to breach.',
                    'Leaving deep-think mode and entering open water...',
                    'Chart complete. Orca action underway.',
                    'Done circling. Beginning the hunt.',
                    'From sonar maps to sharp turns...',
                ],
                'AskUserQuestion' => [
                    'Clicking for guidance from shore...',
                    'Sending a sonar ping for clarification...',
                    'The pod needs one more signal...',
                    'Checking the current before we dive deeper...',
                    'Requesting a waypoint from the captain...',
                    'Pausing the hunt for a quick echo back...',
                    'Looking for a clearer splash pattern...',
                ],
                'Write' => [
                    'Carving fresh lines through the current...',
                    'Etching the next wave into place...',
                    'Laying down clean tracks in the water...',
                    'Drafting with dorsal-fin precision...',
                    'Shaping the reef one line at a time...',
                    'Turning ideas into ink and wake...',
                    'Committing new markings to the map...',
                ],
                'Bash' => [
                    'Thrashing through the command surf...',
                    'Kicking up shell commands...',
                    'Diving into the terminal trench...',
                    'Snapping through command-line currents...',
                    'Working the rocks with a fast tail-slap...',
                    'Running sharp through open-water commands...',
                    'Hunting in the shell...',
                ],
                'Read' => [
                    'Scanning the waters for meaning...',
                    'Reading the tide marks...',
                    'Following the current through the text...',
                    'Listening closely to the echoes in the file...',
                    'Inspecting the reef for clues...',
                    'Tracing patterns beneath the surface...',
                    'Taking in the whole pod-song...',
                ],
                'Grep' => [
                    'Homing in on the right echo...',
                    'Sonar-locking onto key signals...',
                    'Filtering the sea noise for the real splash...',
                    'Sniffing out the exact current...',
                    'Searching the wake for matching patterns...',
                    'Pinpointing the signal beneath the chop...',
                    'Finding the fish in the data school...',
                ],
                'Glob' => [
                    'Sweeping the coastline for matching shapes...',
                    'Casting a wide sonar net...',
                    'Searching the bay for familiar patterns...',
                    'Gathering every shell that fits the shape...',
                    'Scanning open water for likely matches...',
                    'Pulling in the whole school by pattern...',
                    'Ranging wide across the pod\'s territory...',
                ],
                'Agent' => [
                    'Calling in another orca from the pod...',
                    'Dispatching a specialist from deep water...',
                    'Handing the hunt to a nearby fin...',
                    'A second dorsal has joined the chase...',
                    'Pod support incoming...',
                    'Tagging in a sharper set of teeth...',
                    'Coordinating with another hunter...',
                ],
                'mcp__laravel-boost__list-routes' => [
                    'Mapping the Laravel migration lanes...',
                    'Surveying every channel through the app...',
                    'Charting the route currents...',
                    'Reading the webway like an orca map...',
                    'Listing the swim paths through Laravel...',
                    'Tracing every endpoint in the reef...',
                    'Sonar-scanning the route network...',
                ],
                'Edit' => [
                    'Refining the wake line by line...',
                    'Smoothing the current into shape...',
                    'Tuning the pod-song for clarity...',
                    'Reworking the reef with a careful fin...',
                    'Trimming the splash without losing momentum...',
                    'Polishing the pass through open water...',
                    'Adjusting the current for a cleaner swim...',
                ],
            ],
        ],
    ],
];
