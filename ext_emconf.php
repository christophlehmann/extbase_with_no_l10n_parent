<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Fix language handling of extbase regarding records with no l10n parent',
    'description' => 'Show records in extbase if those got a different language defined then -1 (all) or 0 (default) and no language parent',
    'category' => 'module',
    'author' => 'Georg Ringer',
    'author_email' => 'mail@ringer.it',
    'state' => 'beta',
    'clearCacheOnLoad' => true,
    'version' => '2.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
