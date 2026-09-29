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

    - the page is a standard page (doktype 1), or a shortcut that TYPO3's own
      shortcut resolution leads to a standard page. External-URL and link
      pages, folders, spacers, mount points and every other page type are
      ignored, so no URL an editor types reaches the plugin. A "random
      subpage" shortcut is ignored as well;
    - that standard page belongs to the site the plugin is rendered on;
    - a visitor who is not logged in may see it: the page is unrestricted or
      set to "Hide at login", and so is every page above it that has
      "Extend to subpages" set;
    - the link TYPO3 built stays on the site: a path, or an ``http``/``https``
      URL whose scheme, host and port are those of the site's base or of one
      of its languages. A link containing a backslash, a space or a control
      character is refused as well, because browsers remove or reinterpret
      those characters.

    A shortcut is judged by the page it leads to, under the same access rule
    as a page chosen directly. One difference between the TYPO3 versions
    remains: a shortcut to a link page that points to a page of this site
    is resolved to that page on TYPO3 14.3 and ignored on 13.4.

..  confval:: settings.redirectAfterLogin

    :type: page
    :Default: none (the visitor stays on the current page)

    The page a successful passkey login leads to: the plugin's hidden login
    form posts to it, so the one-time login token only ever goes to a URL
    that passed these checks. The rules of
    :confval:`settings.passwordLoginPage` apply, with one difference in
    access: the page, and every page above it that has "Extend to subpages"
    set, may be unrestricted or restricted to "Show at any login"
    (``fe_group`` ``-2``), which is linked although the anonymous visitor
    cannot see it yet. A page for a user group, "Hide at login" or a
    combination is ignored, because the login does not necessarily grant it.
    Where felogin sits on the same page, the login is completed through
    felogin's form and felogin's own redirect settings apply instead.

    ..  important::

        Frontend users without any user group cannot reach a "Show at any
        login" page. TYPO3 counts a frontend user as logged in for page
        access only when the user belongs to at least one frontend user
        group: ``FrontendUserAuthentication::createUserAspect()`` (TYPO3
        13.4 and 14.3) adds ``-2`` to the user's groups only in that case.
        A user without a group who logs in with a passkey is sent to such a
        redirect page and gets "403 Access Denied". Put every frontend user
        in a group, or pick an unrestricted page.

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
