..  include:: ../Includes.rst.txt

..  _enforcement:

Enforcement
===========

The enforcement system controls how strongly passkeys are required for
frontend users. Enforcement can be set at the site level and overridden
per frontend user group.

Enforcement levels
------------------

Four levels are available:

..  rst-class:: dl-parameters

Off
    Passkeys are completely optional. No prompts, banners, or
    interstitials. Users can log in with a password as normal.

Encourage
    Users without a passkey see a dismissible banner after login
    suggesting they enroll one. No access is blocked. The banner
    can be dismissed and will not re-appear for the session.

Required
    Users without a passkey see an enrollment interstitial after
    login. They can skip the interstitial during the grace period
    (configurable, default 14 days). After the grace period expires,
    they must enroll to continue.

Enforced
    Users without a passkey cannot bypass the enrollment interstitial.
    Grace period skipping is disabled. This level is suitable for
    high-security sites.

Enforcement resolution
-----------------------

The effective enforcement level for a user is determined by:

1. **Site configuration** -- The site-level ``enforcementLevel``
   setting (see :ref:`site-configuration`).
2. **User group overrides** -- Each frontend user group can have an
   enforcement level. The strictest level across all groups the user
   belongs to wins.
3. **Grace period** -- The shortest grace period across applicable
   groups wins.

The ``EnforcementLevelResolvedEvent`` PSR-14 event allows listeners to
further override the resolved level (see :ref:`events-reference`).

Configuring per-group enforcement
-----------------------------------

In the TYPO3 backend:

1. Go to :guilabel:`Web > List` and open the ``fe_groups`` record.
2. The TCA record shows a :guilabel:`Passkey Enforcement` section.
3. Set the enforcement level and grace period for the group.

Or use the backend module:

1. Go to :guilabel:`Admin Tools > Passkey Management FE`.
2. In the Enforcement tab, select a site.
3. Adjust enforcement levels per group.

Interstitial behaviour
-----------------------

When a user triggers the enrollment interstitial (level ``required``
or ``enforced``):

- The full-page interstitial is shown.
- It explains why a passkey is required.
- It shows the enrollment form (links to the enrollment page).
- For ``required`` level: a :guilabel:`Skip for now` button is shown
  with the remaining grace period.
- For ``enforced`` level: no skip button.
- API endpoints, AJAX requests, and the login/logout pages are
  exempted from the interstitial.

Grace period tracking
---------------------

Grace periods are tracked per user in the ``fe_users`` table via the
``passkey_grace_period_start`` column (unix timestamp of the first login
without a passkey after enforcement was enabled). The enforcement
middleware computes the expiry from
``passkey_grace_period_start + gracePeriodDays``.

Per-group grace period days are stored in ``fe_groups.passkey_grace_period_days``.
The shortest grace period across all applicable groups wins.
