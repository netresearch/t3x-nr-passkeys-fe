<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller\Plugin;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Extbase controller for the PasskeyLogin frontend plugin.
 * Assigns template variables for the eID URL and site config,
 * then renders the Fluid template. Actual WebAuthn logic runs via eID/JavaScript.
 */
final class LoginPluginController extends ActionController
{
    public function indexAction(): ResponseInterface
    {
        $site = $this->request->getAttribute('site');
        $siteIdentifier = $site?->getIdentifier() ?? '';
        $baseUrl = rtrim((string)($site?->getBase() ?? ''), '/');
        $eidUrl = $baseUrl . '/?eID=nr_passkeys_fe';

        $this->view->assignMultiple([
            'eidUrl' => $eidUrl,
            'siteIdentifier' => $siteIdentifier,
            'showUsernameField' => true,
            'discoverableEnabled' => (bool)($this->settings['discoverableEnabled'] ?? true),
            'showPasswordFallback' => (bool)($this->settings['showPasswordFallback'] ?? true),
            'passwordFallbackUrl' => $baseUrl . '/passkey-login',
            'recoveryUrl' => $baseUrl . '/passkey-login?recovery=1',
        ]);

        return $this->htmlResponse();
    }
}
