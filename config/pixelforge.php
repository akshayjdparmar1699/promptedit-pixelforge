<?php

return [
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Seconds a signed webhook timestamp may differ from now before it is treated as a replay.
    'signature_tolerance' => 300,

    // Markup applied on top of the provider cost, in whole percent.
    'markup_percent' => 25,

    // Submission retry policy for 429 / 503 / network errors.
    'submit_max_attempts' => 30,
    'submit_give_up_after_minutes' => 20,

    // Credit packs offered by the fake "Buy credits" flow, in whole US dollars.
    'credit_packs' => [5, 10, 25],
];
