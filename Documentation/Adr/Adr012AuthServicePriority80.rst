.. include:: /Includes.rst.txt

===========================================
ADR-012: Authentication Service Priority 80
===========================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

TYPO3's frontend authentication service chain processes services in descending priority
order. ``SaltedPasswordService`` runs at priority 50. Third-party extensions commonly
use various priorities:

- LDAP extensions: typically 80-90
- OAuth/OIDC extensions: typically 70-80
- SAML extensions: typically 80-90
- ``nr-passkeys-be``: priority 80

The passkey auth service must intercept login requests before the password service
but cooperate with other auth providers.

Decision
========

**Priority 80, matching nr-passkeys-be.**

The ``PasskeyFrontendAuthenticationService`` runs at priority 80:

- **Above** ``SaltedPasswordService`` (50) — passkey payloads are checked first
- **At same level as** ``nr-passkeys-be`` (80) — consistent behavior
- **Below** most LDAP/SAML providers (90+) — SSO providers take precedence

When the service receives a request **without** a passkey payload (``_type: "passkey"``),
it returns ``100`` (continue chain), passing control to the next service. This means:

- LDAP/SSO at 90: processes first, passkey at 80 only sees non-SSO requests
- Passkey at 80: checks for passkey payload, passes non-passkey requests to password
- Password at 50: handles traditional password authentication

Consequences
============

**Positive:**

- Consistent with nr-passkeys-be, reducing confusion
- SSO providers at 90+ take precedence (correct: if IdP handles auth, passkey is irrelevant)
- Non-passkey logins fall through cleanly to password service

**Negative:**

- Priority collision possible if another extension also uses 80
- TYPO3 does not guarantee order for same-priority services

**Mitigation:**

- Document the priority in extension settings (not currently configurable, but could be)
- The service's ``getUser()`` only activates when ``_type: "passkey"`` is present in
  ``loginData['uident']``, so a priority collision with a non-passkey service (e.g., LDAP)
  is harmless — both services check for their own payload type

Alternatives Considered
=======================

**Priority 90 (above LDAP):** Would intercept passkey payloads before LDAP, which is
correct, but could interfere with SSO flows that should take absolute precedence.

**Priority 60 (just above password):** Would work but wouldn't match nr-passkeys-be,
and would let more services process the request before passkeys are checked, adding
unnecessary latency for passkey logins.

**Configurable priority:** Over-engineering for a niche concern. If an integrator needs
a custom priority, they can override the service registration in ``ext_localconf.php``.
