<?php

// No declare() here — TER requires plain PHP in ext_emconf.php.

$EM_CONF[$_EXTKEY] = [
    'title' => 'Passkeys Frontend Authentication',
    'description' => 'Passkey-first TYPO3 frontend authentication for fe_users (WebAuthn/FIDO2). Enables passwordless login with TouchID, FaceID, YubiKey, Windows Hello. By Netresearch.',
    'category' => 'fe',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => '',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
            'nr_passkeys_be' => '0.6.0-0.99.99',
            'frontend' => '13.4.0-14.99.99',
            'extbase' => '13.4.0-14.99.99',
            'fluid' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'felogin' => '13.4.0-14.99.99',
        ],
    ],
];
