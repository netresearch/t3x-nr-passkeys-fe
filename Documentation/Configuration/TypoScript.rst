..  include:: ../Includes.rst.txt

..  _typoscript-reference:

TypoScript Reference
====================

The extension provides TypoScript constants and setup for the plugin view
paths, the default of the discoverable login switch and the default CSS.

Constants
---------

..  code-block:: typoscript
    :caption: Available TypoScript constants

    plugin.tx_nrpasskeysfe.settings.discoverableEnabled = 1
    plugin.tx_nrpasskeysfe.settings.css.includeDefault = 1

..  confval:: plugin.tx_nrpasskeysfe.settings.discoverableEnabled

   :type: boolean
   :Default: ``1``

   Discoverable (usernameless) login. It is the default for the login
   plugin, whose FlexForm switch overrides it per content element, and the
   setting the felogin integration follows: with ``0`` the passkey tab of
   the felogin form shows a username field and asks for the username
   before a passkey is accepted.

..  confval:: plugin.tx_nrpasskeysfe.settings.css.includeDefault

   :type: boolean
   :Default: ``1``

   Load :file:`passkey-fe.css` with the login, management and enrollment
   plugins and with the felogin integration. See
   :ref:`typoscript-disabling-css`.

The enrollment page the post-login interstitial redirects to is a site
setting (:confval:`nr_passkeys_fe.enrollmentPageUrl`), not a TypoScript
constant.

Setup
-----

The setup configures view paths for the Fluid templates:

..  code-block:: typoscript
    :caption: EXT:nr_passkeys_fe/Configuration/TypoScript/setup.typoscript

    plugin.tx_nrpasskeysfe {
        view {
            templateRootPaths.0 = EXT:nr_passkeys_fe/Resources/Private/Templates/
            partialRootPaths.0 = EXT:nr_passkeys_fe/Resources/Private/Partials/
            layoutRootPaths.0 = EXT:nr_passkeys_fe/Resources/Private/Layouts/
        }
        settings {
            discoverableEnabled = {$plugin.tx_nrpasskeysfe.settings.discoverableEnabled}
            css.includeDefault = {$plugin.tx_nrpasskeysfe.settings.css.includeDefault}
        }
    }

    plugin.tx_felogin_login.view.templateRootPaths.100 = EXT:nr_passkeys_fe/Resources/Private/Templates/Felogin/

    plugin.tx_felogin_login.settings.passkeys {
        discoverableEnabled = {$plugin.tx_nrpasskeysfe.settings.discoverableEnabled}
        css.includeDefault = {$plugin.tx_nrpasskeysfe.settings.css.includeDefault}
    }

The felogin template override receives only felogin's own settings, so the
two constants are handed to it under ``plugin.tx_felogin_login.settings.passkeys``.

Overriding templates
--------------------

To override a template, add a custom path at a higher index:

..  code-block:: typoscript

    plugin.tx_nrpasskeysfe {
        view {
            templateRootPaths.10 = EXT:my_site/Resources/Private/Templates/NrPasskeysFe/
        }
    }

Then create the template in the same directory structure, e.g.:
:file:`EXT:my_site/Resources/Private/Templates/NrPasskeysFe/Login/Index.html`

..  _typoscript-disabling-css:

Disabling default CSS
---------------------

To include your own styles instead of the extension's default CSS, set the
constant, which covers the plugins and the felogin integration:

..  code-block:: typoscript

    plugin.tx_nrpasskeysfe.settings.css.includeDefault = 0
