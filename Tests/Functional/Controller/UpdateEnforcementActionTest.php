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
use Netresearch\NrPasskeysFe\Tests\Functional\Controller\Fixtures\DropEnforcementFieldHook;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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
        // Members = required, Premium = off, Restricted = off, Removed = deleted
        $this->importCSVDataSet(__DIR__ . '/Fixtures/fe_groups.csv');
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

        // DataHandler, not a bare UPDATE: the change is in the record history
        // (actiontype 2 = update) and in the system log (action 2 = update).
        self::assertSame(1, $this->countRows('sys_history', ['tablename' => 'fe_groups', 'recuid' => 2, 'actiontype' => 2]));
        self::assertSame(1, $this->countRows('sys_log', ['tablename' => 'fe_groups', 'recuid' => 2, 'action' => 2, 'error' => 0]));
    }

    #[Test]
    public function aDeletedGroupIsReportedAsNotFound(): void
    {
        $this->actAs(1);

        $response = $this->post(['groupUid' => 4, 'enforcement' => 'required']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('off', $this->storedLevel(4));
    }

    #[Test]
    public function anAdministratorInAWorkspaceIsAskedToSwitchToLive(): void
    {
        // fe_groups carries no versioningWS, so DataHandler refuses every
        // write to it outside the live workspace.
        $this->actAs(1)->workspace = 1;

        $response = $this->post(['groupUid' => 2, 'enforcement' => 'required']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Frontend user groups are not versioned in workspaces. Switch to the Live workspace to change the enforcement level.'],
            \json_decode((string) $response->getBody(), true),
        );
        self::assertSame('off', $this->storedLevel(2));
        self::assertSame(0, $this->countRows('sys_history', ['tablename' => 'fe_groups']));

        // The message comes from the extension's language files: in German it
        // is the de.locallang.xlf target, not the English fallback in the code.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('de');
        $german = $this->post(['groupUid' => 2, 'enforcement' => 'required']);
        self::assertSame(
            ['error' => 'Frontend-Benutzergruppen werden in Arbeitsumgebungen nicht versioniert. Wechseln Sie in die LIVE-Arbeitsumgebung, um die Durchsetzungsstufe zu ändern.'],
            \json_decode((string) $german->getBody(), true),
        );
    }

    #[Test]
    public function aWorkspaceWithLiveEditingStillSaves(): void
    {
        // A workspace may allow live editing of tables without versioning;
        // there DataHandler writes the live fe_groups record, so the save is
        // allowed.
        $backendUser = $this->actAs(1);
        $backendUser->workspace = 1;
        $backendUser->workspaceRec = ['uid' => 1, 'live_edit' => 1];

        $response = $this->post(['groupUid' => 2, 'enforcement' => 'required']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('required', $this->storedLevel(2));
    }

    #[Test]
    public function theServiceReportsADataHandlerErrorAndLeavesTheRecordAlone(): void
    {
        // An editor has no write permission on fe_groups; DataHandler logs that
        // to its error log instead of writing.
        $this->actAs(2);

        try {
            $this->get(FrontendGroupEnforcementService::class)->setLevel(2, 'required');
            self::fail('A DataHandler error has to surface as an exception.');
        } catch (RuntimeException $exception) {
            if ($exception instanceof AssertionFailedError) {
                throw $exception;
            }

            self::assertSame(1790000001, $exception->getCode(), $exception::class . ': ' . $exception->getMessage());
            self::assertStringContainsString('fe_groups', $exception->getMessage());
        }

        self::assertSame('off', $this->storedLevel(2));
    }

    #[Test]
    public function theServiceReportsAWriteThatDidNotStick(): void
    {
        // A hook drops the field without an error-log entry, as a third-party
        // hook or a missing field permission can; only the read-back notices.
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['nr_passkeys_fe_test']
            = DropEnforcementFieldHook::class;
        $this->actAs(1);

        try {
            $this->get(FrontendGroupEnforcementService::class)->setLevel(2, 'required');
            self::fail('A value that was not stored has to surface as an exception.');
        } catch (RuntimeException $exception) {
            if ($exception instanceof AssertionFailedError) {
                throw $exception;
            }

            self::assertSame(1790000002, $exception->getCode(), $exception::class . ': ' . $exception->getMessage());
        } finally {
            unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['nr_passkeys_fe_test']);
        }

        self::assertSame('off', $this->storedLevel(2));
    }

    #[Test]
    public function dataHandlerStoresAnyStringInTheSelectSoTheActionMustValidate(): void
    {
        // Pins why the action's own level check matters: DataHandler does not
        // hold a select value against the TCA items and stores it as given.
        $this->actAs(1);

        $this->get(FrontendGroupEnforcementService::class)->setLevel(2, 'mandatory');

        self::assertSame('mandatory', $this->storedLevel(2));
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

    private function actAs(int $backendUserUid): BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($backendUserUid);
        // DataHandler reads $GLOBALS['LANG'] for its messages.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        return $backendUser;
    }

    /**
     * @param array<string, int|string> $where
     */
    private function countRows(string $table, array $where): int
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable($table)->count('*', $table, $where);
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
        // Without restrictions, so a deleted row is read as well.
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('fe_groups');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('passkey_enforcement')
            ->from('fe_groups')
            ->where($queryBuilder->expr()->eq('uid', $groupUid))
            ->executeQuery()
            ->fetchOne();
    }
}
