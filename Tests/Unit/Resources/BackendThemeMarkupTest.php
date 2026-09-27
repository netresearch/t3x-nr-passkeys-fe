<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Resources;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgSpriteIconProvider;

/**
 * Pins the backend markup that follows TYPO3's light and dark scheme on
 * 13.4 and 14.3: core classes and tokens only, an accessible name on every
 * control and progress bar, and icons that inherit currentColor.
 */
#[CoversNothing]
final class BackendThemeMarkupTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    /**
     * Record and plugin icons, keyed by icon identifier.
     */
    private const SPRITE_ICONS = [
        'nr-passkeys-fe-credential' => 'credential.svg',
        'nr-passkeys-fe-recovery-code' => 'recovery-code.svg',
        'nr-passkeys-fe-plugin-login' => 'plugin-login.svg',
        'nr-passkeys-fe-plugin-management' => 'plugin-management.svg',
        'nr-passkeys-fe-plugin-enrollment' => 'plugin-enrollment.svg',
    ];

    #[Test]
    public function everyEnforcementSelectHasAnAccessibleName(): void
    {
        $selects = $this->query('Dashboard.html', '//select[contains(@class, "passkey-fe-enforcement-select")]');

        self::assertCount(1, $selects);
        foreach ($selects as $select) {
            self::assertStringContainsString(
                "f:translate(key: 'dashboard.groups.enforcement'",
                $select->getAttribute('aria-label'),
            );
            self::assertStringContainsString('{group.title}', $select->getAttribute('aria-label'));
        }
    }

    #[Test]
    public function theAdoptionBarIsANamedProgressbarDrawnFromCoreTokens(): void
    {
        $bars = $this->query('Dashboard.html', '//*[@role="progressbar"]');

        self::assertCount(1, $bars);
        $bar = $bars[0];
        self::assertSame('passkey-fe-bar', $bar->getAttribute('class'));
        self::assertStringContainsString(
            "f:translate(key: 'dashboard.groups.adoption'",
            $bar->getAttribute('aria-label'),
        );
        self::assertStringContainsString('{group.title}', $bar->getAttribute('aria-label'));
        self::assertSame('0', $bar->getAttribute('aria-valuemin'));
        self::assertSame('100', $bar->getAttribute('aria-valuemax'));
        self::assertSame('{group.adoptionPercentage}', $bar->getAttribute('aria-valuenow'));

        // The fill is a child of the bar; the percentage stays visible next to it.
        $fill = $this->query('Dashboard.html', '//*[@role="progressbar"]/div[@class="passkey-fe-bar-fill"]');
        self::assertCount(1, $fill);
        $value = $this->query('Dashboard.html', '//*[@role="progressbar"]/following-sibling::span[@class="passkey-fe-bar-value"]');
        self::assertCount(1, $value);

        $css = $this->read('Resources/Public/Css/backend.css');
        self::assertMatchesRegularExpression(
            '/\.passkey-fe-bar\s*\{[^}]*background-color:\s*var\(--typo3-surface-container-high\);/',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.passkey-fe-bar-fill\s*\{[^}]*background-color:\s*var\(--typo3-component-primary-color\);/',
            $css,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function backendTemplateProvider(): iterable
    {
        yield 'Dashboard' => ['Dashboard.html'];
        yield 'Help' => ['Help.html'];
    }

    #[Test]
    #[DataProvider('backendTemplateProvider')]
    public function backendTemplatesUseOnlyClassesCoreDefines(string $template): void
    {
        $html = $this->read('Resources/Private/Templates/AdminModule/' . $template);

        // Absent from core's backend.css at v13.4.35 and v14.3.7, or (progress)
        // absent at v14.3.7: each of these renders as nothing or as a raw
        // browser widget, or keeps one colour pair in both schemes.
        foreach (['text-body-secondary', 'table-light', 'table-sm', 'class="progress', 'bg-success', 'class="accordion', 'accordion-item', 'data-bs-toggle', 'text-bg-'] as $class) {
            self::assertStringNotContainsString($class, $html, $template . ' uses ' . $class);
        }
    }

    #[Test]
    public function theHelpFaqUsesNativeDisclosureWidgets(): void
    {
        $xpath = $this->xpath('Help.html');

        self::assertSame(5, $xpath->query('//details[@name="passkey-fe-faq"]')?->length);
        self::assertSame(5, $xpath->query('//details[@name="passkey-fe-faq"]/summary')?->length);
        self::assertSame(5, $xpath->query('//details[@name="passkey-fe-faq"]/summary/following-sibling::p')?->length);
    }

    #[Test]
    public function theModuleControllerLoadsTheBackendStylesheet(): void
    {
        $php = $this->read('Classes/Controller/AdminModuleController.php');

        self::assertStringContainsString("addCssFile('EXT:nr_passkeys_fe/Resources/Public/Css/backend.css')", $php);
        self::assertFileExists(self::ROOT . 'Resources/Public/Css/backend.css');
    }

    #[Test]
    public function theBackendStylesheetUsesOnlyCoreColourTokens(): void
    {
        $css = $this->read('Resources/Public/Css/backend.css');

        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $css);
        self::assertDoesNotMatchRegularExpression('/rgba?\(|hsla?\(/', $css);
        self::assertStringNotContainsString('prefers-color-scheme', $css);
        self::assertStringNotContainsString('--bs-', $css);
    }

    #[Test]
    public function theCredentialLookupPaintsStatusWithCoreBadgesAndMutesLeafCells(): void
    {
        $js = $this->read('Resources/Public/JavaScript/PasskeyFeAdmin.js');

        self::assertStringContainsString("'badge badge-default' : 'badge badge-success'", $js);
        self::assertStringNotContainsString('text-bg-', $js);
        self::assertStringNotContainsString('text-body-secondary', $js);
        self::assertStringNotContainsString("row.classList.add('text-variant')", $js);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spriteIconProvider(): iterable
    {
        foreach (self::SPRITE_ICONS as $identifier => $file) {
            yield $identifier => [$identifier, $file];
        }
    }

    #[Test]
    #[DataProvider('spriteIconProvider')]
    public function recordAndPluginIconsAreSpritesThatInheritCurrentColor(string $identifier, string $file): void
    {
        /** @var array<string, array{provider: class-string, sprite?: string, source?: string}> $icons */
        $icons = require self::ROOT . 'Configuration/Icons.php';

        self::assertSame(SvgSpriteIconProvider::class, $icons[$identifier]['provider']);
        self::assertSame(
            'EXT:nr_passkeys_fe/Resources/Public/Icons/' . $file . '#' . $identifier,
            $icons[$identifier]['sprite'] ?? null,
        );

        $svg = new DOMDocument();
        self::assertTrue($svg->loadXML($this->read('Resources/Public/Icons/' . $file)));
        $xpath = new DOMXPath($svg);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        self::assertSame(1, $xpath->query('/s:svg/s:symbol[@id="' . $identifier . '"]')?->length);
        self::assertSame(1, $xpath->query('/s:svg/s:use[@href="#' . $identifier . '"]')?->length);
        self::assertSame(0, $xpath->query('//s:style')?->length);
        // The glyph takes the backend text colour; only the brand accent is fixed.
        self::assertGreaterThan(0, $xpath->query('/s:svg/s:symbol//*[@fill="currentColor"]')?->length);
        foreach ($xpath->query('//*[@fill]') ?: [] as $shape) {
            self::assertInstanceOf(DOMElement::class, $shape);
            self::assertContains(
                $shape->getAttribute('fill'),
                ['currentColor', 'var(--nr-icon-accent, #2F99A4)'],
                $file . ' paints a fixed colour',
            );
        }
    }

    private function read(string $path): string
    {
        $content = \file_get_contents(self::ROOT . $path);
        self::assertIsString($content);

        return $content;
    }

    private function xpath(string $template): DOMXPath
    {
        $document = new DOMDocument();
        // Fluid's namespaced view helpers are not HTML; libxml keeps them as
        // unknown elements, which is all the queries need.
        \libxml_use_internal_errors(true);
        $document->loadHTML($this->read('Resources/Private/Templates/AdminModule/' . $template));
        \libxml_clear_errors();

        return new DOMXPath($document);
    }

    /**
     * @return list<DOMElement>
     */
    private function query(string $template, string $expression): array
    {
        $result = [];
        foreach ($this->xpath($template)->query($expression) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $result[] = $node;
            }
        }

        return $result;
    }
}
