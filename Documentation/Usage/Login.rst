..  include:: ../Includes.rst.txt

..  _usage-login:

Login
=====

The NrPasskeysFe:Login plugin provides two login flows depending on
site configuration.

Discoverable login (Variant A)
-------------------------------

When the browser supports WebAuthn Conditional UI and the site has
no pre-filled username, the login page shows a passkey autofill
option in the username input field. The browser presents available
passkeys matching the site's RP ID without requiring a username.

This is the recommended flow for passkey-only sites.

1. Open the login page.
2. The browser automatically suggests a passkey in the username field
   (autofill dropdown).
3. Select the passkey from the dropdown.
4. Authenticate with the biometric prompt (TouchID, Windows Hello, etc.).
5. You are logged in.

Username-first login (Variant B)
----------------------------------

If the browser does not support Conditional UI, or the user prefers to
type their username first:

1. Enter your username in the login form.
2. Click :guilabel:`Sign in with a passkey`.
3. The browser prompts for your passkey.
4. Authenticate with the biometric prompt.
5. You are logged in.

Password fallback
-----------------

When no passkey is available and the enforcement level allows it,
users can still log in with a password via the standard felogin plugin
or the password fallback link on the passkey login form.

When enforcement level is ``required`` or ``enforced``, users without
a passkey are redirected to the enrollment page after password login.

Error states
------------

The login form displays user-friendly error messages for:

- **No passkey found** -- Passkey not registered for this site or
  device.
- **Authentication cancelled** -- User dismissed the browser prompt.
- **Challenge expired** -- The challenge timed out (120 seconds by
  default). Try again.
- **Account locked** -- Too many failed attempts. Wait for the lockout
  to expire or contact an administrator.

felogin integration
-------------------

If you use the standard felogin plugin, the extension can inject a
passkey button below the password login form. To enable this, ensure
``nr_passkeys_fe`` is active and the felogin plugin is on the same page.

The passkey button is added via a PSR-14 event listener on the
felogin rendering event. The button opens the same WebAuthn flow as
the standalone login plugin.
