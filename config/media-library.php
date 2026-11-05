<?php

return [
    // Shared hosting runs the application scheduler without a permanent worker.
    // Spatie merges the remaining defaults from its package configuration.
    'queue_conversions_by_default' => env('QUEUE_CONVERSIONS_BY_DEFAULT', false),
];
