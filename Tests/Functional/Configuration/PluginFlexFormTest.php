<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Configuration;

use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Each plugin content type shows its FlexForm in the content element form,
 * and FormEngine resolves the plugin's own data structure for it, on both
 * supported TYPO3 versions.
 */
#[CoversNothing]
final class PluginFlexFormTest extends AbstractPasskeyFunctionalTestCase
{
    /**
     * Content type => [FlexForm field => its config type].
     *
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function pluginProvider(): iterable
    {
        yield 'login' => ['nrpasskeysfe_passkeylogin', [
            'settings.discoverableEnabled' => 'check',
            'settings.showPasswordFallback' => 'check',
            'settings.passwordLoginPage' => 'group',
            'settings.redirectAfterLogin' => 'group',
            'settings.cssClass' => 'input',
        ]];
        yield 'management' => ['nrpasskeysfe_passkeymanagement', ['settings.cssClass' => 'input']];
        yield 'enrollment' => ['nrpasskeysfe_passkeyenrollment', ['settings.cssClass' => 'input']];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cTypeProvider(): iterable
    {
        foreach (self::pluginProvider() as $name => [$cType]) {
            yield $name => [$cType];
        }
    }

    #[Test]
    #[DataProvider('cTypeProvider')]
    public function theContentElementFormShowsTheFlexFormField(string $cType): void
    {
        $showitem = $GLOBALS['TCA']['tt_content']['types'][$cType]['showitem'] ?? '';
        self::assertIsString($showitem);

        $fields = \array_map(
            static fn(string $item): string => \trim(\explode(';', $item)[0]),
            \explode(',', $showitem),
        );
        self::assertContains('pi_flexform', $fields, $cType . ' has no pi_flexform field in its form');
    }

    /**
     * The fields must carry their own config: a field whose config core had
     * to fill in (the removed TCEforms wrapper) is typed "none" and cannot be
     * edited.
     *
     * @param array<string, string> $expectedFields
     */
    #[Test]
    #[DataProvider('pluginProvider')]
    public function formEngineResolvesThePluginsOwnDataStructure(string $cType, array $expectedFields): void
    {
        $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
        $fieldTca = $GLOBALS['TCA']['tt_content']['columns']['pi_flexform'];
        self::assertIsArray($fieldTca);

        $row = ['uid' => 1, 'pid' => 1, 'CType' => $cType, 'list_type' => ''];

        if ((new Typo3Version())->getMajorVersion() >= 14) {
            // 14.x resolves the data structure through the record type's
            // columnsOverrides and needs the table schema, as FormEngine passes it.
            $schema = $this->get(TcaSchemaFactory::class)->get('tt_content');
            $identifier = $flexFormTools->getDataStructureIdentifier($fieldTca, 'tt_content', 'pi_flexform', $row, $schema);
            $dataStructure = $flexFormTools->parseDataStructureByIdentifier($identifier, $schema);
        } else {
            $identifier = $flexFormTools->getDataStructureIdentifier($fieldTca, 'tt_content', 'pi_flexform', $row);
            $dataStructure = $flexFormTools->parseDataStructureByIdentifier($identifier);
        }

        $types = [];
        foreach ($dataStructure['sheets']['sDEF']['ROOT']['el'] ?? [] as $name => $field) {
            $types[$name] = $field['config']['type'] ?? null;
        }

        self::assertSame($expectedFields, $types);
        self::assertSame('General', $dataStructure['sheets']['sDEF']['ROOT']['sheetTitle'] ?? null);
    }
}
