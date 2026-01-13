<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000', 
        'http://localhost:3001', 
        'http://127.0.0.1:3000',
        'https://subatomic-billy-grazyna.ngrok-free.dev',
        // Add your specific Vercel deployment URL here
         'https://lfg-theta.vercel.app', 
         'https://www.lfg-ph.games',
         'https://lfg-ph.games',
    ],

    'allowed_origins_patterns' => [
        '/^https:\/\/.*\.ngrok-free\.dev$/',  // Allow any ngrok subdomain
        '/^https:\/\/.*\.vercel\.app$/',       // Allow any Vercel deployment
        '/^https:\/\/.*-.*\.vercel\.app$/',    // Allow preview deployments (branch-name-project.vercel.app)
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];