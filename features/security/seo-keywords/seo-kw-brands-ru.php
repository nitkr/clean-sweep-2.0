<?php
/**
 * Russian-script operator brands. Cyrillic byte boundary, not \\b and not /u.
 * The boundary keeps азино from matching inside казино.
 *
 * Not казино (the word casino) and not вулкан (volcano).
 */
return [
    'brands' => [
        'вавада',
        'мостбет',
        'мелбет',
        'пинап',
        'азино',
    ],
];
