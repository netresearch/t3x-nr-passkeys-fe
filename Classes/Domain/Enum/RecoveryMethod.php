<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Domain\Enum;

/**
 * Recovery method available to a frontend user who cannot use a passkey.
 */
enum RecoveryMethod: string
{
    case Password = 'password';
    case RecoveryCode = 'recovery_code';
    case MagicLink = 'magic_link';
}
