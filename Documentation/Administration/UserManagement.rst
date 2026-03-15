..  include:: ../Includes.rst.txt

..  _administration-user-management:

User Management
===============

Administrators can manage frontend user passkeys from the backend
module or via the ``fe_users`` record in the list module.

Viewing credentials in fe_users
---------------------------------

When editing a ``fe_users`` record in :guilabel:`Web > List`, a
read-only info element shows:

- Number of registered passkeys
- Last used date
- Enforcement level applicable to the user

This uses a custom TCA element (``passkey_fe_info``) registered
by the extension.

Backend module actions
-----------------------

From :guilabel:`Admin Tools > Passkey Management FE`:

..  rst-class:: dl-parameters

List credentials
    View all passkeys registered by a specific user.

Revoke a credential
    Immediately invalidate a specific passkey. The user must re-enroll
    from that device.

Revoke all credentials
    Remove all passkeys for a user. Use when a user's device is lost
    or stolen.

Reset grace period
    Reset the grace period start date to give a user more time to
    enroll (for ``required`` enforcement only).

Unlock account
    If the user's account is locked due to too many failed attempts,
    unlock it immediately.

Invalidating passkeys via database
------------------------------------

In an emergency, you can revoke all passkeys for a user directly:

..  code-block:: sql

    -- View credentials
    SELECT * FROM tx_nrpasskeysfe_credential
    WHERE fe_user_uid = <uid>;

    -- Revoke all credentials for a user
    UPDATE tx_nrpasskeysfe_credential
    SET deleted = 1
    WHERE fe_user_uid = <uid>;

    -- Or hard-delete
    DELETE FROM tx_nrpasskeysfe_credential
    WHERE fe_user_uid = <uid>;

..  warning::

    Direct database manipulation bypasses audit logging. Prefer using
    the backend module or admin API when possible.
