..  include:: ../Includes.rst.txt

..  _webauthn-compliance:

WebAuthn Compliance
===================

The extension implements the W3C WebAuthn Level 2 specification and
the FIDO2 Client-to-Authenticator Protocol (CTAP2).

Implemented ceremonies
-----------------------

..  rst-class:: dl-parameters

Registration (attestation)
    The full attestation verification ceremony. Supported formats:
    none, packed, FIDO-U2F, Apple, TPM, and Android SafetyNet.
    The extension uses ``web-auth/webauthn-lib`` v5.x for all
    cryptographic verification.

Authentication (assertion)
    Full assertion verification including signature counter checks
    for authenticator clone detection.

Relying Party configuration
-----------------------------

- **rpId**: Domain-only (no scheme/port). Per-site configurable.
- **Origin**: Full URL including scheme. Verified against registered
  origins during ceremonies.
- **User verification**: Configurable (``required`` / ``preferred`` /
  ``discouraged``). Defaults to ``required``.

Challenge handling
------------------

Challenges are generated as 32-byte cryptographically random values
(``random_bytes(32)``), then wrapped in an HMAC-SHA256 signed token:

..  code-block:: text

    challengeToken = base64url(nonce || HMAC-SHA256(encryptionKey, nonce || challenge || timestamp))

The token includes:

- A nonce (replay protection -- each token can be used once)
- A timestamp (challenge TTL enforcement)
- HMAC signature (tampering detection)

The plaintext challenge is sent to the browser; the HMAC-signed token
is included in the eID response and must be returned verbatim during
verification.

Credential storage
------------------

Frontend credentials are stored in ``tx_nrpasskeysfe_credential``:

- ``credential_id`` -- WebAuthn credential ID (binary, indexed)
- ``public_key_cose`` -- COSE-encoded public key (binary blob)
- ``sign_count`` -- Usage counter for clone detection
- ``user_handle`` -- SHA-256(uid || encryptionKey) -- not the UID itself
- ``aaguid`` -- Authenticator AAGUID for device identification
- ``transports`` -- JSON transport hints

The ``user_handle`` is a one-way hash to prevent user enumeration
from credential lookups.

Recovery codes
--------------

Recovery codes are stored as bcrypt hashes (cost factor 12). Plain
text is generated server-side, displayed once to the user, then
immediately discarded. The bcrypt hash is stored. On verification,
the submitted code is compared against the hash in constant time.

See :ref:`ADR-010 <adr-010>` for the design decision.
