<?php
/**
 * Language-agnostic SEO spam tokens and hide-CSS fragments.
 *
 * `core` counts toward the catalog needle cap. `generic` is gate + cs_0264 only
 * (not doorway slugs). `hide_css` is regex, not keywords.
 */
return [
    'core' => [
        'viagra',
        'cialis',
        'levitra',
        'xanax',
        'casino',
        '1xbet',
        'bet365',
        'mostbet',
        'pin-up',
        'pinup',
    ],
    'generic' => [
        'poker',
        'roulette',
        'jackpot',
        'gambling',
    ],
    'hide_css' => [
        'display\\s*:\\s*none',
        'visibility\\s*:\\s*hidden',
        'left\\s*:\\s*-\\d{3,}',
        'font-size\\s*:\\s*0',
        'opacity\\s*:\\s*0',
        'text-indent\\s*:\\s*-\\d{3,}',
        'height\\s*:\\s*0(?:px)?[^"\']{0,80}overflow\\s*:\\s*hidden',
        'overflow\\s*:\\s*hidden[^"\']{0,80}height\\s*:\\s*0(?:px)?',
    ],
];
