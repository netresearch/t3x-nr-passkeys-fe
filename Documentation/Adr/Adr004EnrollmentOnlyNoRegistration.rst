.. include:: /Includes.rst.txt

==============================================
ADR-004: Enrollment Only, No User Registration
==============================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

TYPO3 core has no built-in FE user registration. Extensions like ``femanager`` and
``sf_register`` fill this gap. The question is whether ``nr_passkeys_fe`` should
include its own registration flow (create new fe_user + passkey in one step).

Three approaches were considered:

A. **Enrollment only** — Passkeys for existing fe_users, PSR-14 hooks for integrations
B. **Enrollment + basic registration** — Minimalist registration form
C. **Enrollment + registration hooks** — No form, but callable service

Decision
========

**Option A with Option C's hooks.**

- Only existing fe_users can enroll passkeys through the management plugin or
  post-login enrollment flow.
- A ``PasskeyEnrollmentService`` is provided as a public service that registration
  extensions can call programmatically.
- PSR-14 events (``BeforePasskeyEnrollmentEvent``, ``AfterPasskeyEnrollmentEvent``)
  allow third-party extensions to hook into the enrollment lifecycle.

Consequences
============

**Positive:**

- Focused scope: no competition with femanager/sf_register
- Clean separation of concerns (registration vs. authentication)
- PSR-14 events enable any registration extension to add "Register with Passkey"
- Smaller codebase, fewer security surfaces

**Negative:**

- No out-of-box "Register with Passkey" experience
- Integrators must configure registration extensions separately
- Higher setup effort for new installations

**Mitigation:**

- Documentation includes integration guides for femanager and sf_register
- ``PasskeyEnrollmentService`` has a simple API: ``enroll(int $feUserUid, ...): FrontendCredential``
- Example code in developer guide for calling enrollment from custom registration forms

Alternatives Considered
=======================

**Option B (Basic registration):** Tempting for simplicity, but any registration form
immediately requires: email verification, CAPTCHA, terms acceptance, profile fields,
GDPR consent. This is a rabbit hole that existing extensions handle better.
