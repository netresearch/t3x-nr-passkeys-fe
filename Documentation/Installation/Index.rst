..  include:: ../Includes.rst.txt

..  _installation:

============
Installation
============

Prerequisites
=============

- TYPO3 13.4 LTS or TYPO3 14.1+
- PHP 8.2, 8.3, 8.4, or 8.5
- ``netresearch/nr-passkeys-be`` ^0.6 (installed automatically)
- HTTPS is **required** for WebAuthn (except ``localhost`` during
  development)
- A configured TYPO3 encryption key
  (``$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']``,
  minimum 32 characters)

Installation via Composer
=========================

This is the recommended way to install the extension:

..  code-block:: bash

    composer require netresearch/nr-passkeys-fe

This also installs ``netresearch/nr-passkeys-be`` as a dependency.

Activate the extension
======================

After installation, activate the extension in the TYPO3 backend:

1. Go to :guilabel:`Admin Tools > Extensions`
2. Search for "Passkeys Frontend Authentication"
3. Click the activate button

Or use the CLI:

..  code-block:: bash

    vendor/bin/typo3 extension:activate nr_passkeys_fe

..  note::

    If ``nr_passkeys_be`` is not already active, activate it first.
    Both extensions must be active for the frontend login to work.

Database schema update
======================

The extension adds two tables and extends two core tables:

- ``tx_nrpasskeysfe_credential`` -- Frontend passkey credentials
- ``tx_nrpasskeysfe_recovery_code`` -- Bcrypt-hashed recovery codes
- ``fe_users`` -- Adds ``passkey_grace_period_start`` and
  ``passkey_nudge_until`` columns for enforcement tracking
- ``fe_groups`` -- Adds ``passkey_enforcement`` and
  ``passkey_grace_period_days`` columns for per-group enforcement

After activation, run the database schema update:

1. Go to :guilabel:`Admin Tools > Maintenance > Analyze Database
   Structure`
2. Apply the suggested changes

Or use the CLI:

..  code-block:: bash

    vendor/bin/typo3 database:updateschema

Include TypoScript
==================

Include the extension's TypoScript in your site configuration:

1. Go to :guilabel:`Site Management > TypoScript`
2. Edit your root TypoScript record
3. Add the static template
   :guilabel:`Passkeys Frontend Authentication (nr_passkeys_fe)`

Or add it manually:

..  code-block:: typoscript

    @import 'EXT:nr_passkeys_fe/Configuration/TypoScript/setup.typoscript'
    @import 'EXT:nr_passkeys_fe/Configuration/TypoScript/constants.typoscript'

Add the plugins
===============

Three frontend plugins are available. Add them to your pages as
content elements:

..  rst-class:: dl-parameters

NrPasskeysFe:Login
    The passkey login form. Place on your login page.
    Supports both discoverable (usernameless) and username-first login.

NrPasskeysFe:Management
    Self-service credential management. Place on a page accessible
    only to logged-in users.

NrPasskeysFe:Enrollment
    Enrollment form used as the interstitial target. Required when
    enforcement is active.

See :ref:`quick-start` for a step-by-step walkthrough.

Verify the installation
=======================

After activation:

1. Visit the login page with the NrPasskeysFe:Login plugin.
   You should see a :guilabel:`Sign in with a passkey` button.

2. The backend module :guilabel:`Admin Tools > Passkey Management FE`
   should appear.

..  warning::

    HTTPS is mandatory for WebAuthn to function. The only exception
    is ``localhost`` for local development. If TYPO3 is behind a
    reverse proxy, ensure ``TYPO3_SSL`` or
    ``[SYS][reverseProxySSL]`` is set correctly.
