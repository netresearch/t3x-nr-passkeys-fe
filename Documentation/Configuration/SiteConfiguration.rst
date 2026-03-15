..  include:: ../Includes.rst.txt

..  _site-configuration:

Site Configuration
==================

Each TYPO3 site can have an independent relying party configuration.
This is essential for multi-site installations where different domains
need separate WebAuthn origins.

Settings are added to the site's :file:`config.yaml` file or via the
:guilabel:`Sites` module in the TYPO3 backend.

..  code-block:: yaml
    :caption: config/sites/my-site/config.yaml

    passkeys:
      rpId: 'example.com'
      rpName: 'My Example Site'
      origin: 'https://example.com'
      enforcementLevel: 'off'
      gracePeriodDays: 14

..  confval:: passkeys.rpId

   :type: string
   :Default: *(auto-detected from HTTP_HOST)*

   The WebAuthn Relying Party identifier. Must match the domain of the
   site. Use just the domain name, not the full URL.

   ..  important::

      Once passkeys are registered against a specific ``rpId``, changing
      it invalidates all existing registrations. Users must re-enroll.

..  confval:: passkeys.rpName

   :type: string
   :Default: ``TYPO3 Frontend``

   Human-readable name shown to users during passkey registration in
   the browser dialog.

..  confval:: passkeys.origin

   :type: string
   :Default: *(auto-detected from request)*

   The expected WebAuthn origin (e.g. ``https://example.com``). Must
   include the scheme and port if non-standard. Leave empty for
   auto-detection.

..  confval:: passkeys.enforcementLevel

   :type: string
   :Default: ``off``

   The site-level enforcement level. Valid values:

   - ``off`` -- Passkeys are optional; no prompts or interstitials.
   - ``encourage`` -- Users without passkeys see a dismissible banner.
   - ``required`` -- Users without passkeys see an enrollment
     interstitial after login. Skippable during the grace period.
   - ``enforced`` -- Users without passkeys cannot bypass the
     interstitial. Grace period skipping is disabled.

   Per-group enforcement can override this for specific user groups
   (strictest level wins). See :ref:`enforcement`.

..  confval:: passkeys.gracePeriodDays

   :type: int
   :Default: ``14``

   Number of days before the grace period expires for users who can
   skip the enrollment interstitial. Applies when the level is
   ``required``. Has no effect for ``enforced``.

Multi-site example
------------------

For a multi-site installation with different enforcement levels:

..  code-block:: yaml
    :caption: config/sites/main-site/config.yaml (strict)

    passkeys:
      rpId: 'company.example'
      rpName: 'Company Portal'
      origin: 'https://company.example'
      enforcementLevel: 'enforced'
      gracePeriodDays: 0

..  code-block:: yaml
    :caption: config/sites/public-site/config.yaml (soft rollout)

    passkeys:
      rpId: 'www.example.com'
      rpName: 'Public Site'
      origin: 'https://www.example.com'
      enforcementLevel: 'encourage'
      gracePeriodDays: 30

See :ref:`multi-site` for details on cross-domain passkey handling.
