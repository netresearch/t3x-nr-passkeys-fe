..  include:: ../Includes.rst.txt

..  _troubleshooting:

===============
Troubleshooting
===============

This page covers the most common issues encountered when setting up or
using the extension.

"Not allowed" / SecurityError in browser
-----------------------------------------

**Symptom:** The browser throws ``NotAllowedError`` or similar when
attempting passkey login or enrollment.

**Causes and fixes:**

1. **Not on HTTPS.** WebAuthn requires HTTPS. Exception: ``localhost``
   works over HTTP for development. Check your URL.

2. **Wrong RP ID.** The configured ``rpId`` must match the current
   domain. If ``rpId: 'example.com'`` but the page is served from
   ``sub.example.com``, registration will fail unless the RP ID is
   the registrable suffix.

3. **User cancelled the prompt.** The user dismissed the authenticator
   dialog. Not a server error.

4. **Cross-origin iframe.** WebAuthn cannot be invoked from a
   cross-origin iframe. Ensure the login plugin is on the same origin
   as the page.

Challenge expired
-----------------

**Symptom:** Login fails with "Challenge token expired or invalid."

**Fix:** The challenge TTL is 120 seconds by default. If users take
longer to authenticate (e.g. slow hardware key), increase
``challengeTtlSeconds`` in the extension settings.

"Invalid origin" error in logs
---------------------------------

**Symptom:** Authentication fails with an origin mismatch in the
TYPO3 logs.

**Fix:** The ``passkeys.origin`` in the site's ``config.yaml`` must
exactly match the scheme + domain + port combination the browser sees.
Include the port if non-standard (e.g. ``https://example.com:8443``).

Account locked
--------------

**Symptom:** User sees "Account locked" after multiple failed attempts.

**Fix:** Wait for the lockout duration to expire (default: 15 minutes),
or unlock via the backend module:
:guilabel:`Admin Tools > Passkey Management FE > Users`.

Login plugin shows no passkey button
--------------------------------------

**Symptom:** The login page shows the standard felogin form but no
passkey button.

**Checklist:**

1. Is ``nr_passkeys_fe`` activated? Check
   :guilabel:`Admin Tools > Extensions`.
2. Is the NrPasskeysFe:Login plugin (not felogin) added to the page?
3. Is TypoScript included? Check
   :guilabel:`Web > Template > TypoScript Object Browser`.
4. Are there JavaScript errors in the browser console?

Recovery codes not accepted
----------------------------

**Symptom:** Recovery code login fails with "Invalid recovery code."

**Causes:**

1. The code was already used. Each code is one-time only.
2. The user generated a new set, invalidating all previous codes.
3. The code was entered incorrectly (check for ``0`` vs ``O``,
   ``1`` vs ``l``).

Enrollment interstitial appears after every login
--------------------------------------------------

**Symptom:** User is redirected to the enrollment page on every login
even after enrolling.

**Causes:**

1. The enrolled credential's ``site_identifier`` does not match the
   current site. This can happen if the RP ID was changed after
   enrollment.
2. The TYPO3 ``SYS.encryptionKey`` was changed, invalidating the
   ``user_handle`` lookup.

**Fix:** Have the user revoke the old credential and re-enroll. If
``encryptionKey`` was changed, all credentials must be re-enrolled.

Debug logging
-------------

Enable TYPO3 debug logging to see detailed authentication errors:

..  code-block:: php
    :caption: config/system/additional.php

    $GLOBALS['TYPO3_CONF_VARS']['LOG']['Netresearch']['NrPasskeysFe'] = [
        'writerConfiguration' => [
            \TYPO3\CMS\Core\Log\LogLevel::DEBUG => [
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                    'logFileInfix' => 'nr_passkeys_fe',
                ],
            ],
        ],
    ];

Log file: ``var/log/typo3_nr_passkeys_fe_*.log``
