<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Service;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Event\AfterPasskeyEnrollmentEvent;
use Netresearch\NrPasskeysFe\EventListener\SendEnrollmentNotification;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;

#[CoversClass(SendEnrollmentNotification::class)]
final class SendEnrollmentNotificationTest extends AbstractPasskeyFunctionalTestCase
{
    private string $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->mailbox                                                = Environment::getVarPath() . '/enrollment-' . bin2hex(random_bytes(10)) . '.mbox';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport']              = 'mbox';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file']    = $this->mailbox;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = 'no-reply@example.test';
        $db                                                           = $this->getConnectionPool()->getConnectionForTable('fe_users');
        $db->update('fe_users', ['email' => 'owner@example.test'], ['uid' => 1]);
        $db->update('fe_users', ['email' => 'other@example.test'], ['uid' => 2]);

        $directory = Environment::getConfigPath() . '/sites/home';
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $fixture = file_get_contents(__DIR__ . '/Fixtures/home-site.yaml');
        self::assertIsString($fixture);
        file_put_contents(
            $directory . '/config.yaml',
            str_replace(
                'https://home.example/',
                'https://home.example/members/',
                $fixture,
            ),
        );
        $this->get(SiteConfiguration::class)->siteConfigurationChanged();
    }

    #[Test]
    public function notifiesOnlyTheOwnerThroughTheConfiguredTransport(): void
    {
        $this->enroll();
        self::assertFileExists($this->mailbox);
        $message = file_get_contents($this->mailbox);
        self::assertIsString($message);
        self::assertStringContainsString('To: owner@example.test', $message);
        self::assertStringContainsString('From:', $message);
        self::assertStringContainsString('no-reply@example.test', $message);
        self::assertStringContainsString(
            'A passkey was added to your account',
            $message,
        );
        self::assertStringContainsString(
            'home (https://home.example/members/)',
            quoted_printable_decode($message),
        );
        self::assertStringNotContainsString('other@example.test', $message);
        self::assertStringNotContainsString('sensitive-key-label', $message);
    }

    #[Test]
    public function missingAddressProducesNoMessage(): void
    {
        $this
            ->getConnectionPool()
            ->getConnectionForTable('fe_users')
            ->update('fe_users', ['email' => ''], ['uid' => 1]);
        $this->enroll();
        self::assertFileDoesNotExist($this->mailbox);
    }

    #[Test]
    public function unavailableMailTransportLeavesEnrollmentIntact(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'no-such-transport';
        $this->enroll();
        self::assertFileDoesNotExist($this->mailbox);
        self::assertSame(
            1,
            $this
                ->getConnectionPool()
                ->getConnectionForTable('tx_nrpasskeysfe_credential')
                ->count('*', 'tx_nrpasskeysfe_credential', ['fe_user' => 1]),
        );
    }

    private function enroll(): void
    {
        $credential = new FrontendCredential(
            feUser: 1,
            credentialId: 'test-id',
            publicKeyCose: 'test-public-key',
            label: 'sensitive-key-label',
            siteIdentifier: 'home',
            storagePid: 1,
        );
        $this->get(FrontendCredentialRepository::class)->save($credential);
        $this
            ->get(EventDispatcherInterface::class)
            ->dispatch(new AfterPasskeyEnrollmentEvent(1, $credential, 'home'));
    }

    protected function tearDown(): void
    {
        if (isset($this->mailbox) && is_file($this->mailbox)) {
            unlink($this->mailbox);
        }

        parent::tearDown();
    }
}
