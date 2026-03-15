..  include:: ../Includes.rst.txt

..  _extension-settings:

Extension Settings
==================

Global extension settings are managed in
:guilabel:`Admin Tools > Settings > Extension Configuration > nr_passkeys_fe`.

..  note::

    The FE extension reuses the relying party, challenge TTL, rate
    limiting, and algorithm settings from ``nr_passkeys_be``. Per-site
    RP ID override is configured in the site configuration
    (see :ref:`site-configuration`).

Challenge settings
------------------

..  confval:: challengeTtlSeconds

   :type: int
   :Default: ``120``

   Time-to-live for challenge tokens in seconds. After expiry the user
   must request a new challenge. 120 seconds is sufficient for most
   authenticators.

Rate limiting
-------------

..  confval:: rateLimitMaxAttempts

   :type: int
   :Default: ``10``

   Maximum requests allowed per IP per endpoint within the rate limit
   window. Exceeding this limit returns HTTP 429.

..  confval:: rateLimitWindowSeconds

   :type: int
   :Default: ``300``

   Duration of the rate limiting window in seconds. The counter resets
   after this period.

Account lockout
---------------

..  confval:: lockoutThreshold

   :type: int
   :Default: ``5``

   Number of consecutive failed authentication attempts before the
   account is temporarily locked. Applies per username/IP.

..  confval:: lockoutDurationSeconds

   :type: int
   :Default: ``900``

   Duration of the account lockout in seconds (default: 15 minutes).
   Administrators can unlock accounts manually from the backend module.

Cryptographic algorithms
------------------------

..  confval:: allowedAlgorithms

   :type: string
   :Default: ``ES256``

   Comma-separated list of allowed signing algorithms. Supported values:

   - ``ES256`` -- ECDSA with SHA-256 (recommended)
   - ``ES384`` -- ECDSA with SHA-384
   - ``ES512`` -- ECDSA with SHA-512
   - ``RS256`` -- RSA with SHA-256

   Example for multiple algorithms: ``ES256,RS256``

User verification
-----------------

..  confval:: userVerification

   :type: string
   :Default: ``required``

   The user verification requirement for WebAuthn ceremonies:

   - ``required`` -- Authenticator must verify the user (biometric or
     PIN). Most secure option.
   - ``preferred`` -- Verify if possible; proceed without if not.
   - ``discouraged`` -- Skip user verification for fastest flow.

   Invalid values fall back to ``required``.
