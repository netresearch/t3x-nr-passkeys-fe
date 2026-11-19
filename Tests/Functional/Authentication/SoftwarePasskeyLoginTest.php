<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Tests\Functional\Authentication;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use DOMDocument;
use DOMXPath;
use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use Netresearch\NrPasskeysFe\Service\SiteConfigurationService;
use Netresearch\NrPasskeysFe\Tests\AbstractPasskeyFunctionalTestCase;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use Webauthn\AuthenticatorData;

#[CoversNothing]
final class SoftwarePasskeyLoginTest extends AbstractPasskeyFunctionalTestCase
{
    private const SITE = 'https://home.example/';

    #[Test]
    public function aSignedAssertionCreatesAFrontendSessionForTheCredentialOwner(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/fe_users.csv');
        $this->installLoginPage();
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('home');
        self::assertSame(
            'enforced',
            $this
                ->get(SiteConfigurationService::class)
                ->getEnforcementLevel($site),
        );
        self::assertFalse(
            SiteConfigurationService::allowsRecoveryMethod(
                $site,
                'recovery_code',
            ),
        );
        $key = openssl_pkey_new(
            [
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name'       => 'prime256v1',
            ],
        );
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $id         = random_bytes(32);
        $userHandle = 'fixture-user-handle';
        $credential = new FrontendCredential(
            feUser: 99,
            credentialId: $id,
            publicKeyCose: $this->publicKey($key),
            userHandle: $userHandle,
            label: 'Functional test key',
            siteIdentifier: 'home',
            storagePid: 1,
        );
        $this->get(FrontendCredentialRepository::class)->save($credential);
        $page = new InternalRequest(self::SITE);
        $list = $page->withQueryParameters(
            ['eID' => 'nr_passkeys_fe', 'action' => 'manageList'],
        );
        self::assertSame(
            401,
            $this->executeFrontendSubRequest($list)->getStatusCode(),
        );
        $form         = $this->executeFrontendSubRequest($page);
        $requestToken = $this->requestToken($form);
        $options      = $this->json(
            $this->executeFrontendSubRequest(
                $page->withQueryParameters(
                    ['eID' => 'nr_passkeys_fe', 'action' => 'loginOptions'],
                ),
            ),
        );
        self::assertIsArray($options['options']);
        self::assertIsString($options['options']['challenge']);
        self::assertIsString($options['challengeToken']);
        $payload = [
            'assertion' => $this->signedAssertion(
                $key,
                $id,
                $userHandle,
                $options['options']['challenge'],
            ),
            'challengeToken' => $options['challengeToken'],
        ];
        // InternalRequest applies form decoding to an unparsed POST body, including JSON.
        $verify = $page
            ->withQueryParameters(['eID' => 'nr_passkeys_fe', 'action' => 'loginVerify'])
            ->withMethod('POST')
            ->withHeader('Content-Type', 'application/json')
            ->withBody(
                (new StreamFactory())->createStream(
                    json_encode($payload, JSON_THROW_ON_ERROR),
                ),
            )
            ->withParsedBody($payload);
        $verified = $this->json($this->executeFrontendSubRequest($verify));
        self::assertIsString($verified['loginToken']);
        $login = $page
            ->withMethod('POST')
            ->withCookieParams($this->cookies($form))
            ->withParsedBody(
                [
                    'logintype'              => 'login',
                    'user'                   => '__passkey__',
                    RequestToken::PARAM_NAME => $requestToken,
                    'pass'                   => json_encode(
                        [
                            '_type' => 'passkey_token',
                            'token' => $verified['loginToken'],
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ],
            );
        $cookies = $this->cookies($this->executeFrontendSubRequest($login));
        self::assertArrayHasKey('fe_typo_user', $cookies);
        $managed = $this->json(
            $this->executeFrontendSubRequest(
                $list->withCookieParams(
                    ['fe_typo_user' => $cookies['fe_typo_user']],
                ),
            ),
        );
        self::assertSame(1, $managed['count']);
        self::assertSame(
            $credential->getUid(),
            $managed['credentials'][0]['uid'],
        );
    }

    private function installLoginPage(): void
    {
        $this
            ->getConnectionPool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'         => 1,
                    'pid'         => 0,
                    'title'       => 'Member login',
                    'slug'        => '/',
                    'doktype'     => 1,
                    'is_siteroot' => 1,
                ],
            );
        $directory = Environment::getConfigPath() . '/sites/home';
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        copy(
            __DIR__ . '/../Service/Fixtures/home-site.yaml',
            $directory . '/config.yaml',
        );
        $settings = "nr_passkeys_fe:\n  enforcementLevel: enforced\n  enabledRecoveryMethods: []\n";
        file_put_contents($directory . '/settings.yaml', $settings);
        file_put_contents(
            $directory . '/config.yaml',
            "settings:\n  nr_passkeys_fe:\n    enforcementLevel: enforced\n    enabledRecoveryMethods: []\n",
            FILE_APPEND,
        );
        $this->get(SiteConfiguration::class)->siteConfigurationChanged();
        $this->setUpFrontendRootPage(
            1,
            [],
            [
                'config' => <<<'TYPOSCRIPT'
                    @import 'EXT:nr_passkeys_fe/Configuration/TypoScript/setup.typoscript'
                    page = PAGE
                    page.10 = EXTBASEPLUGIN
                    page.10.extensionName = NrPasskeysFe
                    page.10.pluginName = PasskeyLogin
                    TYPOSCRIPT,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode(), $body);
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string, string> */
    private function cookies(ResponseInterface $response): array
    {
        $cookies = [];
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $cookie = Cookie::fromString($header, true);
            $value  = $cookie->getValue();
            if ($value !== null) {
                $cookies[$cookie->getName()] = $value;
            }
        }

        return $cookies;
    }

    private function publicKey(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $ec = $details['ec'];

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(
                NegativeIntegerObject::create(-2),
                ByteStringObject::create(
                    str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT),
                ),
            )
            ->add(
                NegativeIntegerObject::create(-3),
                ByteStringObject::create(
                    str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT),
                ),
            );
    }

    /** @return array<string, mixed> */
    private function signedAssertion(
        OpenSSLAsymmetricKey $key,
        string $id,
        string $userHandle,
        string $challenge,
    ): array {
        $client = json_encode(
            [
                'type'      => 'webauthn.get',
                'origin'    => 'https://home.example',
                'challenge' => $challenge,
            ],
            JSON_THROW_ON_ERROR,
        );
        $authenticator = hash('sha256', 'home.example', true) . chr(AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV) . pack('N', 1);
        self::assertTrue(
            openssl_sign(
                $authenticator . hash('sha256', $client, true),
                $signature,
                $key,
                OPENSSL_ALGO_SHA256,
            ),
        );
        $encodedId = Base64UrlSafe::encodeUnpadded($id);

        return [
            'id'       => $encodedId,
            'rawId'    => $encodedId,
            'type'     => 'public-key',
            'response' => [
                'clientDataJSON'    => Base64UrlSafe::encodeUnpadded($client),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authenticator),
                'signature'         => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle'        => Base64UrlSafe::encodeUnpadded($userHandle),
            ],
        ];
    }

    private function requestToken(ResponseInterface $form): string
    {
        self::assertSame(200, $form->getStatusCode());
        $dom = new DOMDocument();
        self::assertTrue(
            $dom->loadHTML(
                (string) $form->getBody(),
                LIBXML_NOERROR | LIBXML_NOWARNING,
            ),
        );
        $requestToken = (new DOMXPath($dom))->evaluate(
            'string(//form[.//input[@name="logintype" and @value="login"]]//input[@name="' . RequestToken::PARAM_NAME . '"]/@value)',
        );
        self::assertIsString($requestToken);
        self::assertNotSame('', $requestToken);

        return $requestToken;
    }
}
