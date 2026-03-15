<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Controller\Plugin;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Extbase controller for the PasskeyManagement frontend plugin.
 * Assigns template variables then renders Fluid; management logic runs via eID/JS.
 */
final class ManagementPluginController extends ActionController
{
    public function indexAction(): ResponseInterface
    {
        $site = $this->request->getAttribute('site');
        $baseUrl = rtrim((string)($site?->getBase() ?? ''), '/');
        $eidUrl = $baseUrl . '/?eID=nr_passkeys_fe';

        $this->view->assignMultiple([
            'eidUrl' => $eidUrl,
            'siteIdentifier' => $site?->getIdentifier() ?? '',
            'listUrl' => $eidUrl . '&action=manageList',
            'registerOptionsUrl' => $eidUrl . '&action=registrationOptions',
            'registerVerifyUrl' => $eidUrl . '&action=registrationVerify',
            'renameUrl' => $eidUrl . '&action=manageRename',
            'removeUrl' => $eidUrl . '&action=manageRemove',
            'recoveryGenerateUrl' => $eidUrl . '&action=recoveryGenerate',
        ]);

        return $this->htmlResponse();
    }
}
