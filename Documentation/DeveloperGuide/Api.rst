..  include:: ../Includes.rst.txt

..  _api-reference:

eID API Reference
=================

All frontend API endpoints are handled by the eID dispatcher at
``/?eID=nr_passkeys_fe``. Request bodies are JSON. All responses are
JSON with ``Content-Type: application/json``.

Authentication endpoints (public)
----------------------------------

These endpoints do not require a frontend session.

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=login/options

Request challenge options for passkey login.

**Request:**

..  code-block:: json

    {
        "username": "johndoe"
    }

For discoverable login, omit ``username``.

**Response (200):**

..  code-block:: json

    {
        "challenge": "...",
        "challengeToken": "...",
        "rpId": "example.com",
        "allowCredentials": []
    }

----

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=login/verify

Verify a passkey assertion and create a frontend session.

**Request:**

..  code-block:: json

    {
        "assertion": {
            "id": "...",
            "type": "public-key",
            "response": {
                "clientDataJSON": "...",
                "authenticatorData": "...",
                "signature": "...",
                "userHandle": "..."
            }
        },
        "challengeToken": "..."
    }

**Response (200):**

..  code-block:: json

    {
        "success": true,
        "redirect": "/my-account/"
    }

Recovery code endpoint (public)
---------------------------------

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=login/recovery

Login using a one-time recovery code.

**Request:**

..  code-block:: json

    {
        "username": "johndoe",
        "recoveryCode": "XXXXX-YYYYY"
    }

**Response (200):**

..  code-block:: json

    {
        "success": true,
        "redirect": "/my-account/"
    }

Enrollment endpoints (requires session)
-----------------------------------------

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=enrollment/options

Request challenge options for passkey enrollment. Requires an active
frontend session.

**Response (200):**

..  code-block:: json

    {
        "challenge": "...",
        "challengeToken": "...",
        "rp": {"id": "example.com", "name": "My Site"},
        "user": {"id": "...", "name": "johndoe", "displayName": "John Doe"},
        "pubKeyCredParams": [{"type": "public-key", "alg": -7}]
    }

----

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=enrollment/verify

Verify an attestation and save the new credential.

**Request:**

..  code-block:: json

    {
        "attestation": {
            "id": "...",
            "type": "public-key",
            "response": {
                "clientDataJSON": "...",
                "attestationObject": "..."
            }
        },
        "challengeToken": "...",
        "name": "My MacBook"
    }

**Response (200):**

..  code-block:: json

    {
        "success": true,
        "credentialId": "..."
    }

Management endpoints (requires session)
-----------------------------------------

.. code-block:: text

    GET /?eID=nr_passkeys_fe&action=management/list

Returns the list of passkeys for the current user.

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=management/rename

**Request:** ``{"credentialId": "...", "name": "New Name"}``

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=management/remove

**Request:** ``{"credentialId": "..."}``

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=management/recovery-codes/generate

Generates a new set of 10 recovery codes. Returns the plaintext codes
(shown once only).

Admin endpoints (requires admin session)
------------------------------------------

.. code-block:: text

    GET /?eID=nr_passkeys_fe&action=admin/list&feUserUid=<uid>
    POST /?eID=nr_passkeys_fe&action=admin/revoke
    POST /?eID=nr_passkeys_fe&action=admin/revoke-all
    POST /?eID=nr_passkeys_fe&action=admin/unlock
    POST /?eID=nr_passkeys_fe&action=admin/update-enforcement

Admin endpoints require a valid TYPO3 backend session with admin
privileges. They are used by the backend admin module JavaScript.

Error responses
---------------

All error responses follow this format:

..  code-block:: json

    {
        "success": false,
        "error": "Human-readable error message",
        "code": "ERROR_CODE"
    }

Common HTTP status codes:

=====  ==========================================
Code   Meaning
=====  ==========================================
400    Invalid request (missing/malformed fields)
401    Not authenticated (session required)
403    Forbidden (insufficient privileges)
429    Rate limit exceeded
=====  ==========================================
