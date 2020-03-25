<?php

return [
    // Sets whether the apm reporting should be active or not
    'active' => env('APM_ACTIVE', true),

    'cli' => [
        // Sets whether the apm reporting should also be active for non-HTTP requests
        'active' => env('APM_ACTIVE_CLI', true),
    ],

    'app' => [
        // The app name that will identify your app in Kibana / Elastic APM, limited characters allowed
        'appName' => preg_replace('/[^a-zA-Z0-9 _-]/', '-', env('APM_APPNAME', 'Laravel')),

        // The version of your app
        'appVersion' => env('APM_APPVERSION', ''),
    ],

    'env' => [
        // whitelist environment variables OR send everything
        'env' => ['DOCUMENT_ROOT', 'REMOTE_ADDR'],

        // Application environment
        'environment' => env('APM_ENVIRONMENT', 'development'),
    ],

    // GuzzleHttp\Client options (http://docs.guzzlephp.org/en/stable/request-options.html#request-options)
    'httpClient' => [],

    'server' => [
        // The apm-server to connect to
        'serverUrl' => env('APM_SERVERURL', 'http://127.0.0.1:8200'),

        // Token for x
        'secretToken' => env('APM_SECRETTOKEN', null),

        // Hostname of the system the agent is running on.
        'hostname' => gethostname(),
    ],

    'transactions' => [
        // This option will bundle transaction on the route name without variables.
        'useRouteUri' => env('APM_USEROUTEURI', true),
        // This is a regular expression to match and filter out transactions by name. Use | in regex for multiple patterns.
        'ignorePatterns' => env('APM_IGNORE_PATTERNS', null),
    ],

    'spans' => [
        // Max number of child items displayed when viewing trace details.
        'maxTraceItems' => env('APM_MAXTRACEITEMS', 1000),

        // Depth of backtraces
        'backtraceDepth' => env('APM_BACKTRACEDEPTH', 25),

        'querylog' => [
            // Set to false to completely disable query logging, or to 'auto' if you would like to use the threshold feature.
            'enabled' => env('APM_QUERYLOG', true),

            // If a query takes longer then 200ms, we enable the query log. Make sure you set enabled = 'auto'
            'threshold' => env('APM_THRESHOLD', 200),
        ],
    ],
];
