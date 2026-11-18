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

Credential backup eligibility
-----------------------------

The user record shows whether the authenticator reported that a newly
registered credential is eligible for backup or is bound to one device.
Backup eligibility does not establish that a backup has been made.
Credentials registered before this field was added show an unknown status.
Apply the database schema update when upgrading the extension.
