#!/usr/bin/env php
<?php
/**
 * Sets the felogin FlexForm pi_flexform with proper XML (PDO avoids shell quote stripping).
 *
 * Usage: php fix-felogin-flexform.php <db_name>
 */

$dbName = $argv[1] ?? 'v13';
$pdo = new PDO("mysql:host=db;dbname=$dbName", 'root', 'root');

// Find the FE Users storage folder
$storagePid = $pdo->query("SELECT uid FROM pages WHERE slug='/fe-users-storage' LIMIT 1")->fetchColumn();
if (!$storagePid) {
    echo "WARNING: No /fe-users-storage page found, skipping FlexForm setup\n";
    exit(0);
}

$flexform = <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="settings.pages">
                    <value index="vDEF">$storagePid</value>
                </field>
                <field index="settings.recursive">
                    <value index="vDEF">1</value>
                </field>
            </language>
        </sheet>
        <sheet index="s_redirect">
            <language index="lDEF">
                <field index="settings.redirectMode">
                    <value index="vDEF"></value>
                </field>
                <field index="settings.redirectDisable">
                    <value index="vDEF">0</value>
                </field>
            </language>
        </sheet>
    </data>
</T3FlexForms>
XML;

// Find the felogin content element on the passkey-login page
$loginPageUid = $pdo->query("SELECT uid FROM pages WHERE slug='/passkey-login' LIMIT 1")->fetchColumn();
if (!$loginPageUid) {
    echo "WARNING: No /passkey-login page found\n";
    exit(0);
}

$stmt = $pdo->prepare("UPDATE tt_content SET pi_flexform = ? WHERE CType = 'felogin_login' AND pid = ?");
$stmt->execute([$flexform, $loginPageUid]);
echo "felogin FlexForm set (storagePid=$storagePid, loginPage=$loginPageUid, affected={$stmt->rowCount()})\n";
