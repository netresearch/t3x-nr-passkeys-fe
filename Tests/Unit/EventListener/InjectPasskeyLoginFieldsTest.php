<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\EventListener;

use Netresearch\NrPasskeysFe\Configuration\FrontendConfiguration;
use Netresearch\NrPasskeysFe\EventListener\InjectPasskeyLoginFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\FrontendLogin\Event\ModifyLoginFormViewEvent;

#[CoversClass(InjectPasskeyLoginFields::class)]
final class InjectPasskeyLoginFieldsTest extends TestCase
{
    private function buildEvent(ViewInterface $view): ModifyLoginFormViewEvent
    {
        return new ModifyLoginFormViewEvent($view, new ServerRequest('https://example.com/', 'GET'));
    }

    #[Test]
    public function doesNothingForForeignEventObjects(): void
    {
        $subject = new InjectPasskeyLoginFields(new FrontendConfiguration(enableFePasskeys: true));

        // The listener parameter type is object; anything that is not the
        // felogin event must be ignored, which here means: no error.
        $subject->__invoke(new stdClass());
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function assignsNothingWhenFePasskeysDisabled(): void
    {
        $subject = new InjectPasskeyLoginFields(new FrontendConfiguration(enableFePasskeys: false));

        $view = $this->createMock(ViewInterface::class);
        $view->expects(self::never())->method('assign');

        $subject->__invoke($this->buildEvent($view));
    }

    #[Test]
    public function assignsTheEidUrlTheTemplateOverrideReads(): void
    {
        $subject = new InjectPasskeyLoginFields(new FrontendConfiguration(enableFePasskeys: true));

        // The template override reads only the eID URL from the listener.
        $view = $this->createMock(ViewInterface::class);
        $view->expects(self::once())->method('assign')->with('passkeyEidUrl', '?eID=nr_passkeys_fe');

        $subject->__invoke($this->buildEvent($view));
    }
}
