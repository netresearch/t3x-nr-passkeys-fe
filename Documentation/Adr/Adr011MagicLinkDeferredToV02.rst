.. include:: /Includes.rst.txt

=============================================
ADR-011: Magic Link Recovery Deferred to v0.2
=============================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

The design includes three recovery mechanisms: password fallback, recovery codes,
and magic link (email-based one-time login). All three are in scope for the extension,
but implementing all in v0.1 would increase the MVP scope significantly.

Magic link specifically requires:

- Email sending infrastructure (TYPO3 mail configuration)
- Token generation and secure storage
- Email template (FluidEmail or custom)
- Verification endpoint
- User enumeration prevention on the request endpoint
- Testing of email delivery in CI

Decision
========

**Defer magic link to v0.2. Ship v0.1 with password fallback + recovery codes.**

v0.1 scope:

- Password fallback (free, felogin already handles it)
- Recovery codes (self-contained, no external dependencies)

v0.2 scope:

- ``MagicLinkService`` implementation
- ``RecoveryController`` magic link endpoints
- FluidEmail template for magic link email
- ``MagicLinkRequestedEvent`` for custom email rendering
- Site configuration for enabling/disabling magic link

The event class (``MagicLinkRequestedEvent``) and service are deferred entirely to
v0.2. They are **not** shipped in v0.1 to avoid shipping dead code.

Consequences
============

**Positive:**

- Smaller v0.1 scope, faster to ship
- Password + recovery codes already cover the critical recovery paths
- v0.2 can focus on polish: magic link + admin dashboard + adoption charts

**Negative:**

- Sites that disable password login AND lose recovery codes have no v0.1 fallback
- Users expecting magic link from day one will be disappointed

**Mitigation:**

- ``Enforced`` enforcement level (which blocks passwords) should not be used until
  v0.2 ships magic link. Documentation will warn about this.
- Recovery codes are prominently presented at enrollment to ensure users save them
- Admin unlock capability (via admin module) as emergency escape hatch

Alternatives Considered
=======================

**Ship all three in v0.1:** Would delay the MVP by 1-2 weeks for email template
design, mail testing infrastructure, and user enumeration prevention on the request
endpoint. The benefit is marginal since password + recovery codes are sufficient for
the initial rollout.
