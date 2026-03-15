..  include:: ../Includes.rst.txt

..  _usage-management:

Management
==========

The NrPasskeysFe:Management plugin allows logged-in frontend users to
manage their own passkeys and recovery codes.

Accessing the management panel
--------------------------------

Place the NrPasskeysFe:Management plugin on a page restricted to
authenticated frontend users (e.g. a "My Account" page).

Available actions
-----------------

..  rst-class:: dl-parameters

List passkeys
    The panel shows all registered passkeys with their name,
    registration date, last used date, and the authenticator type.

Enroll a new passkey
    Click :guilabel:`Register a new passkey` to add another device.
    See :ref:`usage-enrollment`.

Rename a passkey
    Click the edit icon next to a passkey to rename it.

Remove a passkey
    Click the delete icon next to a passkey to revoke it.

    ..  warning::

       If enforcement level is ``required`` or ``enforced``, removing
       the last passkey may be blocked. The user must enroll a new
       passkey first.

Generate recovery codes
    Generates a new set of 10 one-time recovery codes.
    See :ref:`usage-recovery`.

Passkey list fields
-------------------

=================  ================================================
Field              Description
=================  ================================================
Name               User-defined label (e.g. "iPhone 16")
Registered         Date the passkey was enrolled
Last used          Date of the most recent successful authentication
Authenticator      AAGUID-based device type, if known
=================  ================================================
