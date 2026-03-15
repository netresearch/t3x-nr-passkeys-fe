..  include:: ../Includes.rst.txt

..  _quick-start:

===========
Quick Start
===========

This guide gets you from installation to your first passkey login in
five minutes.

Prerequisites
=============

- TYPO3 13.4 LTS or 14.1+ with HTTPS
- Composer-based installation

Step 1: Install
===============

..  code-block:: bash

    composer require netresearch/nr-passkeys-fe
    vendor/bin/typo3 extension:activate nr_passkeys_be
    vendor/bin/typo3 extension:activate nr_passkeys_fe
    vendor/bin/typo3 database:updateschema

Step 2: Include TypoScript
===========================

In your site's root TypoScript record, add:

..  code-block:: typoscript

    @import 'EXT:nr_passkeys_fe/Configuration/TypoScript/setup.typoscript'
    @import 'EXT:nr_passkeys_fe/Configuration/TypoScript/constants.typoscript'

Then set the page UIDs for your login and management pages:

..  code-block:: typoscript

    plugin.tx_nrpasskeysfe.settings.loginPageUid = 42
    plugin.tx_nrpasskeysfe.settings.managementPageUid = 43
    plugin.tx_nrpasskeysfe.settings.enrollmentPageUid = 44

Step 3: Add plugins to pages
=============================

Create three pages in your TYPO3 page tree:

1. **Login page** (e.g. UID 42): Add the content element
   :guilabel:`Plugin > Passkeys Frontend Authentication > Login`.
   This is your passkey login page.

2. **Management page** (e.g. UID 43): Add
   :guilabel:`Plugin > Passkeys Frontend Authentication > Management`.
   Restrict access to logged-in frontend users only.

3. **Enrollment page** (e.g. UID 44): Add
   :guilabel:`Plugin > Passkeys Frontend Authentication > Enrollment`.
   Used for the post-login interstitial. Can be the same as the
   management page.

Step 4: Configure the site
===========================

Add the following to your site's :file:`config.yaml`:

..  code-block:: yaml

    passkeys:
      rpId: 'your-domain.example'
      rpName: 'My Site'
      origin: 'https://your-domain.example'
      enforcementLevel: 'encourage'

Replace ``your-domain.example`` with your actual domain.

Step 5: Log in with a passkey
==============================

1. Visit your login page (e.g. ``/login``).
2. Click :guilabel:`Sign in with a passkey`.
3. If you have no passkey yet, you will be prompted to create one.
4. Follow the browser's passkey creation dialog (TouchID, Windows
   Hello, security key, etc.).
5. After enrollment, click :guilabel:`Sign in with a passkey` again.
   The browser will present your passkey. Authenticate with the
   biometric prompt.

..  tip::

    For discoverable (usernameless) login, passkey-capable browsers
    will offer to autofill your passkey directly in the username field.
    Enable Conditional UI in your browser settings for the best
    experience.

Next steps
==========

- :ref:`configuration` -- Configure enforcement levels and rate
  limiting
- :ref:`usage-enrollment` -- How users set up passkeys
- :ref:`administration` -- Manage passkeys from the backend module
- :ref:`multi-site` -- Configure multiple sites with different RP IDs
