.. include:: /Includes.rst.txt

==========================================
ADR-010: Recovery Codes Hashed with bcrypt
==========================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

Recovery codes are a fallback authentication mechanism. When a user generates
recovery codes, the system stores them and the user saves the plaintext codes
offline. At login time, the user enters a code which must be verified against
the stored value.

The storage format must be:

1. One-way (codes cannot be recovered from the database)
2. Resistant to brute force (if database is compromised)
3. Verifiable at login time

Options:

A. **Plaintext storage** — Trivial, but any database leak exposes all codes
B. **SHA-256 hash** — Fast, but vulnerable to rainbow tables / brute force
C. **bcrypt hash** — Intentionally slow, resistant to brute force
D. **Argon2id hash** — Modern, memory-hard, strongest protection

Decision
========

**Option C: bcrypt with cost factor 12.**

Each recovery code is stored as a bcrypt hash in ``tx_nrpasskeysfe_recovery_code.code_hash``.

Code format: ``XXXX-XXXX`` (8 alphanumeric characters, grouped for readability).
This gives 36^8 ≈ 2.8 trillion possible codes, making brute force infeasible even
with fast hashing.

.. code-block:: php

   // Generation
   $plaintext = $this->generateRandomCode(); // e.g., "A7K2-M9P4"
   $hash = password_hash($plaintext, PASSWORD_BCRYPT, ['cost' => 12]);

   // Verification
   $valid = password_verify($inputCode, $storedHash);

Consequences
============

**Positive:**

- Industry standard for credential storage
- Cost factor 12 makes brute force impractical (~250ms per hash on modern hardware)
- ``password_hash()`` / ``password_verify()`` are PHP built-ins, no dependencies
- Automatic salt generation per hash

**Negative:**

- ~250ms per verification attempt (acceptable for login, rate-limited anyway)
- Cannot bulk-verify codes (each hash is unique due to salt)
- bcrypt has 72-byte input limit (not an issue for 8-char codes)

**Mitigation:**

- Rate limiting on recovery code verification endpoint
- Account lockout after N failed recovery code attempts
- Codes are single-use (marked ``used_at`` after successful verification)

Alternatives Considered
=======================

**Option A (Plaintext):** Unacceptable for any credential storage.

**Option B (SHA-256):** Too fast. An attacker with the database could brute-force
all possible 8-character codes in minutes on a GPU.

**Option D (Argon2id):** Stronger than bcrypt, but requires ``libargon2`` which may
not be available on all PHP installations. bcrypt is universally available and
sufficient for recovery codes with rate limiting.
