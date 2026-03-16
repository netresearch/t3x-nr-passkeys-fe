.. include:: /Includes.rst.txt

===================================================================
ADR-003: Triple Recovery Mechanisms (Password + Codes + Magic Link)
===================================================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

Passkey-first authentication creates a lockout risk when users lose access to their
authenticator. The design principle is "recovery first" — no user should be permanently
locked out because their phone broke.

Four approaches were considered, from minimal to maximal:

A. **Password fallback only**
B. **Password + recovery codes**
C. **Password + magic link**
D. **All three: password + recovery codes + magic link**

Decision
========

**Option D: All three recovery mechanisms, phased.**

- **v0.1 — Password fallback:** Standard felogin password login remains available.
  Blocked only at ``Enforced`` enforcement level.
- **v0.1 — Recovery codes:** 10 one-time codes generated at passkey enrollment.
  Stored bcrypt-hashed. Format: ``XXXX-XXXX`` (alphanumeric).
- **v0.2 — Magic link:** Email-based one-time login URL. 15-minute TTL, single-use.
  Requires TYPO3 mail configuration.

Each mechanism can be enabled/disabled per site via site configuration:

.. code-block:: yaml

   nr_passkeys_fe:
     enabledRecoveryMethods:
       - password
       - recovery_code
       - magic_link

Consequences
============

**Positive:**

- Maximum flexibility for different deployment scenarios
- Per-site configurability covers diverse requirements
- Phased delivery reduces v0.1 scope while planning for v0.2
- Password fallback is free (felogin already handles it)

**Negative:**

- Magic link adds complexity (email delivery, token management)
- Three auth paths to secure and test
- User confusion about which recovery method to use

**Mitigation:**

- Magic link deferred to v0.2 (see ADR-011)
- Clear recovery UI: show available methods based on site config
- Each mechanism tested independently and in combination

Alternatives Considered
=======================

**Options A-C:** Each individually viable but less flexible. Enterprise deployments
may disable password fallback entirely (compliance), making recovery codes or magic
links essential. Consumer sites may prefer magic links over codes.
