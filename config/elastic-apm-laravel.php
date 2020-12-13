<?php

return [
    // Sets whether the apm reporting should be active or not
    'active' => env('ELASTIC_APM_ENABLED', env('APM_ACTIVE', true)),

    'log-level' => env('APM_LOG_LEVEL', 'error'),

    'cli' => [
        // Sets whether the apm reporting should also be active for non-HTTP requests
        'active' => env('APM_ACTIVE_CLI', true),
    ],

    'app' => [
        // The app name that will identify your app in Kibana / Elastic APM, limited characters allowed
        'appName' => preg_replace('/[^a-zA-Z0-9 _-]/', '-', env('ELASTIC_APM_SERVICE_NAME', env('APM_APPNAME', 'Laravel'))),

        // The version of your app
        'appVersion' => env('ELASTIC_APM_SERVICE_VERSION', env('APM_APPVERSION')),
    ],

    'env' => [
        // whitelist environment variables OR send everything
        'env' => ['DOCUMENT_ROOT', 'REMOTE_ADDR'],

        // Application environment
        'environment' => env('APM_ENVIRONMENT', 'development'),
    ],

    'server' => [
        // The apm-server to connect to
        'serverUrl' => env('ELASTIC_APM_SERVER_URL', env('APM_SERVERURL', 'http://127.0.0.1:8200')),

        // Token for x
        'secretToken' => env('ELASTIC_APM_SECRET_TOKEN', env('APM_SECRETTOKEN')),

        // Hostname of the system the agent is running on.
        'hostname' => null,
    ],

    'agent' => [
        'hostname' => env('ELASTIC_APM_HOSTNAME', gethostname()),
        'transactionSampleRate' => env('ELASTIC_APM_TRANSACTION_SAMPLE_RATE', 1),
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
        'backtraceDepth' => env('ELASTIC_APM_STACK_TRACE_LIMIT', env('APM_BACKTRACEDEPTH', 25)),

        'querylog' => [
            // Set to false to completely disable query logging, or to 'auto' if you would like to use the threshold feature.
            'enabled' => env('APM_QUERYLOG', true),

            // If a query takes longer then 200ms, we enable the query log. Make sure you set enabled = 'auto'
            'threshold' => env('APM_THRESHOLD', 200),
        ],
    ],
];
