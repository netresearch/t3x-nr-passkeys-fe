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

The login plugin carries one switch on the content element itself:

..  confval:: settings.discoverableEnabled

    :type: boolean
    :Default: enabled

    Allow login without entering a username: the passkey identifies the
    user. This also switches WebAuthn Conditional UI — with it enabled the
    browser offers the passkey directly in the username field's autofill
    menu, which is the entry point most returning users reach for. Turn it
    off to require a username before a passkey is accepted.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    ExtensionSettings
    SiteConfiguration
    TypoScript
