.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: ../Includes.rst.txt

..  _configuration:

=============
Configuration
=============

Configuration happens at four levels:

1. **Extension settings** -- Global defaults (algorithm, challenge TTL,
   rate limiting)
2. **Site configuration** -- Per-site RP ID and origin
3. **TypoScript** -- Plugin view paths, the default of discoverable login
   and the default CSS
4. **Plugin FlexForm** -- Per-plugin switches on the content element

Plugin FlexForm
===============

The login plugin carries these settings on the content element itself, on
its :guilabel:`Plugin` tab:

..  confval:: settings.discoverableEnabled

    :type: select: *Use the site setting* / *On* / *Off*
    :Default: *Use the site setting*

    Allow login without entering a username: the passkey identifies the
    user. *Off* shows a username field and requires a username before a
    passkey is accepted. *Use the site setting* follows the TypoScript
    constant :confval:`plugin.tx_nrpasskeysfe.settings.discoverableEnabled`;
    *On* and *Off* hold for this content element whatever the constant says.

    Content elements saved before this field became a select stored ``1``
    (the old checkbox's default) or ``0``. Both keep their meaning, as *On*
    and *Off*, so such an element does not follow the constant until it is
    set to *Use the site setting*. The empty choice is handed to TypoScript
    by a listener on ``BeforeFlexFormConfigurationOverrideEvent``; core's
    ``ignoreFlexFormSettingsIfEmpty`` cannot be used for it, because it also
    treats ``0`` as empty and would override an explicit *Off*.

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

    - the page is a standard page (doktype 1), or shortcuts lead to one.
      External-URL and link pages, folders, spacers, mount points and every
      other page type are ignored, so no URL an editor types reaches the
      plugin. A shortcut is followed as TYPO3 follows it for the visitor
      (target page, first subpage the visitor may see, parent page); a
      "random subpage" shortcut anywhere in the chain is ignored, because
      the page it leads to is not known when the link is built;
    - that standard page belongs to the site the plugin is rendered on;
    - TYPO3 grants a visitor who is not logged in access to it, with the
      same checks as for a page request: the page itself (hidden, start
      and end time, access groups) and every page above it that has
      "Extend to subpages" set, in the current workspace and language. In
      practice the page and those pages are unrestricted or set to "Hide at
      login";
    - the link TYPO3 built stays on the site: a path, or an ``http``/``https``
      URL whose scheme, host and port are those of the site's base or of one
      of its languages. A link containing a backslash, a space or a control
      character is refused as well, because browsers remove or reinterpret
      those characters.

..  confval:: settings.redirectAfterLogin

    :type: page
    :Default: none (the visitor stays on the current page)

    The page a successful passkey login leads to: the plugin's hidden login
    form posts to it, so the one-time login token only ever goes to a URL
    that passed these checks. The rules of
    :confval:`settings.passwordLoginPage` apply, with one difference in
    access: TYPO3 has to grant access to a visitor holding "Show at any
    login" (``fe_group`` ``-2``) and no other group, so the page may be
    linked although the anonymous visitor cannot see it yet. A page, or a
    page above it that extends its access to subpages, restricted to a user
    group or to "Hide at login" is ignored, because the login does not
    necessarily grant it; "Show at any login" combined with a group is
    accepted, because TYPO3 grants either.
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
