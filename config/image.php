<?php

return [
    'quality' => (int) env('IMAGE_QUALITY', 82),
    'max_width' => (int) env('IMAGE_MAX_WIDTH', 2048),
    'max_upload_kb' => (int) env('IMAGE_MAX_UPLOAD_KB', 5120),
];
