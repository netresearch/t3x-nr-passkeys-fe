..  include:: ../Includes.rst.txt

..  _threat-model:

Threat Model
============

This page describes the primary threats and the mitigations
implemented in the extension.

Phishing
--------

**Threat:** An attacker creates a fake login page to steal credentials.

**Mitigation:** WebAuthn binds the credential to the Relying Party ID
(domain). A passkey registered for ``example.com`` cannot be used on
``evil.example.com``. The browser enforces this binding; no user action
is required.

Credential theft via database breach
--------------------------------------

**Threat:** Attacker reads the credential database and impersonates
users.

**Mitigation:** The database stores only public keys. The corresponding
private key never leaves the authenticator device and cannot be
extracted. A leaked public key is useless for authentication.

Recovery code theft
-------------------

**Threat:** Attacker reads stored recovery codes and uses them.

**Mitigation:** Recovery codes are stored as bcrypt hashes (cost 12).
Brute-forcing the hash is computationally infeasible. Users should
store plaintext codes offline in a password manager.

Challenge replay
----------------

**Threat:** Attacker intercepts a challenge/assertion and replays it.

**Mitigation:** Each challenge token includes a nonce stored in the
TYPO3 session. A nonce can be verified exactly once (``TYPO3 rate
limiter`` + session check). The challenge has a 120-second TTL.

Brute-force / DoS
-----------------

**Threat:** Attacker floods the authentication endpoints.

**Mitigation:**

- Rate limiting per IP per endpoint (configurable, default 10 req/5 min)
- Account lockout after configurable failed attempts (default 5)
- HTTP 429 responses with ``Retry-After`` header

User enumeration
----------------

**Threat:** Attacker discovers valid usernames by observing differing
error responses.

**Mitigation:** The ``login/options`` endpoint returns the same
response structure regardless of whether the username exists. The
``user_handle`` field in stored credentials is a SHA-256 hash of the
UID + encryption key, not the username.

Session fixation
----------------

**Threat:** Attacker fixes a session ID and then authenticates to take
over the session.

**Mitigation:** TYPO3's built-in session management regenerates the
session ID after authentication. The extension does not bypass this.

CSRF
----

**Threat:** Attacker tricks an authenticated user into performing
unwanted actions.

**Mitigation:** Management endpoints require a valid frontend session
cookie. The eID endpoint validates the ``Content-Type: application/json``
header, which browsers do not set for cross-origin form submissions.

HTTPS requirement
-----------------

WebAuthn operations are only allowed on secure origins (HTTPS or
``localhost``). Any attempt to use the passkey API over plain HTTP will
be rejected by the browser before reaching the server.

..  important::

    Ensure your TYPO3 installation is running over HTTPS in production.
    Set ``reverseProxySSL`` if behind a load balancer.
