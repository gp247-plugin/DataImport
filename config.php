<?php
/**
 * Data Import DEFAULT settings. This file belongs to the plugin and is overwritten by every
 * update — to change a value on a site, set it in the site's own config (key
 * `Plugins/DataImport.<name>`), never here.
 */
return [
    // Largest source file accepted, in bytes (100 MB).
    'max_file_bytes' => 104857600,

    // Days a written run can be undone.
    'undo_days' => 30,

    // Days an unfinished run (and its staged data) is kept after its last activity.
    'purge_days' => 7,

    // A writing run with no slice for this many minutes no longer blocks backups.
    'idle_minutes' => 15,

    // Most parts a chunked upload may have.
    'max_chunks' => 10000,

    // Largest product image downloaded from a URL, in bytes (5 MB), and the time allowed per image, in seconds.
    'image_max_bytes' => 5242880,
    'image_timeout' => 10,

    // Most images taken from one product row.
    'max_images' => 10,
];
