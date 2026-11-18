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
