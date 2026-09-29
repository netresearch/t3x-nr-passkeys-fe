..  include:: ../Includes.rst.txt

..  _configuration:

=============
Configuration
=============

Configuration happens at three levels:

1. **Extension settings** -- Global defaults (algorithm, challenge TTL,
   rate limiting)
2. **Site configuration** -- Per-site RP ID and origin
3. **TypoScript** -- Plugin view settings and page UIDs
4. **Plugin FlexForm** -- Per-plugin switches on the content element

Plugin FlexForm
===============

The login plugin carries these settings on the content element itself, on
its :guilabel:`Plugin` tab:

..  confval:: settings.discoverableEnabled

    :type: boolean
    :Default: enabled

    Allow login without entering a username: the passkey identifies the
    user. This also switches WebAuthn Conditional UI — with it enabled the
    browser offers the passkey directly in the username field's autofill
    menu, which is the entry point most returning users reach for. Turn it
    off to show a username field and require a username before a passkey
    is accepted.

..  confval:: settings.showPasswordFallback

    :type: boolean
    :Default: enabled

    Show a "Use password instead" link next to the recovery code link. The
    link points to :confval:`settings.passwordLoginPage`; without that page
    no link is shown, whatever this switch says.

..  confval:: settings.passwordLoginPage

    :type: page
    :Default: none

    The page with the password login, usually a felogin plugin. Only a
    page of the site the plugin is rendered on is linked; a page of another
    site, or one TYPO3 does not link to (hidden, access-restricted), is
    ignored.

..  confval:: settings.redirectAfterLogin

    :type: page
    :Default: none (the visitor stays on the current page)

    The page a successful passkey login leads to. The same restriction as
    for :confval:`settings.passwordLoginPage` applies: only a page of the
    current site is used, and the link is built by TYPO3 from the page, so
    the setting cannot send a visitor off-site. Where felogin sits on the
    same page, the login is completed through felogin's form and felogin's
    own redirect settings apply instead.

..  confval:: settings.cssClass

    :type: string
    :Default: empty

    Additional CSS class on the plugin's outer element. The management and
    enrollment plugins carry this field too; it is their only setting.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    ExtensionSettings
    SiteConfiguration
    TypoScript
