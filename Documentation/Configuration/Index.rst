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
    user. Turn it off to show a username field and require a username
    before a passkey is accepted.

    WebAuthn Conditional UI, where the browser offers the passkey in a
    username field's autofill menu, is armed only when discoverable login
    is on *and* the login container holds a field named
    ``nr_passkeys_username``. The extension's templates render that field
    only with discoverable login off, so they never arm it; a template
    override that adds the field with discoverable login on does.

..  confval:: settings.showPasswordFallback

    :type: boolean
    :Default: enabled

    Show a "Use password instead" link next to the recovery code link. The
    link points to :confval:`settings.passwordLoginPage`; without that page
    no link is shown, whatever this switch says.

..  confval:: settings.passwordLoginPage

    :type: page
    :Default: none

    The page with the password login, usually a felogin plugin. It is
    linked only when all of these hold, and ignored otherwise:

    - the page belongs to the site the plugin is rendered on;
    - TYPO3 builds a link to it for a visitor who is not logged in (not for
      a hidden or access-restricted page);
    - that link stays on the site: a path on the current host, or an
      ``http``/``https`` URL on the host of the site's base or of one of its
      languages. An external-URL page or a shortcut to another host is
      ignored even though the page itself belongs to the site.

..  confval:: settings.redirectAfterLogin

    :type: page
    :Default: none (the visitor stays on the current page)

    The page a successful passkey login leads to: the plugin's hidden login
    form posts to it, so the one-time login token only ever goes to a URL
    that passed these checks. The rules of
    :confval:`settings.passwordLoginPage` apply, with one difference: a
    page only logged-in visitors may see is linked, because the visitor is
    logged in when the form arrives. Hosts are compared, not sites, so a
    second site on one of this site's hosts counts as on-site. Where felogin
    sits on the same page, the login is completed through felogin's form
    and felogin's own redirect settings apply instead.

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
