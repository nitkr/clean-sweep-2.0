<?php
/**
 * Directory entry. Always go through the PHP app so Recovery Mode can run
 * before any API talks to WordPress. Do not serve the static index.html.
 */
require __DIR__ . '/clean-sweep.php';
