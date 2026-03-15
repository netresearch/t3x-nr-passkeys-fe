<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential',
        'label' => 'label',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'created_at DESC',
        'iconfile' => 'EXT:nr_passkeys_fe/Resources/Public/Icons/credential.svg',
        'hideTable' => true,
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'fe_user' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential.fe_user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'fe_users',
                'readOnly' => true,
            ],
        ],
        'credential_id' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential.credential_id',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'label' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential.label',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 128,
                'readOnly' => true,
            ],
        ],
        'aaguid' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential.aaguid',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'site_identifier' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential.site_identifier',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'storage_pid' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential.storage_pid',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'created_at' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential.created_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'last_used_at' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential.last_used_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'revoked_at' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential.revoked_at',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'revoked_by' => [
            'label' => 'LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:tx_nrpasskeysfe_credential.revoked_by',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'fe_users',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'fe_user, credential_id, label, aaguid, site_identifier, storage_pid, created_at, last_used_at, revoked_at, revoked_by',
        ],
    ],
];
