-- Demo pages and frontend user for nr_passkeys_fe development environment
-- This file uses @DEMO_PID@ as placeholder for the parent page ID.
-- The install scripts substitute it at import time.
--
-- Plugin content elements (tt_content) are NOT included here because
-- v13 uses CType='list' + list_type while v14 uses CType directly.
-- Each install script adds version-appropriate plugin records after import.
--
-- Reset: ddev install-v13 / ddev install-v14 drops and recreates the database.

-- ---------------------------------------------------------------------------
-- FE Users storage folder (SysFolder, doktype=254)
-- ---------------------------------------------------------------------------
INSERT INTO pages (pid, title, slug, doktype, sorting, tstamp, crdate)
VALUES (@DEMO_PID@, 'FE Users', '/fe-users-storage', 254, 9000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
SET @storage_pid = LAST_INSERT_ID();

-- ---------------------------------------------------------------------------
-- FE User Group
-- ---------------------------------------------------------------------------
INSERT INTO fe_groups (pid, title, passkey_enforcement, tstamp, crdate)
VALUES (@storage_pid, 'Demo Users', 'off', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
SET @group_uid = LAST_INSERT_ID();

-- ---------------------------------------------------------------------------
-- FE User: demo / demo (argon2i hash)
-- ---------------------------------------------------------------------------
INSERT INTO fe_users (pid, username, password, usergroup, email, name, disable, tstamp, crdate)
VALUES (@storage_pid, 'demo',
    '$argon2i$v=19$m=65536,t=4,p=1$Qlp2a0NGRzdRaElSaExmMg$lD1Nn2qzwlxjQiHWXC2cIxprpBrcmDLm/UYJYXcvBZ8',
    CAST(@group_uid AS CHAR), 'demo@example.com', 'Demo User', 0,
    UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- ---------------------------------------------------------------------------
-- Demo Pages
-- ---------------------------------------------------------------------------

-- Login page: felogin + passkey button (injected via template override)
INSERT INTO pages (pid, title, slug, doktype, sorting, tstamp, crdate)
VALUES (@DEMO_PID@, 'Login', '/passkey-login', 1, 9100, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
SET @login_page = LAST_INSERT_ID();

-- Passkey-only login: standalone passkey plugin without felogin fallback
INSERT INTO pages (pid, title, slug, doktype, sorting, tstamp, crdate)
VALUES (@DEMO_PID@, 'Passkey-Only Login', '/passkey-only', 1, 9150, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- My Account: passkey management (fe_group=-2 = "show at any login")
INSERT INTO pages (pid, title, slug, doktype, sorting, fe_group, tstamp, crdate)
VALUES (@DEMO_PID@, 'My Account', '/my-account', 1, 9200, '-2', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- Passkey Setup: enrollment page
INSERT INTO pages (pid, title, slug, doktype, sorting, tstamp, crdate)
VALUES (@DEMO_PID@, 'Passkey Setup', '/passkey-setup', 1, 9300, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- ---------------------------------------------------------------------------
-- Login page: demo credentials hint (version-independent text element)
-- ---------------------------------------------------------------------------
INSERT INTO tt_content (pid, CType, header, bodytext, sorting, colPos, tstamp, crdate)
VALUES (@login_page, 'text', 'Demo Credentials',
    '<div class=\"alert alert-info\">\n<p><strong>Username:</strong> <code>demo</code><br><strong>Password:</strong> <code>demo</code></p>\n<p>After logging in, register a passkey under <a href=\"/my-account\">My Account</a> and use it for future logins.</p>\n</div>',
    200, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
