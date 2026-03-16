.. include:: /Includes.rst.txt

==========================================================
ADR-007: Post-Login Enrollment Interstitial via Middleware
==========================================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

Users who log in with a password and don't yet have passkeys registered need to be
prompted to enroll. This is the primary adoption driver. ``nr-passkeys-be`` solves
this with a PSR-15 middleware (``PasskeySetupInterstitial``) that intercepts backend
navigation for users who must register passkeys.

In the frontend, this is more complex because:

- FE pages are public-facing, not a controlled backend UI
- Redirect loops must be avoided (enrollment page itself must be exempt)
- The enrollment experience must be self-contained and user-friendly

Decision
========

**PSR-15 middleware (``PasskeyEnrollmentInterstitial``) in the FE request chain.**

The middleware runs after ``FrontendUserAuthenticator`` and checks:

1. Is the user authenticated? (No → skip)
2. Does the user have passkeys? (Yes → skip)
3. What is the effective enforcement level?

   - **Off:** Skip
   - **Encourage:** Skip (banner handles this, not interstitial)
   - **Required:** Redirect to enrollment page. Skip allowed with session nonce
     during grace period. After grace period, redirect is persistent.
   - **Enforced:** Redirect to enrollment page. No skip option.

4. Is the current request to an exempt path? (passkey API routes, logout,
   enrollment page itself → skip)

**Enrollment page:** A dedicated page with the ``PasskeyEnrollmentPlugin`` that
integrators configure via TypoScript constant:

.. code-block:: typoscript

   plugin.tx_nrpasskeysfe.settings.enrollmentPage = 42

**Skip mechanism:** Session-based nonce (CSRF-protected). Skipping sets a session
flag that suppresses the redirect for the remainder of the session.

Consequences
============

**Positive:**

- Proven pattern from nr-passkeys-be, adapted for FE
- Enforcement levels give gradual adoption control
- Skip mechanism prevents user frustration during grace period
- Exempt paths prevent redirect loops

**Negative:**

- Requires integrator to create a dedicated enrollment page
- Redirect may confuse users unfamiliar with passkeys
- Session-based skip resets on new session (intentional for Required level)

**Mitigation:**

- Quick start documentation with step-by-step enrollment page setup
- Clear messaging on the enrollment page explaining why the user is there
- ``Encourage`` level uses non-intrusive banner instead of redirect

Alternatives Considered
=======================

**Banner only (no interstitial):** Banners are easily dismissed and forgotten.
For ``Required`` and ``Enforced`` levels, a redirect is the only way to ensure
users actually enroll.

**JavaScript-only prompt:** Fragile (can be blocked by ad blockers), not accessible,
and can't enforce enrollment for ``Enforced`` level.
