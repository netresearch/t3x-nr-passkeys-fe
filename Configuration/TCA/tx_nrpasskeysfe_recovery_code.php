<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_recovery_code',
        'label' => 'uid',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'crdate DESC',
        'hideTable' => true,
        'adminOnly' => true,
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'fe_user' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_recovery_code.fe_user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'fe_users',
                'readOnly' => true,
            ],
        ],
        'code_hash' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_recovery_code.code_hash',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'used_at' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_recovery_code.used_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'site_identifier' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_recovery_code.site_identifier',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'fe_user, code_hash, site_identifier, used_at',
        ],
    ],
];
