.. SPDX-License-Identifier: GPL-2.0-or-later
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: ../Includes.rst.txt

..  _administration:

==============
Administration
==============

This chapter covers administration tasks for TYPO3 editors and
administrators managing frontend passkey authentication.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    Dashboard
    Enforcement
    UserManagement

Enrollment notification
-----------------------

After a passkey is saved, the extension sends a security notice to the email
address on the account using the site's configured TYPO3 mail transport.
The notice uses the enrolling site's default language.
An absent or invalid account address or a mail failure leaves the
registered credential intact and is recorded in the TYPO3 log.

Direct SMTP delivery waits for the mail server during registration. To return
the enrollment response before delivery, configure the TYPO3 file spool. Set
:php:`$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_spool_type']` to
:php:`'file'` and :php:`transport_spool_filepath` to a private directory
outside the web root. Schedule :bash:`vendor/bin/typo3 mailer:spool:send` to
deliver queued notices through the configured transport. The spool applies to
all TYPO3 mail on that installation.
