..  include:: ../Includes.rst.txt

..  _administration-dashboard:

Dashboard
=========

The Passkey Management FE backend module is accessible at
:guilabel:`Admin Tools > Passkey Management FE`.

The dashboard provides:

Adoption statistics
-------------------

Overview of passkey adoption across all frontend users:

- **Total users** -- All active ``fe_users`` records
- **Users with passkeys** -- Users with at least one enrolled passkey
- **Adoption rate** -- Percentage with a passkey
- **Users in grace period** -- Users enrolled but within their grace
  period (enforcement only)

Per-group breakdown
-------------------

For each frontend user group with enforcement configured:

- Group name and enforcement level
- Number of users in the group with / without passkeys
- Users in grace period

Recent activity
---------------

Recent passkey events for audit and monitoring:

- Passkey enrollments (user, device, date)
- Passkey removals
- Successful and failed authentication attempts
- Recovery code usage

The dashboard auto-refreshes every 30 seconds.

Site selector
-------------

When multiple TYPO3 sites are configured, a site selector allows
switching between site-specific statistics.
