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
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
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
        $subject = new LoginPluginController($this->createStub(SiteFinder::class));
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
        // A hidden or access-restricted page resolves to its site but builds
        // no link; the login then stays on the current page.
        $vars = $this->renderWithSettings(['redirectAfterLogin' => '11'], targets: [11 => ['main', '']]);

        self::assertNull($vars['redirectUrl']);
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
     * @param array<string, mixed>                   $settings
     * @param array<int, array{string, string}>      $targets  page uid => [site identifier, URI typolink builds]
     *
     * @return array<string, mixed>
     */
    private function renderWithSettings(array $settings, array $targets = []): array
    {
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturnCallback(
            static function (int $pageUid) use ($targets): Site {
                if (!isset($targets[$pageUid])) {
                    throw new SiteNotFoundException('No site for page ' . $pageUid, 1);
                }

                return new Site($targets[$pageUid][0], 1, ['base' => 'https://' . $targets[$pageUid][0] . '.example/']);
            },
        );
        $subject = $this->buildController($siteFinder);

        // The builder links the page last passed to setTargetPageUid().
        $target = new ArrayObject(['uid' => 0]);
        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('reset')->willReturnSelf();
        $uriBuilder->method('setTargetPageUid')->willReturnCallback(
            static function (int $uid) use ($target, $uriBuilder): UriBuilder {
                $target['uid'] = $uid;
                return $uriBuilder;
            },
        );
        $uriBuilder->method('build')->willReturnCallback(
            static fn(): string => $targets[$target['uid']][1] ?? '',
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
            $this->buildExtbaseRequest('main', 'https://main.example'),
            $view,
            ['settings' => $settings, 'uriBuilder' => $uriBuilder],
        );

        $subject->indexAction();

        return $assignedVars;
    }

    private function buildController(?SiteFinder $siteFinder = null): LoginPluginController
    {
        $subject = new LoginPluginController($siteFinder ?? $this->createStub(SiteFinder::class));
        $subject->injectResponseFactory(new ResponseFactory());
        $subject->injectStreamFactory(new StreamFactory());
        return $subject;
    }

    private function buildExtbaseRequest(string $siteIdentifier, string $baseUrl): Request
    {
        $site = $this->createStub(SiteInterface::class);
        $site->method('getIdentifier')->willReturn($siteIdentifier);
        $site->method('getBase')->willReturn(new Uri($baseUrl));

        $serverRequest = new ServerRequest($baseUrl . '/page', 'GET');
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
