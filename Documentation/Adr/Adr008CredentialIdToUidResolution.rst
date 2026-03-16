.. include:: /Includes.rst.txt

=======================================================
ADR-008: Credential-ID-to-UID Resolution (Not Username)
=======================================================

:Status: Accepted
:Date: 2026-03-14
:Decision-makers: Sebastian Mendel

Context
=======

When a passkey assertion is received (especially in discoverable login mode), the
system must resolve which fe_user the credential belongs to. There are two approaches:

A. Resolve by ``credential_id`` → ``fe_user.uid`` (direct UID lookup)
B. Resolve by ``credential_id`` → ``username`` → ``fe_user`` (username lookup)

TYPO3 has a known security issue with username-based FE authentication across
multiple storage folders. TYPO3-SA-2024-006 addressed a vulnerability where
ambiguous usernames in different storage folders could lead to authentication
bypass or privilege escalation.

Decision
========

**Always resolve by credential_id → fe_user.uid.**

The ``FrontendCredentialRepository.findByCredentialId()`` returns the ``fe_user``
UID directly from the credential record. The auth service then loads the fe_user
by UID, not by username.

Additionally, all credential queries include the ``storage_pid`` to prevent
cross-pool resolution:

.. code-block:: php

   $credential = $this->credentialRepository->findByCredentialId(
       $credentialId,
       $storagePid  // Always scoped
   );

   if ($credential === null) {
       return null; // Unknown credential
   }

   // Load fe_user by UID, not username
   $feUser = $this->loadFeUserByUid($credential->getFeUser());

Consequences
============

**Positive:**

- Eliminates username ambiguity vulnerability entirely
- Storage PID scoping prevents cross-pool credential leakage
- Simpler and faster (single indexed lookup vs. username search)
- Consistent with WebAuthn spec (credentials are identified by ID, not username)

**Negative:**

- If ``fe_user.uid`` changes (e.g., import/migration), credentials break
- Cannot use TYPO3's built-in username resolution logic

**Mitigation:**

- fe_user UIDs rarely change in practice
- Migration documentation for credential re-linking if UIDs change
- Admin module shows credential-to-user mapping for debugging

Alternatives Considered
=======================

**Option B (Username resolution):** Vulnerable to the same class of bugs that
TYPO3-SA-2024-006 addressed. Multiple fe_users with the same username in
different storage folders would create ambiguity. Rejected.
