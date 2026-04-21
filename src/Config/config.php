<?php

return [
    'timeout' => 10,
    'retry_total' => 3,
    'retry_backoff' => 0.5,
    'retry_status_codes' => [500, 502, 503, 504, 429],
    'acl_name_cache_ttl' => 600,
];
