<?php

return [
    'internal_single_org_mode' => filter_var(env('INTERNAL_SINGLE_ORG_MODE', true), FILTER_VALIDATE_BOOL),
    'internal_organization_id' => env('INTERNAL_SINGLE_ORG_ID'),
    'internal_organization_name' => env('INTERNAL_SINGLE_ORG_NAME', 'Prime Healers'),
];
