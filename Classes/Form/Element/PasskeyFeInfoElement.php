<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysFe\Form\Element;

use Netresearch\NrPasskeysFe\Domain\Model\FrontendCredential;
use Netresearch\NrPasskeysFe\Service\FrontendCredentialRepository;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * FormEngine element that renders a read-only list of a frontend user's
 * registered passkeys in the TYPO3 backend fe_users record editor.
 *
 * Mirrors the BE PasskeyInfoElement pattern but operates on fe_users
 * and tx_nrpasskeysfe_credential. No management actions are exposed
 * (revoke is FE self-service only); the element is purely informational
 * for backend editors reviewing a fe_user record.
 *
 * @internal
 */
final class PasskeyFeInfoElement extends AbstractFormElement
{
    public function __construct(
        private readonly FrontendCredentialRepository $credentialRepository,
    ) {}

    /**
     * Set FormEngine data array after DI instantiation.
     *
     * Required for TYPO3 v12/v13 compatibility: NodeFactory uses method_exists()
     * to choose between the DI path (setData) and the legacy constructor path.
     *
     * @param array<string, mixed> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        /** @var array<string, mixed> $resultArray */
        $resultArray = $this->initializeResultArray();

        $tableName = $this->data['tableName'] ?? '';
        if ($tableName !== 'fe_users') {
            return $resultArray;
        }

        $databaseRow = \is_array($this->data['databaseRow'] ?? null)
            ? $this->data['databaseRow']
            : [];
        $rawUid = $databaseRow['uid'] ?? null;
        $userId = \is_numeric($rawUid) ? (int) $rawUid : 0;
        if ($userId === 0) {
            return $resultArray;
        }

        $lang = $this->getLanguageService();

        // Retrieve all credentials for this user (across all sites)
        $credentials = $this->findAllByFeUser($userId);
        $activeCount = \count(\array_filter(
            $credentials,
            static fn(FrontendCredential $credential): bool => !$credential->isRevoked(),
        ));

        // Status badge
        if ($activeCount > 0) {
            $badgeText = $activeCount . ' ' . $this->translate('admin.fe_passkeys.enabled', 'passkey(s) registered');
            $status = '<span class="badge badge-success badge-space-end mb-2">'
                . \htmlspecialchars($badgeText)
                . '</span>';
        } else {
            $status = '<span class="badge badge-danger badge-space-end">'
                . \htmlspecialchars($this->translate('admin.fe_passkeys.none', 'No passkeys'))
                . '</span>';
        }

        $childHtml = [];

        if ($credentials !== []) {
            $childHtml[] = '<ul class="list-group">';
            foreach ($credentials as $credential) {
                $credUid = $credential->getUid();
                $isRevoked = $credential->isRevoked();

                $childHtml[] = '<li class="list-group-item" style="line-height: 2.1em;">';
                $childHtml[] = '<strong>' . \htmlspecialchars($credential->getLabel() ?: 'Passkey #' . $credUid) . '</strong> ';

                if ($isRevoked) {
                    $childHtml[] = '<span class="badge badge-danger">'
                        . \htmlspecialchars($this->translate('admin.fe_passkeys.status.revoked', 'Revoked'))
                        . '</span>';
                } else {
                    $childHtml[] = '<span class="badge badge-success">'
                        . \htmlspecialchars($this->translate('admin.fe_passkeys.status.active', 'Active'))
                        . '</span>';
                }

                // Metadata
                $createdAt = $credential->getCreatedAt();
                $lastUsedAt = $credential->getLastUsedAt();
                $aaguid = $credential->getAaguid();
                $siteIdentifier = $credential->getSiteIdentifier();

                $createdLabel = \htmlspecialchars($this->translate('admin.fe_passkeys.created', 'Created'));
                $lastUsedLabel = \htmlspecialchars($this->translate('admin.fe_passkeys.lastUsed', 'Last used'));
                $neverLabel = \htmlspecialchars($this->translate('admin.fe_passkeys.never', 'Never'));
                $siteLabel = \htmlspecialchars($this->translate('admin.fe_passkeys.site', 'Site'));
                $aaguidLabel = \htmlspecialchars($this->translate('admin.fe_passkeys.aaguid', 'AAGUID'));

                $childHtml[] = '<br><small class="text-body-secondary">';
                $childHtml[] = $createdLabel . ': ' . ($createdAt > 0 ? \htmlspecialchars($this->formatTimestamp($createdAt)) : $neverLabel);
                $childHtml[] = ' &middot; ' . $lastUsedLabel . ': ' . ($lastUsedAt > 0 ? \htmlspecialchars($this->formatTimestamp($lastUsedAt)) : $neverLabel);
                if ($siteIdentifier !== '') {
                    $childHtml[] = ' &middot; ' . $siteLabel . ': ' . \htmlspecialchars($siteIdentifier);
                }
                if ($aaguid !== '') {
                    $childHtml[] = ' &middot; ' . $aaguidLabel . ': <code>' . \htmlspecialchars($aaguid) . '</code>';
                }
                $childHtml[] = '</small>';

                $childHtml[] = '</li>';
            }
            $childHtml[] = '</ul>';
        }

        $fieldId = 't3js-form-field-passkey-fe-id' . StringUtility::getUniqueId('-');

        $html = [];
        $html[] = '<div class="formengine-field-item t3js-formengine-field-item" id="' . \htmlspecialchars($fieldId) . '">';
        $html[] = '<div class="form-control-wrap" style="max-width: ' . $this->formMaxWidth($this->defaultInputWidth) . 'px">';
        $html[] = '<div class="form-wizards-wrap">';
        $html[] = '<div class="form-wizards-item-element">';
        $html[] = \implode(PHP_EOL, $childHtml);
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';

        $resultArray['html'] = $this->wrapWithFieldsetAndLegend($status . \implode(PHP_EOL, $html));

        return $resultArray;
    }

    /**
     * Find all credentials (including revoked) for a frontend user across all sites.
     *
     * Delegates to FrontendCredentialRepository::findAllByFeUser() which returns
     * both active and revoked credentials for a complete history view.
     *
     * @return list<FrontendCredential>
     */
    private function findAllByFeUser(int $feUserUid): array
    {
        return $this->credentialRepository->findAllByFeUser($feUserUid);
    }

    private function formatTimestamp(int $timestamp): string
    {
        $typo3Conf = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sysConf = \is_array($typo3Conf) ? ($typo3Conf['SYS'] ?? null) : null;
        $rawDateFormat = \is_array($sysConf) ? ($sysConf['ddmmyy'] ?? 'Y-m-d') : 'Y-m-d';
        $rawTimeFormat = \is_array($sysConf) ? ($sysConf['hhmm'] ?? 'H:i') : 'H:i';
        $format = \is_string($rawDateFormat) ? $rawDateFormat : 'Y-m-d';
        $timeFormat = \is_string($rawTimeFormat) ? $rawTimeFormat : 'H:i';

        return \date($format . ' ' . $timeFormat, $timestamp);
    }

    private function translate(string $key, string $fallback): string
    {
        $lang = $this->getLanguageService();
        $translated = $lang->sL('LLL:EXT:nr_passkeys_fe/Resources/Private/Language/locallang_db.xlf:' . $key);
        if ($translated !== '') {
            return $translated;
        }

        return $fallback;
    }
}
