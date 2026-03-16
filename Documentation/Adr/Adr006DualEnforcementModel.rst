.. include:: /Includes.rst.txt

==================================================================
ADR-006: Dual Enforcement Model (Site + FE Groups, Strictest Wins)
==================================================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

``nr-passkeys-be`` uses per-group enforcement on ``be_groups`` with four levels:
Off, Encourage, Required, Enforced. For frontend users, there is an additional
dimension: site-level enforcement, since TYPO3 can serve multiple sites with
different security requirements.

Decision
========

**Dual enforcement: per ``fe_groups`` + per site configuration. Strictest wins.**

Enforcement levels (reusing ``EnforcementLevel`` from nr-passkeys-be):

- **Off:** No passkey prompts or requirements.
- **Encourage:** Dismissible enrollment banner after login.
- **Required:** Enrollment interstitial after login. Skippable during grace period.
  After grace period, passkey enrollment is mandatory but password fallback remains.
- **Enforced:** Mandatory passkey enrollment, no skip. Password login blocked for
  users who have at least one passkey registered.

Resolution algorithm:

.. code-block:: php

   $effectiveLevel = max(
       $siteEnforcementLevel->severity(),
       $strictestGroupLevel->severity()
   );

Site-level enforcement is set in ``config/sites/*/settings.yaml``:

.. code-block:: yaml

   nr_passkeys_fe:
     enforcement: 'encourage'

Group-level enforcement is set on ``fe_groups.passkey_enforcement``.

Grace period is per-group (``fe_groups.passkey_grace_period_days``), tracked per-user
(``fe_users.passkey_grace_period_start``).

Consequences
============

**Positive:**

- Site-level gives integrators a global switch for the whole portal
- Group-level gives granular control for privileged user groups
- Consistent with nr-passkeys-be enforcement model
- ``EnforcementLevelResolvedEvent`` allows custom business logic to override

**Negative:**

- Two places to configure = potential admin confusion
- "Strictest wins" may surprise admins (group Required + site Off = Required)

**Mitigation:**

- Admin module shows effective enforcement per user with source breakdown
- Documentation with examples: "Site Off + Group Required = Required for that group"
- ``FrontendEnforcementStatus`` DTO exposes both ``siteLevel`` and ``groupLevel``
  for transparency

Alternatives Considered
=======================

**Per-group only (Option A):** No way to enforce site-wide without touching every group.

**Per-site only (Option B):** No granular control. Cannot require passkeys for admins
but not regular users.
