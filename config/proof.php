<?php

return [
    'image_max_kb' => (int) env('PROOF_IMAGE_MAX_KB', 100),
    'image_target_kb' => (int) env('PROOF_IMAGE_TARGET_KB', 50),
    'image_max_dimension' => (int) env('PROOF_IMAGE_MAX_DIMENSION', 1024),
    'storage_disk' => env('PROOF_STORAGE_DISK', 'local'),
    'size_warning_threshold_kb' => (int) env('PROOF_SIZE_WARNING_THRESHOLD_KB', 500),
];
