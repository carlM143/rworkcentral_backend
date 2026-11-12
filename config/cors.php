<?php

// <<<<<<< HEAD
// =======
// $origins = array_values(array_filter(array_map(
//     'trim',
//     explode(',', (string) env(
//         'CORS_ALLOWED_ORIGINS',
//         'http://127.0.0.1:8000',
//         // 'https://rwork-frontend.rwebcloud.app/',
//     )),
// )));

// >>>>>>> 694760b (update the cors.php)
// return [

//     'paths' => ['api/*', 'sanctum/csrf-cookie'],

//     'allowed_methods' => ['*'],

//     'allowed_origins' => [
//         'http://localhost:3000',
//         'http://127.0.0.1:3000',
//         'https://rwork-frontend.rwebcloud.app',
//     ],

//     'allowed_origins_patterns' => [],

//     'allowed_headers' => ['*'],

//     'exposed_headers' => [],

//     'max_age' => 0,

//     'supports_credentials' => true,

// ];

$defaultOrigins = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'https://rwork-frontend.rwebcloud.app',
];

$envOrigins = array_filter(array_map(
    fn (string $origin) => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
));

$origins = array_values(array_unique(array_merge($defaultOrigins, $envOrigins)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];