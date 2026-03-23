..  include:: ../Includes.rst.txt

..  _api-reference:

eID API Reference
=================

All frontend API endpoints are handled by the eID dispatcher at
``/?eID=nr_passkeys_fe``. The ``action`` query parameter selects the
controller action. Request bodies are JSON. All responses are JSON with
``Content-Type: application/json``.

Token-based login flow
-----------------------

Both passkey login and recovery code login use a two-phase flow:

1. **eID verification** -- JavaScript calls the eID endpoint
   (``loginVerify`` or ``recoveryVerify``). The server verifies the
   assertion or recovery code and stores the authenticated
   ``fe_user`` UID in a short-lived cache entry
   (``nr_passkeys_fe_nonce`` cache, 2-minute TTL). The response
   includes a ``loginToken``.

2. **felogin form submission** -- JavaScript submits a standard
   ``logintype=login`` form to the current page, including the
   ``loginToken`` in a hidden field. TYPO3's normal FE authentication
   chain processes the request.

3. **Auth service** -- ``PasskeyFrontendAuthenticationService``
   (priority 80) reads the ``loginToken``, looks up the user UID in
   the cache, and returns the ``fe_users`` row. The token is consumed
   (one-time use).

This ensures users get a proper TYPO3 frontend session with all
middleware (enforcement interstitial, session regeneration) applied.

Authentication endpoints (public)
----------------------------------

These endpoints do not require a frontend session.

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=loginOptions

Request challenge options for passkey login.

**Request:**

..  code-block:: json

    {
        "username": "johndoe"
    }

For discoverable login, omit ``username`` or send an empty body.

**Response (200):**

..  code-block:: json

    {
        "options": {
            "challenge": "...",
            "rpId": "example.com",
            "allowCredentials": []
        },
        "challengeToken": "..."
    }

----

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=loginVerify

Verify a passkey assertion and issue a login token.

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
        "status": "ok",
        "feUserUid": 42,
        "loginToken": "abc123..."
    }

The ``loginToken`` is a one-time token valid for 2 minutes. The
JavaScript must submit it via a standard felogin form to complete the
login (see `Token-based login flow`_ above).

Recovery code endpoint (public)
---------------------------------

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=recoveryVerify

Login using a one-time recovery code.

**Request:**

..  code-block:: json

    {
        "username": "johndoe",
        "code": "XXXX-XXXX"
    }

**Response (200):**

..  code-block:: json

    {
        "status": "ok",
        "feUserUid": 42,
        "loginToken": "abc123..."
    }

The ``loginToken`` is consumed via felogin form submission, identical
to the passkey login flow.

Enrollment endpoints (requires session)
-----------------------------------------

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=registrationOptions

Request challenge options for passkey enrollment. Requires an active
frontend session.

**Response (200):**

..  code-block:: json

    {
        "options": {
            "challenge": "...",
            "rp": {"id": "example.com", "name": "My Site"},
            "user": {"id": "...", "name": "johndoe", "displayName": "John Doe"},
            "pubKeyCredParams": [{"type": "public-key", "alg": -7}]
        },
        "challengeToken": "..."
    }

----

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=registrationVerify

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
        "status": "ok",
        "credentialId": "..."
    }

Management endpoints (requires session)
-----------------------------------------

.. code-block:: text

    GET /?eID=nr_passkeys_fe&action=manageList

Returns the list of passkeys for the current user.

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=manageRename

**Request:** ``{"credentialId": "...", "name": "New Name"}``

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=manageRemove

**Request:** ``{"credentialId": "..."}``

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=recoveryGenerate

Generates a new set of 10 recovery codes. Returns the plaintext codes
(shown once only).

**Response (200):**

..  code-block:: json

    {
        "status": "ok",
        "codes": ["XXXX-XXXX", "..."],
        "count": 10
    }

Enrollment status endpoints (requires session)
------------------------------------------------

.. code-block:: text

    GET /?eID=nr_passkeys_fe&action=enrollmentStatus

Returns the current user's enrollment and enforcement status.

.. code-block:: text

    POST /?eID=nr_passkeys_fe&action=enrollmentSkip

Skips the enrollment interstitial (only available during grace period
when enforcement level is ``required``).

Action routing summary
-----------------------

The eID dispatcher routes the ``action`` query parameter to controllers:

=======================  ===================================  =========
Action                   Controller method                    Auth
=======================  ===================================  =========
``loginOptions``         LoginController::optionsAction       Public
``loginVerify``          LoginController::verifyAction        Public
``recoveryVerify``       RecoveryController::verifyAction     Public
``recoveryGenerate``     RecoveryController::generateAction   Session
``registrationOptions``  ManagementController::regOptions     Session
``registrationVerify``   ManagementController::regVerify      Session
``manageList``           ManagementController::listAction     Session
``manageRename``         ManagementController::renameAction   Session
``manageRemove``         ManagementController::removeAction   Session
``enrollmentStatus``     EnrollmentController::statusAction   Session
``enrollmentSkip``       EnrollmentController::skipAction     Session
=======================  ===================================  =========

Error responses
---------------

All error responses follow this format:

..  code-block:: json

    {
        "error": "Human-readable error message"
    }

Common HTTP status codes:

=====  ==========================================
Code   Meaning
=====  ==========================================
400    Invalid request (missing/malformed fields)
401    Not authenticated (session required)
403    Forbidden (insufficient privileges)
429    Rate limit exceeded
500    Internal server error
=====  ==========================================
