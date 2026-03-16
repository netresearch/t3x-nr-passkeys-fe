ARG BASE_IMAGE
FROM $BASE_IMAGE

ENV EXTENSION_KEY "nr_passkeys_fe"
ENV DDEV_SITENAME "nr-passkeys-fe"
ENV GITHUB_URL "https://github.com/netresearch/t3x-nr-passkeys-fe"

# Install PCOV for fast code coverage
RUN apt-get update && apt-get install -y php${PHP_VERSION}-pcov && rm -rf /var/lib/apt/lists/*

# Remove default Apache index.html and create Netresearch-branded landing page
RUN rm -f /var/www/html/index.html
RUN cat > /var/www/html/index.php << 'PHPEOF'
<?php
$gitInfoFile = '/var/www/html/.git-info.json';
$gitInfo = file_exists($gitInfoFile) ? json_decode(file_get_contents($gitInfoFile), true) : [];
$gitBranch = $gitInfo['branch'] ?? 'unknown';
$gitCommit = $gitInfo['commit'] ?? 'unknown';
$prNumber = isset($gitInfo['pr']) && $gitInfo['pr'] !== 'unknown' ? $gitInfo['pr'] : null;
$githubUrl = 'https://github.com/netresearch/t3x-nr-passkeys-fe';
$branchUrl = $githubUrl && $gitBranch !== 'unknown' ? $githubUrl . '/tree/' . urlencode($gitBranch) : null;
$prUrl = $githubUrl && $prNumber ? $githubUrl . '/pull/' . $prNumber : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EXT:nr_passkeys_fe - Dev Environment</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;600;700&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --nr-primary: #2F99A4;
            --nr-accent: #FF4D00;
            --nr-text: #585961;
            --nr-grey: #CCCDCC;
            --nr-bg: #f7f8f8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Open Sans', sans-serif; max-width: 960px; margin: 0 auto; padding: 40px 20px; line-height: 1.6; color: var(--nr-text); background: var(--nr-bg); }
        h1, h2, h3 { font-family: 'Raleway', sans-serif; }
        .header { display: flex; align-items: center; gap: 16px; margin-bottom: 8px; border-bottom: 3px solid var(--nr-primary); padding-bottom: 16px; }
        .header svg { width: 48px; height: 48px; flex-shrink: 0; }
        .header h1 { color: var(--nr-primary); font-size: 1.6em; font-weight: 700; }
        .git-info { font-size: 0.85em; color: var(--nr-text); margin-bottom: 28px; padding: 8px 12px; background: #fff; border-radius: 4px; border-left: 3px solid var(--nr-grey); }
        .git-info a { color: var(--nr-primary); text-decoration: none; font-weight: 600; }
        .git-info a:hover { text-decoration: underline; }
        .git-info .sep { margin: 0 8px; color: var(--nr-grey); }
        .container { background: #fff; padding: 32px; border-radius: 8px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); }
        .card { background: var(--nr-bg); border-left: 4px solid var(--nr-primary); padding: 20px; margin: 16px 0; border-radius: 0 6px 6px 0; }
        .card h3 { color: var(--nr-primary); margin-bottom: 8px; font-size: 1.1em; }
        a { color: var(--nr-primary); text-decoration: none; font-weight: 600; }
        a:hover { text-decoration: underline; }
        code { background: #eef5f6; padding: 2px 6px; border-radius: 3px; font-size: 0.9em; color: var(--nr-text); }
        .links { display: flex; gap: 12px; margin-top: 10px; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 10px 20px; background: var(--nr-primary); color: #fff !important; border-radius: 4px; text-decoration: none !important; font-weight: 600; transition: background 0.2s; font-size: 0.95em; }
        .btn:hover { background: #257d86; }
        .btn-accent { background: var(--nr-accent); }
        .btn-accent:hover { background: #d94200; }
        ul { list-style: none; padding: 0; }
        li { padding: 4px 0; }
        .footer { margin-top: 32px; text-align: center; font-size: 0.85em; color: var(--nr-grey); }
        .footer a { color: var(--nr-text); font-weight: 400; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <svg viewBox="-75 -75 440 440" xmlns="http://www.w3.org/2000/svg"><g><path fill="#2999a4" d="M209.6,0V31.62h32.77a26.38,26.38,0,0,1,26.44,26.43V242a26.38,26.38,0,0,1-26.44,26.44H209.6V300h47.93a42.77,42.77,0,0,0,42.86-42.86V42.89A42.76,42.76,0,0,0,257.53,0ZM43.25,0A42.76,42.76,0,0,0,.39,42.89V257.18A42.76,42.76,0,0,0,43.25,300H91.18V268.46H58.4A26.38,26.38,0,0,1,32,242v-184A26.37,26.37,0,0,1,58.4,31.62H91.18V0Z" transform="translate(-0.39 -0.04)"/><path fill="#595a62" d="M221.44,120.41c0-34.48-13.94-57.82-48.93-57.82-26.62,0-48.54,7.74-64.17,26.56l-.7-22.06-28.31.06V232.94h31.59V124.69c7.14-18.38,32.14-34.8,53-34.5,27.38.4,25.2,26.24,26,45.81v96.94h31.58" transform="translate(-0.39 -0.04)"/></g></svg>
            <h1>EXT:nr_passkeys_fe</h1>
        </div>
        <?php if ($gitBranch !== 'unknown' || $prNumber): ?>
        <div class="git-info">
            <?php if ($branchUrl): ?>
                <a href="<?= htmlspecialchars($branchUrl) ?>" target="_blank"><?= htmlspecialchars($gitBranch) ?><?php if ($gitCommit !== 'unknown'): ?> @ <?= htmlspecialchars($gitCommit) ?><?php endif; ?></a>
            <?php endif; ?>
            <?php if ($prUrl): ?><span class="sep">|</span><a href="<?= htmlspecialchars($prUrl) ?>" target="_blank">PR #<?= htmlspecialchars($prNumber) ?></a><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="card">
            <h3>TYPO3 Environments</h3>
            <p><strong>TYPO3 13.4 LTS</strong></p>
            <div class="links">
                <a href="https://v13.nr-passkeys-fe.ddev.site/" class="btn">v13 Frontend</a>
                <a href="https://v13.nr-passkeys-fe.ddev.site/typo3/" class="btn">v13 Backend</a>
            </div>
            <p style="margin-top:12px"><strong>TYPO3 14.x</strong></p>
            <div class="links">
                <a href="https://v14.nr-passkeys-fe.ddev.site/" class="btn">v14 Frontend</a>
                <a href="https://v14.nr-passkeys-fe.ddev.site/typo3/" class="btn">v14 Backend</a>
            </div>
        </div>
        <div class="card">
            <h3>Demo Pages</h3>
            <ul>
                <li><code>/passkey-login</code> — Login (felogin + passkey plugin)</li>
                <li><code>/my-account</code> — Passkey management (requires login)</li>
                <li><code>/passkey-setup</code> — Passkey enrollment</li>
            </ul>
        </div>
        <div class="card">
            <h3>Credentials</h3>
            <ul>
                <li><strong>BE Admin:</strong> <code>admin</code> / <code>Joh316!!</code></li>
                <li><strong>FE User:</strong> <code>demo</code> / <code>password</code></li>
            </ul>
        </div>
        <div class="card">
            <h3>Quick Commands</h3>
            <ul>
                <li><code>ddev setup</code> — Complete setup (all-in-one)</li>
                <li><code>ddev install-v13</code> / <code>ddev install-v14</code> — Install TYPO3</li>
                <li><code>ddev docs</code> — Render documentation</li>
            </ul>
        </div>
        <div class="card">
            <h3>Documentation</h3>
            <div class="links">
                <a href="https://docs.nr-passkeys-fe.ddev.site/" class="btn btn-accent">View Docs</a>
            </div>
        </div>
    </div>
    <div class="footer">
        <a href="https://www.netresearch.de/">Netresearch DTT GmbH</a>
    </div>
</body>
</html>
PHPEOF

RUN mkdir -p /var/www/html/v13/public/typo3
RUN echo "<h1>Run <code>ddev install-v13</code> first</h1>" > /var/www/html/v13/public/index.html
RUN echo "<h1>Run <code>ddev install-v13</code> first</h1>" > /var/www/html/v13/public/typo3/index.html

RUN mkdir -p /var/www/html/v14/public/typo3
RUN echo "<h1>Run <code>ddev install-v14</code> first</h1>" > /var/www/html/v14/public/index.html
RUN echo "<h1>Run <code>ddev install-v14</code> first</h1>" > /var/www/html/v14/public/typo3/index.html

ARG uid
ARG gid
RUN chown -R $uid:$gid /var/www/html
