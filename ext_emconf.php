<?php

/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 */

// No declare() here — TER requires plain PHP in ext_emconf.php.

$EM_CONF[$_EXTKEY] = [
    'title' => 'Passkeys Frontend Authentication',
    'description' => 'Passkey-first frontend login for fe_users (WebAuthn/FIDO2): passwordless sign-in with Touch ID, Face ID, YubiKey or Windows Hello.',
    'category' => 'fe',
    'author' => 'Netresearch DTT GmbH',
    'author_email' => '',
    'author_company' => 'Netresearch DTT GmbH',
    'state' => 'stable',
    'version' => '2.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.20-14.3.99',
            'php' => '8.2.0-8.99.99',
            'nr_passkeys_be' => '1.0.0-1.99.99',
            'frontend' => '13.4.0-14.3.99',
            'extbase' => '13.4.0-14.3.99',
            'fluid' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'felogin' => '13.4.0-14.3.99',
            'dashboard' => '13.4.0-14.3.99',
        ],
    ],
];
