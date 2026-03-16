<?php
/**
 * EZ Dispatch Configuration
 * Central config for the dispatch communication system.
 */

return [
    // Cloudflare Calls
    'cloudflare' => [
        'app_id' => 'ff035ea231bdf9ce73265cef75690da9',        // damp-sun-2b0b (production)
        'app_token' => '53cb9fa8482f77b5e8d085d392a91e8179d7116db80e93863811bec7217c3776',
        'dev_app_id' => '78e89180e6352d1597b9e60bc8f813a4',    // cool-dust-8338 (dev)
        'dev_app_token' => 'c4e29078df1a11e4827e332d5275ef005a6cac900cd5e244649d09b240ff0015',
        'base_url' => 'https://rtc.live.cloudflare.com/v1',
    ],

    // SignalWire (existing)
    'signalwire' => [
        'project_id' => 'ce4806cb-ccb0-41e9-8bf1-7ea59536adfd',
        'space' => 'mobilemechanic.signalwire.com',
        'token' => 'PT1c8cf22d1446d4d9daaf580a26ad92729e48a4a33beb769a',
        'numbers' => [
            'mechanic' => '+19047066669',
            'mechanic_ported' => '+19042175152',
            'sod' => '+19049258873',
        ],
        'forward_to' => '+19046634789',
    ],

    // CRM API
    'crm' => [
        'api_url' => 'https://ezlead4u.com/crm/api/rest.php',
        'username' => 'claude',
        'password' => 'badass',
        'api_key' => 'dZrvuC3Q1A8Cv82X1dQsugRiCpmU3FSMHn9BFWlY',
    ],

    // CRM Entity & Field IDs for Conversations (entity 51)
    'conversations' => [
        'entity_id' => 51,
        'fields' => [
            'customer'    => 482,
            'channel'     => 483,
            'direction'   => 484,
            'status'      => 485,
            'started'     => 486,
            'duration'    => 487,
            'recording'   => 488,
            'transcript'  => 489,
            'screenshots' => 490,
            'job'         => 491,
            'site'        => 492,
            'notes'       => 493,
            'phone'       => 494,
            'cf_session'  => 495,
        ],
        'channels' => [
            'Call'   => 143,
            'Video'  => 144,
            'SMS'    => 145,
            'WebRTC' => 146,
        ],
        'directions' => [
            'Inbound'  => 147,
            'Outbound' => 148,
        ],
        'statuses' => [
            'Ringing'         => 149,
            'Answered'        => 150,
            'In Call'         => 151,
            'Ended'           => 152,
            'Declined'        => 153,
            'Missed'          => 154,
            'Timed Out'       => 155,
            'Recording Ready' => 156,
            'Transcribed'     => 157,
        ],
        'sites' => [
            'mechanicstaugustine.com' => 158,
            'sodjax.com'              => 159,
            'jacksonvillesod.com'     => 160,
            'sodjacksonvillefl.com'   => 161,
            'drainagejax.com'         => 162,
            'sod.company'             => 163,
            'nearby.contractors'      => 164,
            'mechanicstaugustine.com'  => 165,
            // mobilemechanic.best removed — now nationwide site, not local mechanic
        ],
    ],

    // WebSocket server
    'websocket' => [
        'host' => '127.0.0.1',
        'port' => 8765,
        'http_port' => 8766,
        'auth_token' => 'dispatch-dev-token',
    ],

    // Dispatch auth
    'auth' => [
        'password' => 'ez',
    ],

    // Video link settings
    'video_link' => [
        'ttl_seconds' => 3600,
        'base_url' => 'https://mechanicstaugustine.com/video/',
    ],

    // Recording consent
    'consent_message' => 'This call may be recorded for quality purposes.',
];
