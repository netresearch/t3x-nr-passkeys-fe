..  include:: ../Includes.rst.txt

..  _typoscript-reference:

TypoScript Reference
====================

The extension provides TypoScript constants and setup for configuring
plugin paths and page UIDs for redirects.

Constants
---------

..  code-block:: typoscript
    :caption: Available TypoScript constants

    plugin.tx_nrpasskeysfe.settings.loginPageUid = 0
    plugin.tx_nrpasskeysfe.settings.managementPageUid = 0
    plugin.tx_nrpasskeysfe.settings.enrollmentPageUid = 0

..  confval:: plugin.tx_nrpasskeysfe.settings.loginPageUid

   :type: int
   :Default: ``0``

   Page UID of the page containing the NrPasskeysFe:Login plugin.
   Used for redirect after logout and for the enrollment interstitial
   "back to login" link.

..  confval:: plugin.tx_nrpasskeysfe.settings.managementPageUid

   :type: int
   :Default: ``0``

   Page UID of the page containing the NrPasskeysFe:Management plugin.
   Used for redirect after successful enrollment.

..  confval:: plugin.tx_nrpasskeysfe.settings.enrollmentPageUid

   :type: int
   :Default: ``0``

   Page UID of the page containing the NrPasskeysFe:Enrollment plugin.
   Required when enforcement is active. After login, users without a
   passkey are redirected here.

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
            loginPage = {$plugin.tx_nrpasskeysfe.settings.loginPageUid}
            managementPage = {$plugin.tx_nrpasskeysfe.settings.managementPageUid}
            enrollmentPage = {$plugin.tx_nrpasskeysfe.settings.enrollmentPageUid}
            css.includeDefault = 1
        }
    }

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

Disabling default CSS
---------------------

To include your own styles instead of the extension's default CSS:

..  code-block:: typoscript

    plugin.tx_nrpasskeysfe.settings.css.includeDefault = 0
