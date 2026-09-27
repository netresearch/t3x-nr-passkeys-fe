<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Controller;

use Netresearch\NrPasskeysFe\Controller\AdminController;
use Netresearch\NrPasskeysFe\Service\FrontendGroupEnforcementService;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The dashboard's enforcement select, end to end below the HTTP layer: the
 * action and DataHandler against a real database, as an administrator and as
 * an editor.
 */
#[CoversClass(AdminController::class)]
#[CoversClass(FrontendGroupEnforcementService::class)]
final class UpdateEnforcementActionTest extends AbstractPasskeyFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        // Members = required, Premium = off, Restricted = off
        $this->importCSVDataSet(__DIR__ . '/../Service/Fixtures/fe_groups.csv');
    }

    #[Test]
    public function anAdministratorStoresTheLevelThroughDataHandler(): void
    {
        $this->actAs(1);

        $response = $this->post(['groupUid' => 2, 'enforcement' => 'encourage']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['status' => 'ok', 'groupUid' => 2, 'enforcement' => 'encourage'],
            \json_decode((string) $response->getBody(), true),
        );
        self::assertSame('encourage', $this->storedLevel(2));
        // Only the requested group changed.
        self::assertSame('required', $this->storedLevel(1));
        self::assertSame('off', $this->storedLevel(3));
    }

    #[Test]
    public function anEditorIsRefusedAndNothingIsWritten(): void
    {
        $this->actAs(2);

        $response = $this->post(['groupUid' => 2, 'enforcement' => 'enforced']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('off', $this->storedLevel(2));
    }

    #[Test]
    public function anInvalidLevelIsRefusedAndNothingIsWritten(): void
    {
        $this->actAs(1);

        $response = $this->post(['groupUid' => 1, 'enforcement' => 'mandatory']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('required', $this->storedLevel(1));
    }

    #[Test]
    public function anUnknownGroupIsReportedAsNotFound(): void
    {
        $this->actAs(1);

        $response = $this->post(['groupUid' => 999, 'enforcement' => 'off']);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function theAcceptedLevelsAreExactlyTheOnesTheTcaSelectOffers(): void
    {
        $items = $GLOBALS['TCA']['fe_groups']['columns']['passkey_enforcement']['config']['items'] ?? [];
        self::assertIsArray($items);

        self::assertSame(FrontendGroupEnforcementService::LEVELS, \array_column($items, 'value'));
    }

    private function actAs(int $backendUserUid): void
    {
        $backendUser = $this->setUpBackendUser($backendUserUid);
        // DataHandler reads $GLOBALS['LANG'] for its messages.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): ResponseInterface
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax/nr-passkeys-fe/admin/update-enforcement', 'POST'))
            ->withParsedBody($body);

        return $this->get(AdminController::class)->updateEnforcementAction($request);
    }

    private function storedLevel(int $groupUid): mixed
    {
        return $this->get(ConnectionPool::class)
            ->getConnectionForTable('fe_groups')
            ->select(['passkey_enforcement'], 'fe_groups', ['uid' => $groupUid])
            ->fetchOne();
    }
}
