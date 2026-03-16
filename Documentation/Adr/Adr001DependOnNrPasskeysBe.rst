.. include:: /Includes.rst.txt

========================================================
ADR-001: Depend on nr-passkeys-be as Composer Dependency
========================================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

``nr_passkeys_fe`` needs WebAuthn ceremony implementation (registration/assertion),
HMAC-SHA256 challenge token generation with replay protection, and per-IP rate limiting
with account lockout. These are security-critical primitives already proven in
``netresearch/nr-passkeys-be``.

Three approaches were considered:

A. **nr-passkeys-be as composer dependency** — Reuse services directly
B. **Standalone extension** — Copy patterns, own code
C. **Shared library extraction** (nr-passkeys-core) — Extract common code into third package

Decision
========

**Option A: nr-passkeys-be as composer dependency.**

The FE extension requires ``netresearch/nr-passkeys-be`` and reuses:

- ``WebAuthnService`` — Registration/assertion ceremonies
- ``ChallengeService`` — HMAC-SHA256 signed challenge tokens with nonce
- ``RateLimiterService`` — Per-IP/endpoint rate limiting and lockout
- ``ExtensionConfigurationService`` — RP ID, origin, algorithm configuration

The FE extension does NOT reuse BE-specific classes (controllers, middleware, TCA,
credential repository, auth service).

Consequences
============

**Positive:**

- No duplication of security-critical code (~700 lines of WebAuthn + challenge + rate limit)
- Security fixes in BE propagate to FE automatically
- Single ``web-auth/webauthn-lib`` version across both extensions
- Faster initial development

**Negative:**

- BE extension installed even on FE-only sites (unused backend module, BE TCA)
- Version lock-step: FE releases may be blocked by BE version constraints
- BE service API changes can break FE

**Mitigation:**

- Plan for future extraction to ``nr-passkeys-core`` shared library (Option C)
- Define interface contracts for shared services to ease future refactoring
- Accept BE overhead as minimal (no runtime cost, only disk space)

Alternatives Considered
=======================

**Option B (Standalone):** Would require duplicating ~700 lines of security-critical
code. Any bug fix would need to be applied twice. Rejected for maintenance burden.

**Option C (Shared library):** Architecturally cleanest, but requires refactoring
nr-passkeys-be first. Deferred to a future release when both extensions are stable.
