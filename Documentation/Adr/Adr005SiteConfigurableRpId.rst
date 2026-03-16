.. include:: /Includes.rst.txt

======================================================================
ADR-005: Site-Configurable RP ID with Storage PID Credential Isolation
======================================================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

WebAuthn credentials are bound to the Relying Party ID (RP ID), which is effectively
the domain. Unlike the TYPO3 backend (single domain), the frontend can serve multiple
domains. Existing WebAuthn extensions (e.g., ``bnf/mfa-webauthn``) document explicit
HTTPS/single-domain limitations.

Additionally, TYPO3 supports multiple FE user pools via storage PIDs (page IDs where
fe_users are stored). A credential registered on ``shop.example.com`` (storage PID 42)
must not authenticate a user on ``portal.example.com`` (storage PID 99), even if both
happen to share the same RP ID.

Decision
========

**Site-configurable RP ID + storage PID credential isolation.**

1. **Default RP ID:** Derived from the TYPO3 site configuration's base URL domain.
   Zero configuration needed for single-domain sites.

2. **Override RP ID:** Configurable per site in ``settings.yaml``:

   .. code-block:: yaml

      nr_passkeys_fe:
        rpId: 'example.com'

   This allows ``shop.example.com`` and ``blog.example.com`` to share credentials
   when the RP ID is set to the parent domain ``example.com`` (permitted by WebAuthn spec).

3. **Storage PID scoping:** Every credential query includes the storage PID and
   site identifier. The ``FrontendCredentialRepository`` enforces this at the query level.

4. **Credential-ID-to-UID resolution:** Always resolves by ``fe_user.uid``, never by
   ``username``. See ADR-008.

Consequences
============

**Positive:**

- Zero-config for 90% of sites (single domain)
- Multi-subdomain support via RP ID override
- Storage PID isolation prevents cross-pool credential leakage
- Follows WebAuthn spec for RP ID parent domain rule

**Negative:**

- Admins must understand WebAuthn RP ID rules for multi-domain setups
- RP ID changes invalidate all existing credentials (WebAuthn spec limitation)
- Storage PID adds WHERE clause to all credential queries (negligible performance impact)

**Mitigation:**

- Documentation with clear examples for single-domain, multi-subdomain, and multi-site
- Admin module shows RP ID per credential for debugging
- Warning in admin module if RP ID mismatch detected

Alternatives Considered
=======================

**Single domain only (Option A):** Too restrictive for real-world TYPO3 installations
with multi-site setups.

**No storage PID scoping:** Dangerous. TYPO3-SA-2024-006 demonstrated that ambiguous
user resolution across storage folders creates security vulnerabilities.
