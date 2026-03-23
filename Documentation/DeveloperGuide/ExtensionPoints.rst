..  include:: ../Includes.rst.txt

..  _extension-points:

Extension Points
================

Beyond PSR-14 events, the extension provides several extension points.

Overriding Fluid templates
--------------------------

All frontend output is rendered via Fluid templates located in
``EXT:nr_passkeys_fe/Resources/Private/``. Override any template by
registering a higher-priority path:

..  code-block:: typoscript

    plugin.tx_nrpasskeysfe {
        view {
            templateRootPaths.10 = EXT:my_ext/Resources/Private/Templates/NrPasskeysFe/
            partialRootPaths.10 = EXT:my_ext/Resources/Private/Partials/NrPasskeysFe/
            layoutRootPaths.10 = EXT:my_ext/Resources/Private/Layouts/NrPasskeysFe/
        }
    }

Available templates:

- ``Login/Index.html`` -- Passkey login form
- ``Login/Recovery.html`` -- Recovery code login form
- ``Enrollment/Index.html`` -- Passkey enrollment form
- ``Enrollment/Success.html`` -- Post-enrollment success page
- ``Management/Index.html`` -- Self-service management panel
- ``Management/RecoveryCodes.html`` -- Recovery code generation
- ``AdminModule/Index.html`` -- Backend admin module shell

Replacing services
------------------

All services are registered in ``Configuration/Services.yaml`` with
standard Symfony DI. To replace a service, add an alias in your
extension's ``Services.yaml``:

..  code-block:: yaml

    # Override the enforcement service
    Netresearch\NrPasskeysFe\Service\FrontendEnforcementService:
      class: MyVendor\MyExt\Service\CustomEnforcementService

Custom enforcement via event
----------------------------

The recommended approach for custom enforcement logic is to listen to
``EnforcementLevelResolvedEvent`` and call ``setEffectiveLevel()``:

..  code-block:: php

    use Netresearch\NrPasskeysFe\Event\EnforcementLevelResolvedEvent;

    final class CustomEnforcementListener
    {
        public function __invoke(EnforcementLevelResolvedEvent $event): void
        {
            // Exempt staff members from enforcement
            if ($this->isStaffMember($event->feUserUid)) {
                $event->setEffectiveLevel('off');
            }
        }
    }

Adding custom eID endpoints
---------------------------

The extension's eID dispatcher (``EidDispatcher``) is registered at
``eID=nr_passkeys_fe``. It routes requests to controllers based on
the ``action`` POST parameter.

To add custom eID actions without modifying the extension, listen to
``BeforePasskeyAuthenticationEvent`` for pre-auth hooks, or register
your own eID handler in ``ext_localconf.php``:

..  code-block:: php

    $GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['my_passkey_ext']
        = \MyVendor\MyExt\Eid\MyEidHandler::class . '::handle';

JavaScript module overrides
---------------------------

The extension's JavaScript modules are loaded as TYPO3 ES modules
(``@netresearch/nr-passkeys-fe/*``). To customize behavior, extend the
module via ``@typo3/core/event/regular-event.js`` hooks or override the
import map in ``Configuration/JavaScriptModules.php``.

..  note::

    Overriding JavaScript modules is an advanced technique. Prefer
    CSS overrides and template overrides for UI customization.

PasskeyUtils.js shared module
-------------------------------

All frontend JavaScript modules share a common utility module,
``PasskeyUtils.js``, exposed as ``window.NrPasskeysFe``. It provides:

- **base64url encoding/decoding** -- ``base64urlToBuffer()`` and
  ``bufferToBase64url()`` for converting between WebAuthn binary
  formats and JSON-safe strings.
- **DOM helpers** -- ``showError()``, ``showStatus()``,
  ``showLoading()`` for consistent UI state management across the
  login, enrollment, management, and recovery modules.
- **URL validation** -- Shared URL handling utilities.

``PasskeyUtils.js`` is loaded before the module-specific scripts via
``f:asset.script`` in Fluid templates. If you override templates, ensure
``PasskeyUtils.js`` is still included before other passkey scripts.

The eight JavaScript modules are:

- ``PasskeyLogin.js`` -- Login form and WebAuthn assertion flow
- ``PasskeyEnrollment.js`` -- Enrollment ceremony
- ``PasskeyManagement.js`` -- Credential list, rename, remove
- ``PasskeyRecovery.js`` -- Recovery code login form
- ``PasskeyRecoveryCodes.js`` -- Recovery code generation UI
- ``PasskeyBanner.js`` -- Encourage-level dismissible banner
- ``PasskeyFeAdmin.js`` -- Backend admin module
- ``PasskeyUtils.js`` -- Shared utilities (described above)
