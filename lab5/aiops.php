<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AIOps Response Policies
    |--------------------------------------------------------------------------
    |
    | This array maps internal anomaly and incident types to dynamic automated
    | responses that the Aiops Respond engine will execute.
    |
    */

    'response_policies' => [
        'LATENCY_SPIKE'              => 'restart service',
        'ERROR_STORM'                => 'send alert',
        'LOCALIZED_ENDPOINT_FAILURE' => 'circuit breaker',
        'TRAFFIC_SURGE'              => 'scale service',
        'DEFAULT'                    => 'escalate',
    ],

];
