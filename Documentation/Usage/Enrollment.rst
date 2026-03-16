..  include:: ../Includes.rst.txt

..  _usage-enrollment:

Enrollment
==========

Passkey enrollment is the process of creating a new passkey credential
on a device and registering the public key with the TYPO3 site.

Prerequisites
-------------

- The user must be logged in to a frontend session.
- The browser must support WebAuthn (all modern browsers do).
- HTTPS is required (or ``localhost``).

Enrolling a passkey
--------------------

Users enroll passkeys from the NrPasskeysFe:Management plugin or the
dedicated enrollment page:

1. Navigate to the management page (or the enrollment interstitial).
2. Click :guilabel:`Register a new passkey`.
3. The browser opens the passkey creation dialog.
4. Choose an authenticator (TouchID, Windows Hello, YubiKey, etc.).
5. Optionally enter a name for the passkey (e.g. "MacBook Pro").
6. Confirm with the biometric prompt.
7. The passkey is registered and appears in the credential list.

..  note::

    Multiple passkeys can be registered on different devices. This
    is recommended so users are not locked out if they lose a device.

Post-login enrollment interstitial
------------------------------------

When the site enforcement level is ``required`` or ``enforced``, users
who log in without a passkey are redirected to the enrollment page
before accessing the site. This interstitial:

- Explains why a passkey is required.
- Provides the enrollment form.
- Shows remaining grace period days (for ``required`` level).
- When the grace period expires or level is ``enforced``, skipping
  is disabled.

See :ref:`enforcement` for details on configuring enforcement levels.

Naming passkeys
---------------

During enrollment, users can give each passkey a name. This name
appears in the management panel to help users identify which
authenticator each passkey belongs to (e.g. "iPhone 16",
"YubiKey 5C NFC").

Names can be renamed later in the management panel.
