<?php

return [
    // The release this install is running: the VERSION file at the top of the repository, set at each release.
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unknown',
];
