<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Unit\Controller\Plugin;

use ArrayObject;
use GuzzleHttp\Psr7\Uri;
use Netresearch\NrPasskeysFe\Controller\Plugin\LoginPluginController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Error\Http\ShortcutTargetPageNotFoundException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;

#[CoversClass(LoginPluginController::class)]
final class LoginPluginControllerTest extends TestCase
{
    #[Test]
    public function isInstantiable(): void
    {
        $subject = new LoginPluginController($this->createStub(SiteFinder::class), $this->createStub(PageRepository::class));
        self::assertInstanceOf(LoginPluginController::class, $subject);
    }

    #[Test]
    public function indexActionReturnsResponseInterface(): void
    {
        $subject = $this->buildController();

        $view = $this->createStub(ViewInterface::class);
        $this->injectExtbaseProperties($subject, $this->buildExtbaseRequest('main', 'https://example.com'), $view);

        $response = $subject->indexAction();
        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function indexActionAssignsExpectedTemplateVariables(): void
    {
        $subject = $this->buildController();

        $assignedVars = [];
        $view = $this->createStub(ViewInterface::class);
        $view->method('assignMultiple')->willReturnCallback(
            static function (array $vars) use (&$assignedVars, $view): ViewInterface {
                $assignedVars = $vars;
                return $view;
            },
        );

        $this->injectExtbaseProperties(
            $subject,
            $this->buildExtbaseRequest('test-site', 'https://test.example.com'),
            $view,
            ['settings' => []],
        );

        $subject->indexAction();

        self::assertSame('https://test.example.com/?eID=nr_passkeys_fe', $assignedVars['eidUrl']);
        self::assertSame('test-site', $assignedVars['siteIdentifier']);
        self::assertTrue($assignedVars['discoverableEnabled']);
    }

    #[Test]
    public function discoverableLoginIsTheDefaultAndShowsNoUsernameField(): void
    {
        $vars = $this->renderWithSettings([]);

        self::assertTrue($vars['discoverableEnabled']);
        self::assertFalse($vars['showUsernameField']);
    }

    #[Test]
    public function turningDiscoverableLoginOffAsksForTheUsername(): void
    {
        $vars = $this->renderWithSettings(['discoverableEnabled' => '0']);

        self::assertFalse($vars['discoverableEnabled']);
        self::assertTrue($vars['showUsernameField']);
    }

    #[Test]
    public function aRedirectPageOnTheSameSiteBecomesTheTargetOfTheLogin(): void
    {
        $vars = $this->renderWithSettings(['redirectAfterLogin' => '11'], targets: [11 => ['main', '/member']]);

        self::assertSame('/member', $vars['redirectUrl']);
    }

    #[Test]
    public function aRedirectPageReferenceWithTheTablePrefixIsResolvedToo(): void
    {
        $vars = $this->renderWithSettings(['redirectAfterLogin' => 'pages_11'], targets: [11 => ['main', '/member']]);

        self::assertSame('/member', $vars['redirectUrl']);
    }

    #[Test]
    public function aRedirectPageOfAnotherSiteIsIgnored(): void
    {
        $vars = $this->renderWithSettings(['redirectAfterLogin' => '11'], targets: [11 => ['other', 'https://other.example/member']]);

        self::assertNull($vars['redirectUrl']);
    }

    #[Test]
    public function aRedirectPageWithoutASiteIsIgnored(): void
    {
        $vars = $this->renderWithSettings(['redirectAfterLogin' => '11'], targets: []);

        self::assertNull($vars['redirectUrl']);
    }

    #[Test]
    public function aRedirectPageTypolinkWillNotLinkToIsIgnored(): void
    {
        // A hidden page resolves to its site but builds no link, access
        // restriction or not; the login then stays on the current page.
        $vars = $this->renderWithSettings(['redirectAfterLogin' => '11'], targets: [11 => ['main', '']]);

        self::assertNull($vars['redirectUrl']);
    }

    #[Test]
    public function aRedirectPageOnlyLoggedInUsersMaySeeIsLinked(): void
    {
        // The form arrives with the login, so the visitor may see the page by
        // then; typolink only links it when asked to link restricted pages.
        $vars = $this->renderWithSettings(['redirectAfterLogin' => '18'], targets: [18 => ['main', '/members-only', '-2']]);

        self::assertSame('/members-only', $vars['redirectUrl']);
    }

    #[Test]
    public function aPasswordPageOnlyLoggedInUsersMaySeeIsNotLinked(): void
    {
        // The password link is shown to visitors who are not logged in yet.
        $vars = $this->renderWithSettings(['passwordLoginPage' => '18'], targets: [18 => ['main', '/members-only', '-2']]);

        self::assertNull($vars['passwordFallbackUrl']);
    }

    /**
     * Links TYPO3 can build for a page of this site that lead elsewhere: an
     * external-URL page (doktype 3), a shortcut (doktype 4) into another
     * site, and forms a browser reads as another host or no page at all.
     *
     * @return iterable<string, array{string}>
     */
    public static function linksLeavingTheSite(): iterable
    {
        yield 'external URL page' => ['https://evil.example/landing'];
        yield 'shortcut into another site' => ['https://other.example/page'];
        yield 'protocol-relative' => ['//evil.example/landing'];
        yield 'backslash host' => ['/\\evil.example/landing'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'other scheme on the site host' => ['ftp://main.example/file'];
        yield 'no scheme, not a path' => ['evil.example/landing'];
        // Browsers strip tab, LF and CR from a URL, so these read as "//host".
        yield 'tab in a path' => ["/\t/evil.example/landing"];
        yield 'line feed in a path' => ["/\n/evil.example/landing"];
        yield 'carriage return in a path' => ["/\r/evil.example/landing"];
        yield 'tab and backslash' => ["/\t\\evil.example/landing"];
        yield 'backslash before the user info' => ['http://evil.example\\@main.example/landing'];
        yield 'space' => ['/ /evil.example/landing'];
        yield 'delete character' => ["/\x7f/evil.example/landing"];
        yield 'another port on the site host' => ['https://main.example:8443/landing'];
        // Same host and port as the https base, only the scheme differs.
        yield 'http on the port of the https site' => ['http://main.example:443/landing'];
        yield 'another scheme on the site host' => ['http://main.example/landing'];
    }

    #[Test]
    #[DataProvider('linksLeavingTheSite')]
    public function aPageOfThisSiteWhoseLinkLeavesTheSiteIsIgnored(string $builtLink): void
    {
        $vars = $this->renderWithSettings(
            ['redirectAfterLogin' => '17', 'passwordLoginPage' => '17'],
            targets: [17 => ['main', $builtLink]],
        );

        self::assertNull($vars['redirectUrl']);
        self::assertNull($vars['passwordFallbackUrl']);
    }

    /**
     * Only a standard page is linked. Every other doktype is refused before a
     * link is built, whatever link TYPO3 would build for it: the URL of an
     * external-URL or link page is typed by an editor.
     *
     * @return iterable<string, array{int}>
     */
    public static function doktypesThatAreNoStandardPage(): iterable
    {
        yield 'external URL / link page (3)' => [3];
        yield 'mount point (7)' => [7];
        yield 'spacer (199)' => [199];
        yield 'folder (254)' => [254];
        yield 'backend user section (6)' => [6];
    }

    #[Test]
    #[DataProvider('doktypesThatAreNoStandardPage')]
    public function aPageThatIsNoStandardPageIsIgnored(int $doktype): void
    {
        // The built link is on-site, so only the doktype can refuse it.
        $vars = $this->renderWithSettings(
            ['redirectAfterLogin' => '17', 'passwordLoginPage' => '17'],
            targets: [17 => ['site' => 'main', 'uri' => '/member', 'doktype' => $doktype]],
        );

        self::assertNull($vars['redirectUrl']);
        self::assertNull($vars['passwordFallbackUrl']);
    }

    #[Test]
    public function aShortcutToAStandardPageLinksItsTarget(): void
    {
        $vars = $this->renderWithSettings(
            ['redirectAfterLogin' => '22', 'passwordLoginPage' => '22'],
            targets: [
                22 => ['site' => 'main', 'uri' => '/shortcut', 'doktype' => 4, 'shortcut' => 11],
                11 => ['site' => 'main', 'uri' => '/member'],
            ],
        );

        self::assertSame('/member', $vars['redirectUrl']);
        self::assertSame('/member', $vars['passwordFallbackUrl']);
    }

    /**
     * @return iterable<string, array{array<int, array<string, mixed>>}>
     */
    public static function shortcutsThatLeadNowhereLinkable(): iterable
    {
        yield 'to an external URL page' => [[
            22 => ['site' => 'main', 'uri' => '/shortcut', 'doktype' => 4, 'shortcut' => 17],
            17 => ['site' => 'main', 'uri' => '/member', 'doktype' => 3],
        ]];
        yield 'to a page of another site' => [[
            22 => ['site' => 'main', 'uri' => '/shortcut', 'doktype' => 4, 'shortcut' => 51],
            51 => ['site' => 'other', 'uri' => '/other-page'],
        ]];
        yield 'to itself' => [[
            22 => ['site' => 'main', 'uri' => '/shortcut', 'doktype' => 4, 'shortcut' => 22],
        ]];
        yield 'to a page core cannot resolve' => [[
            22 => ['site' => 'main', 'uri' => '/shortcut', 'doktype' => 4, 'shortcut' => 99],
        ]];
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     */
    #[Test]
    #[DataProvider('shortcutsThatLeadNowhereLinkable')]
    public function aShortcutThatLeadsNowhereLinkableIsIgnored(array $targets): void
    {
        $vars = $this->renderWithSettings(['redirectAfterLogin' => '22', 'passwordLoginPage' => '22'], targets: $targets);

        self::assertNull($vars['redirectUrl']);
        self::assertNull($vars['passwordFallbackUrl']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function restrictionsOtherThanAnyLoggedInUser(): iterable
    {
        yield 'a user group' => ['5'];
        yield 'hide at login' => ['-1'];
        yield 'any login or a group' => ['-2,5'];
    }

    #[Test]
    #[DataProvider('restrictionsOtherThanAnyLoggedInUser')]
    public function aLoginTargetWithAnotherRestrictionIsNotLinked(string $feGroup): void
    {
        // Only "any logged-in user" (-2) is known to be visible after the
        // login. A "hide at login" page (-1) is one typolink does link for
        // the anonymous visitor, so the controller has to refuse it itself.
        $vars = $this->renderWithSettings(
            ['redirectAfterLogin' => '18'],
            targets: [18 => ['main', '/restricted', $feGroup]],
        );

        self::assertNull($vars['redirectUrl']);
    }

    #[Test]
    public function aHideAtLoginPageIsStillAPasswordLink(): void
    {
        // The password link is followed before the login, where -1 is visible.
        $vars = $this->renderWithSettings(
            ['passwordLoginPage' => '18'],
            targets: [18 => ['main', '/login', '-1']],
        );

        self::assertSame('/login', $vars['passwordFallbackUrl']);
    }

    #[Test]
    public function anAbsoluteLinkOnASiteWithoutAHostIsIgnored(): void
    {
        // A site with base "/" has no origin to compare with, and TYPO3 builds
        // paths for it even with config.forceAbsoluteUrls. The host of the
        // request is not trusted in its place: it comes from the client.
        $vars = $this->renderWithSettings(
            ['redirectAfterLogin' => '11'],
            targets: [11 => ['main', 'https://main.example/member']],
            siteBase: '/',
        );

        self::assertNull($vars['redirectUrl']);
    }

    /**
     * Pages under an ancestor that extends its fe_group to subpages, the
     * target itself unrestricted. 30 is the parent, 31 the grandparent.
     *
     * @return iterable<string, array{array<int, array<string, mixed>>, bool, bool}>
     */
    public static function inheritedRestrictions(): iterable
    {
        $tree = static fn(string $parentGroup, int $extend, array $grandparent = []): array => [
            18 => ['site' => 'main', 'uri' => '/tree/child', 'pid' => 30],
            30 => ['site' => 'main', 'uri' => '/tree', 'pid' => $grandparent === [] ? 1 : 31, 'fe_group' => $parentGroup, 'extendToSubpages' => $extend],
        ] + ($grandparent === [] ? [] : [31 => ['site' => 'main', 'uri' => '/', 'pid' => 1] + $grandparent]);

        // [pages, linked as the login target, linked as the password page]
        yield 'a group, extended' => [$tree('8', 1), false, false];
        yield 'hide at login, extended' => [$tree('-1', 1), false, true];
        yield 'any login, extended' => [$tree('-2', 1), true, false];
        yield 'a group, not extended' => [$tree('8', 0), true, true];
        yield 'a group on the grandparent, extended' => [$tree('', 0, ['fe_group' => '8', 'extendToSubpages' => 1]), false, false];
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     */
    #[Test]
    #[DataProvider('inheritedRestrictions')]
    public function anInheritedRestrictionCountsAsTheTargetsOwn(array $targets, bool $asLoginTarget, bool $asPasswordPage): void
    {
        $vars = $this->renderWithSettings(['redirectAfterLogin' => '18', 'passwordLoginPage' => '18'], targets: $targets);

        self::assertSame($asLoginTarget ? '/tree/child' : null, $vars['redirectUrl']);
        self::assertSame($asPasswordPage ? '/tree/child' : null, $vars['passwordFallbackUrl']);
    }

    #[Test]
    public function aShortcutToAPageForAnyLoginIsJudgedLikeThatPage(): void
    {
        // Core's own group check would drop the -2 target for the anonymous
        // visitor; the shortcut is resolved without it and judged here.
        $vars = $this->renderWithSettings(
            ['redirectAfterLogin' => '22', 'passwordLoginPage' => '22'],
            targets: [
                22 => ['site' => 'main', 'uri' => '/shortcut', 'doktype' => 4, 'shortcut' => 18],
                18 => ['site' => 'main', 'uri' => '/members-only', 'fe_group' => '-2'],
            ],
        );

        self::assertSame('/members-only', $vars['redirectUrl']);
        self::assertNull($vars['passwordFallbackUrl']);
    }

    #[Test]
    public function aRandomSubpageShortcutIsIgnored(): void
    {
        $vars = $this->renderWithSettings(
            ['redirectAfterLogin' => '22', 'passwordLoginPage' => '22'],
            targets: [
                22 => ['site' => 'main', 'uri' => '/shortcut', 'doktype' => 4, 'shortcut' => 11, 'shortcut_mode' => 2],
                11 => ['site' => 'main', 'uri' => '/member'],
            ],
        );

        self::assertNull($vars['redirectUrl']);
        self::assertNull($vars['passwordFallbackUrl']);
    }

    #[Test]
    public function anAbsoluteLinkWithTheDefaultPortSpelledOutIsKept(): void
    {
        $vars = $this->renderWithSettings(['redirectAfterLogin' => '11'], targets: [11 => ['main', 'https://main.example:443/member']]);

        self::assertSame('https://main.example:443/member', $vars['redirectUrl']);
    }

    #[Test]
    public function anAbsoluteLinkOnTheSiteHostIsKept(): void
    {
        $vars = $this->renderWithSettings(['redirectAfterLogin' => '11'], targets: [11 => ['main', 'https://MAIN.example/member']]);

        self::assertSame('https://MAIN.example/member', $vars['redirectUrl']);
    }

    #[Test]
    public function anAbsoluteLinkOnTheHostOfASiteLanguageIsKept(): void
    {
        $vars = $this->renderWithSettings(
            ['redirectAfterLogin' => '11'],
            targets: [11 => ['main', 'https://main.example.de/mitglieder']],
            languageBases: ['https://main.example.de/'],
        );

        self::assertSame('https://main.example.de/mitglieder', $vars['redirectUrl']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusablePageReferences(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-11'];
        yield 'a URL' => ['https://evil.example/'];
        yield 'a path' => ['/member'];
        yield 'prefix only' => ['pages_'];
        yield 'another table' => ['tt_content_11'];
        yield 'boolean' => [true];
        yield 'list' => ['11,12'];
    }

    #[Test]
    #[DataProvider('unusablePageReferences')]
    public function aPageReferenceThatIsNoPageUidIsIgnored(mixed $reference): void
    {
        $vars = $this->renderWithSettings(
            ['redirectAfterLogin' => $reference, 'passwordLoginPage' => $reference],
            targets: [11 => ['main', '/member'], 1 => ['main', '/']],
        );

        self::assertNull($vars['redirectUrl']);
        self::assertNull($vars['passwordFallbackUrl']);
    }

    #[Test]
    public function thePasswordFallbackLinksToThePasswordLoginPage(): void
    {
        $vars = $this->renderWithSettings(['passwordLoginPage' => '10'], targets: [10 => ['main', '/login']]);

        self::assertSame('/login', $vars['passwordFallbackUrl']);
    }

    #[Test]
    public function turningThePasswordFallbackOffHidesItEvenWithAPage(): void
    {
        $vars = $this->renderWithSettings(
            ['showPasswordFallback' => '0', 'passwordLoginPage' => '10'],
            targets: [10 => ['main', '/login']],
        );

        self::assertNull($vars['passwordFallbackUrl']);
    }

    #[Test]
    public function thePasswordFallbackNeedsAPageOnTheSameSite(): void
    {
        $vars = $this->renderWithSettings(['passwordLoginPage' => '10'], targets: [10 => ['other', 'https://other.example/login']]);

        self::assertNull($vars['passwordFallbackUrl']);
    }

    /**
     * Render indexAction for the site "main" and return what it assigned.
     *
     * @param array<string, mixed>                          $settings
     * @param array<int, array{0: string, 1: string, 2?: bool}> $targets       page uid => [site identifier, URI typolink builds, only for logged-in users]
     * @param list<string>                                  $languageBases bases of the current site's languages
     *
     * @return array<string, mixed>
     */
    private function renderWithSettings(
        array $settings,
        array $targets = [],
        array $languageBases = [],
        string $siteBase = 'https://main.example',
    ): array {
        $targets = \array_map($this->normalizeTarget(...), $targets);
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturnCallback(
            static function (int $pageUid) use ($targets): Site {
                if (!isset($targets[$pageUid])) {
                    throw new SiteNotFoundException('No site for page ' . $pageUid, 1);
                }

                return new Site($targets[$pageUid]['site'], 1, ['base' => 'https://' . $targets[$pageUid]['site'] . '.example/']);
            },
        );

        // getPage(), getRawRecord() and resolveShortcutPage() as core answers
        // them for the records described in $targets.
        $pageRepository = $this->createStub(PageRepository::class);
        $pageRepository->method('getPage')->willReturnCallback(
            static fn(int $uid): array => isset($targets[$uid]) ? self::pageRecord($uid, $targets[$uid]) : [],
        );
        $pageRepository->method('getRawRecord')->willReturnCallback(
            static fn(string $table, int $uid): ?array => isset($targets[$uid]) ? self::pageRecord($uid, $targets[$uid]) : null,
        );
        $pageRepository->method('resolveShortcutPage')->willReturnCallback(
            // 13.4 takes ($page, $resolveRandomSubpages, $disableGroupAccessCheck),
            // 14.3 ($page, $disableGroupAccessCheck): the group flag comes last.
            static function (array $page, bool ...$flags) use ($targets): array {
                $target = $targets[$page['uid']]['shortcut'] ?? null;
                $groupChecked = $flags === [] || !\end($flags);
                if (
                    $target === null
                    || !isset($targets[$target])
                    // With the group check, core resolves only to pages the
                    // anonymous visitor may see.
                    || ($groupChecked && !\in_array($targets[$target]['fe_group'], ['', '0', '-1'], true))
                ) {
                    throw new ShortcutTargetPageNotFoundException('Shortcut target not accessible', 1);
                }

                if ($target === $page['uid']) {
                    // What 13.4 throws for a loop; 14.3 throws a subclass.
                    throw new RuntimeException('Page shortcuts were looping in uids: ' . $target, 1294587212);
                }

                return self::pageRecord($target, $targets[$target]);
            },
        );
        $subject = $this->buildController($siteFinder, $pageRepository);

        // The builder links the page last passed to setTargetPageUid(), and a
        // page only for logged-in users only when restricted pages are linked,
        // as typolink does.
        $target = new ArrayObject(['uid' => 0, 'linkRestricted' => false]);
        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('reset')->willReturnCallback(
            static function () use ($target, $uriBuilder): UriBuilder {
                $target['linkRestricted'] = false;
                return $uriBuilder;
            },
        );
        $uriBuilder->method('setTargetPageUid')->willReturnCallback(
            static function (int $uid) use ($target, $uriBuilder): UriBuilder {
                $target['uid'] = $uid;
                return $uriBuilder;
            },
        );
        $uriBuilder->method('setLinkAccessRestrictedPages')->willReturnCallback(
            static function (bool $link) use ($target, $uriBuilder): UriBuilder {
                $target['linkRestricted'] = $link;
                return $uriBuilder;
            },
        );
        $uriBuilder->method('build')->willReturnCallback(
            static function () use ($target, $targets): string {
                $page = $targets[$target['uid']] ?? null;
                // Like typolink: a page the anonymous visitor may see (no
                // restriction, or "hide at login") is linked; others only
                // with linkAccessRestrictedPages.
                if ($page === null || (!\in_array($page['fe_group'], ['', '0', '-1'], true) && !$target['linkRestricted'])) {
                    return '';
                }

                return $page['uri'];
            },
        );

        $assignedVars = [];
        $view = $this->createStub(ViewInterface::class);
        $view->method('assignMultiple')->willReturnCallback(
            static function (array $vars) use (&$assignedVars, $view): ViewInterface {
                $assignedVars = $vars;
                return $view;
            },
        );

        $this->injectExtbaseProperties(
            $subject,
            $this->buildExtbaseRequest('main', $siteBase, $languageBases, 'https://main.example/page'),
            $view,
            ['settings' => $settings, 'uriBuilder' => $uriBuilder],
        );

        $subject->indexAction();

        return $assignedVars;
    }

    /**
     * A target is [site identifier, URI typolink builds, fe_group] or an
     * array with the keys site, uri, fe_group, doktype, shortcut,
     * shortcut_mode, pid and extendToSubpages.
     *
     * @param array<int|string, mixed> $target
     *
     * @return array{site: string, uri: string, fe_group: string, doktype: int, shortcut: ?int, shortcut_mode: int, pid: int, extendToSubpages: int}
     */
    private function normalizeTarget(array $target): array
    {
        return [
            'site' => (string) ($target['site'] ?? $target[0]),
            'uri' => (string) ($target['uri'] ?? $target[1] ?? ''),
            'fe_group' => (string) ($target['fe_group'] ?? $target[2] ?? ''),
            'doktype' => (int) ($target['doktype'] ?? PageRepository::DOKTYPE_DEFAULT),
            'shortcut' => isset($target['shortcut']) ? (int) $target['shortcut'] : null,
            'shortcut_mode' => (int) ($target['shortcut_mode'] ?? 0),
            'pid' => (int) ($target['pid'] ?? 1),
            'extendToSubpages' => (int) ($target['extendToSubpages'] ?? 0),
        ];
    }

    /**
     * @param array{site: string, uri: string, fe_group: string, doktype: int, shortcut: ?int, shortcut_mode: int, pid: int, extendToSubpages: int} $target
     *
     * @return array<string, int|string>
     */
    private static function pageRecord(int $uid, array $target): array
    {
        return [
            'uid' => $uid,
            'pid' => $target['pid'],
            'doktype' => $target['doktype'],
            'fe_group' => $target['fe_group'],
            'shortcut_mode' => $target['shortcut_mode'],
            'extendToSubpages' => $target['extendToSubpages'],
        ];
    }

    private function buildController(?SiteFinder $siteFinder = null, ?PageRepository $pageRepository = null): LoginPluginController
    {
        $subject = new LoginPluginController(
            $siteFinder ?? $this->createStub(SiteFinder::class),
            $pageRepository ?? $this->createStub(PageRepository::class),
        );
        $subject->injectResponseFactory(new ResponseFactory());
        $subject->injectStreamFactory(new StreamFactory());
        return $subject;
    }

    /**
     * @param list<string> $languageBases
     */
    private function buildExtbaseRequest(
        string $siteIdentifier,
        string $baseUrl,
        array $languageBases = [],
        ?string $requestUrl = null,
    ): Request {
        $languages = [];
        foreach ($languageBases as $languageId => $languageBase) {
            $languages[] = new SiteLanguage($languageId + 1, 'de_DE.UTF-8', new Uri($languageBase), []);
        }

        $site = $this->createStub(SiteInterface::class);
        $site->method('getIdentifier')->willReturn($siteIdentifier);
        $site->method('getBase')->willReturn(new Uri($baseUrl));
        $site->method('getLanguages')->willReturn($languages);

        $serverRequest = new ServerRequest($requestUrl ?? $baseUrl . '/page', 'GET');
        $serverRequest = $serverRequest->withAttribute('site', $site);
        $serverRequest = $serverRequest->withAttribute(
            'extbase',
            new ExtbaseRequestParameters(LoginPluginController::class),
        );
        return new Request($serverRequest);
    }

    /**
     * @param array<string, mixed> $extraProperties
     */
    private function injectExtbaseProperties(
        LoginPluginController $subject,
        Request $request,
        ViewInterface $view,
        array $extraProperties = [],
    ): void {
        $reflection = new ReflectionClass($subject);

        $requestProp = $reflection->getProperty('request');

        $requestProp->setValue($subject, $request);

        $viewProp = $reflection->getProperty('view');

        $viewProp->setValue($subject, $view);

        foreach ($extraProperties as $name => $value) {
            $prop = $reflection->getProperty($name);

            $prop->setValue($subject, $value);
        }
    }
}
