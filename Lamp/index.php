<?php

/**
 * Apache2 VirtualHost Manager v2.0
 * ─────────────────────────────────
 * INSTALLATION:
 *   sudo cp index.php /var/www/html/index.php
 *
 * SUDOERS (/etc/sudoers.d/apache-vhost):
 *   www-data ALL=(ALL) NOPASSWD: /usr/sbin/a2ensite, /usr/sbin/a2dissite, /usr/sbin/apachectl
 *   sudo chmod 440 /etc/sudoers.d/apache-vhost
 *
 * PERMISSIONS (pour créer/modifier des .conf):
 *   sudo chown www-data:www-data /etc/apache2/sites-available
 *   sudo chmod 775 /etc/apache2/sites-available
 */

session_start();

// ─── CONSTANTS ─────────────────────────────────────────────────────────────
define('SITES_AVAILABLE', '/etc/apache2/sites-available');
define('SITES_ENABLED',   '/etc/apache2/sites-enabled');
define('HOSTS_FILE',      '/etc/hosts');
define('APP_VERSION',     '3.0.0');
define('THEMES', ['carbon', 'frost', 'amber', 'dracula', 'nord']);

// ─── THEME ─────────────────────────────────────────────────────────────────
if (isset($_GET['theme']) && in_array($_GET['theme'], THEMES)) {
    setcookie('vhost_theme', $_GET['theme'], time() + 60 * 60 * 24 * 365, '/');
    $_COOKIE['vhost_theme'] = $_GET['theme'];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?themeset=1');
    exit;
}
$theme = $_COOKIE['vhost_theme'] ?? 'carbon';
if (!in_array($theme, THEMES)) $theme = 'carbon';

// ─── HELPERS ───────────────────────────────────────────────────────────────
function parseVhostFile(string $file): array
{
    $content = @file_get_contents($file) ?: '';
    $v = [
        'file' => basename($file),
        'path' => $file,
        'server_name' => '',
        'server_alias' => [],
        'doc_root' => '',
        'port' => '80',
        'ssl' => false,
        'admin_email' => '',
        'raw' => $content,
    ];
    if (preg_match('/<VirtualHost\s+[^:]+:(\d+)/i', $content, $m))  $v['port'] = $m[1];
    if ($v['port'] === '443' || stripos($content, 'SSLEngine on') !== false) $v['ssl'] = true;
    if (preg_match('/ServerName\s+(.+)/i',   $content, $m)) $v['server_name']  = trim($m[1]);
    if (preg_match('/ServerAlias\s+(.+)/i',  $content, $m)) $v['server_alias'] = explode(' ', trim($m[1]));
    if (preg_match('/DocumentRoot\s+"?([^"\n]+)"?/i', $content, $m)) $v['doc_root'] = trim($m[1]);
    if (preg_match('/ServerAdmin\s+(.+)/i',  $content, $m)) $v['admin_email']  = trim($m[1]);
    return $v;
}
function isEnabled(string $filename): bool
{
    $link = SITES_ENABLED . '/' . $filename;
    return file_exists($link) || is_link($link);
}
function getAllVhosts(): array
{
    $vhosts = [];
    foreach (glob(SITES_AVAILABLE . '/*.conf') ?: [] as $f) {
        $v = parseVhostFile($f);
        $v['enabled'] = isEnabled(basename($f));
        $vhosts[] = $v;
    }
    usort($vhosts, fn($a, $b) => strcmp($a['file'], $b['file']));
    return $vhosts;
}
function getApacheStatus(): array
{
    $status  = trim(shell_exec('systemctl is-active apache2 2>/dev/null') ?: '');
    $version = shell_exec('apache2 -v 2>/dev/null') ?: '';
    preg_match('/Apache\/(\S+)/i', $version, $m);
    return ['running' => $status === 'active', 'status' => $status ?: 'unknown', 'version' => $m[1] ?? 'unknown'];
}
function runCommand(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return ['output' => implode("\n", $out), 'code' => $code];
}

// ─── HOSTS HELPERS ─────────────────────────────────────────────────────────
function parseHostsFile(): array
{
    $lines   = file(HOSTS_FILE, FILE_IGNORE_NEW_LINES) ?: [];
    $entries = [];
    foreach ($lines as $i => $line) {
        $trimmed = trim($line);
        $entry = ['idx' => $i, 'raw' => $line, 'type' => 'blank', 'ip' => '', 'hosts' => [], 'comment' => '', 'inline_comment' => ''];
        if ($trimmed === '') {
            $entries[] = $entry;
            continue;
        }
        if (str_starts_with($trimmed, '#')) {
            $entry['type']    = 'comment';
            $entry['comment'] = $trimmed;
            $entries[] = $entry;
            continue;
        }
        // Strip inline comment
        $inline = '';
        if (($ci = strpos($trimmed, ' #')) !== false) {
            $inline  = substr($trimmed, $ci + 1);
            $trimmed = trim(substr($trimmed, 0, $ci));
        }
        $parts = preg_split('/\s+/', $trimmed);
        if (count($parts) >= 2) {
            $entry['type']           = 'host';
            $entry['ip']             = $parts[0];
            $entry['hosts']          = array_slice($parts, 1);
            $entry['inline_comment'] = $inline;
        }
        $entries[] = $entry;
    }
    return $entries;
}

function writeHostsFile(array $entries): bool
{
    $lines = array_map(function ($e) {
        if ($e['type'] === 'comment' || $e['type'] === 'blank') return $e['raw'];
        if ($e['type'] === 'host') {
            $line = $e['ip'] . '    ' . implode(' ', $e['hosts']);
            if ($e['inline_comment']) $line .= '  ' . $e['inline_comment'];
            return $line;
        }
        return $e['raw'];
    }, $entries);
    $content = implode("\n", $lines) . "\n";
    // Écriture via sudo tee
    $tmp = tempnam(sys_get_temp_dir(), 'hosts_');
    file_put_contents($tmp, $content);
    $res = runCommand("sudo tee " . HOSTS_FILE . " < " . escapeshellarg($tmp) . " > /dev/null");
    unlink($tmp);
    return $res['code'] === 0;
}

function hostsEntryExists(array $entries, string $ip, string $host, int $skipIdx = -1): bool
{
    foreach ($entries as $e) {
        if ($e['idx'] === $skipIdx || $e['type'] !== 'host') continue;
        if ($e['ip'] === $ip && in_array($host, $e['hosts'])) return true;
    }
    return false;
}
function sanitizeFilename(string $s): string
{
    $s = preg_replace('/[^a-zA-Z0-9._\-]/', '', $s);
    if (!str_ends_with($s, '.conf')) $s .= '.conf';
    return $s;
}
function generateConf(array $d): string
{
    $port    = intval($d['port'] ?? 80);
    $sn      = trim($d['server_name'] ?? '');
    $sa      = trim($d['server_alias'] ?? '');
    $root    = trim($d['doc_root'] ?? '/var/www/html');
    $admin   = trim($d['admin_email'] ?? 'webmaster@localhost');
    $extra   = trim($d['extra'] ?? '');
    $ssl     = !empty($d['ssl']);
    // Nom utilisé pour les logs et les certs SSL : toujours basé sur ServerName en priorité
    $fname   = trim($d['filename'] ?? '');
    $logname = preg_replace('/[^a-zA-Z0-9\-\.]/i', '', $fname ?: ($sn ?: basename($root)));
    $conf  = "<VirtualHost *:{$port}>\n";
    $conf .= "    ServerAdmin {$admin}\n";
    if ($sn)  $conf .= "    ServerName {$sn}\n";
    if ($sa)  $conf .= "    ServerAlias {$sa}\n";
    $conf .= "    DocumentRoot \"{$root}\"\n\n";
    if ($ssl) {
        $conf .= "    SSLEngine on\n";
        $conf .= "    SSLCertificateFile    /etc/ssl/certs/{$logname}.crt\n";
        $conf .= "    SSLCertificateKeyFile /etc/ssl/private/{$logname}.key\n\n";
    }
    $conf .= "    ErrorLog  \${APACHE_LOG_DIR}/{$logname}-error.log\n";
    $conf .= "    CustomLog \${APACHE_LOG_DIR}/{$logname}-access.log combined\n\n";
    $conf .= "    <Directory \"{$root}\">\n";
    $conf .= "        Options FollowSymLinks\n";
    $conf .= "        AllowOverride All\n";
    $conf .= "        Require all granted\n";
    $conf .= "        DirectoryIndex index.php index.html index.htm\n";
    $conf .= "    </Directory>\n";
    if ($extra) $conf .= "\n    {$extra}\n";
    $conf .= "</VirtualHost>\n";
    return $conf;
}

// ─── ACTIONS ───────────────────────────────────────────────────────────────
$message = null;
$msgType = 'info';

// GET: view raw config
if (isset($_GET['view'])) {
    $f = SITES_AVAILABLE . '/' . sanitizeFilename(basename($_GET['view']));
    if (file_exists($f)) {
        header('Content-Type: text/plain');
        echo @file_get_contents($f);
        exit;
    }
}
// GET: get config for edit
if (isset($_GET['edit_raw'])) {
    $f = SITES_AVAILABLE . '/' . sanitizeFilename(basename($_GET['edit_raw']));
    if (file_exists($f)) {
        header('Content-Type: text/plain');
        echo @file_get_contents($f);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $site   = sanitizeFilename(basename($_POST['site'] ?? '_'));

    switch ($action) {

        case 'enable':
        case 'disable':
            $cmd = $action === 'enable' ? "sudo a2ensite" : "sudo a2dissite";
            $res = runCommand("$cmd " . escapeshellarg($site));
            if ($res['code'] === 0) {
                runCommand("sudo apachectl graceful");
                $message = "✓ Site <strong>$site</strong> " . ($action === 'enable' ? 'activé' : 'désactivé') . ".";
                $msgType = 'success';
            } else {
                $message = "✗ " . htmlspecialchars($res['output']);
                $msgType = 'error';
            }
            break;

        case 'restart':
            $res = runCommand("sudo apachectl restart");
            $message = $res['code'] === 0 ? "✓ Apache2 redémarré." : "✗ " . htmlspecialchars($res['output']);
            $msgType = $res['code'] === 0 ? 'success' : 'error';
            break;

        case 'reload':
            $res = runCommand("sudo apachectl graceful");
            $message = $res['code'] === 0 ? "✓ Apache2 rechargé (graceful)." : "✗ " . htmlspecialchars($res['output']);
            $msgType = $res['code'] === 0 ? 'success' : 'error';
            break;

        case 'create':
            $sn    = trim($_POST['server_name'] ?? '');
            $fn    = preg_replace('/[^a-zA-Z0-9.\-_]/', '', trim($_POST['filename'] ?? ''));
            // Priorité : champ filename > server_name (nettoyé) > fallback horodaté
            if (!$fn) $fn = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $sn);
            if (!$fn) $fn = 'new-site-' . time();
            $fname = $fn . '.conf';
            $fpath = SITES_AVAILABLE . '/' . $fname;
            if (file_exists($fpath)) {
                $message = "✗ Le fichier <strong>$fname</strong> existe déjà.";
                $msgType = 'error';
                break;
            }
            $content = !empty($_POST['raw_mode']) ? ($_POST['raw_content'] ?? '') : generateConf($_POST);
            if (@file_put_contents($fpath, $content) !== false) {
                @chmod($fpath, 0644);
                $message = "✓ VHost <strong>$fname</strong> créé. Activez-le pour l'utiliser.";
                $msgType = 'success';
            } else {
                $message = "✗ Impossible d'écrire dans " . SITES_AVAILABLE . " — vérifiez les permissions.";
                $msgType = 'error';
            }
            break;

        case 'edit_save':
            $fpath   = SITES_AVAILABLE . '/' . $site;
            $content = $_POST['raw_content'] ?? '';
            if (!file_exists($fpath)) {
                $message = "✗ Fichier introuvable.";
                $msgType = 'error';
                break;
            }
            if (@file_put_contents($fpath, $content) !== false) {
                runCommand("sudo apachectl graceful");
                $message = "✓ <strong>$site</strong> sauvegardé et Apache rechargé.";
                $msgType = 'success';
            } else {
                $message = "✗ Impossible d'écrire — vérifiez les permissions.";
                $msgType = 'error';
            }
            break;

        case 'delete':
            $fpath = SITES_AVAILABLE . '/' . $site;
            if (!file_exists($fpath)) {
                $message = "✗ Fichier introuvable.";
                $msgType = 'error';
                break;
            }
            if (isEnabled($site)) {
                runCommand("sudo a2dissite " . escapeshellarg($site));
                runCommand("sudo apachectl graceful");
            }
            if (@unlink($fpath)) {
                $message = "✓ VHost <strong>$site</strong> supprimé.";
                $msgType = 'success';
            } else {
                $message = "✗ Impossible de supprimer — vérifiez les permissions.";
                $msgType = 'error';
            }
            break;

        // ── HOSTS ACTIONS ──────────────────────────────────────────────
        case 'hosts_add':
            $ip      = trim($_POST['h_ip']    ?? '');
            $hosts   = trim($_POST['h_hosts'] ?? '');
            $comment = trim($_POST['h_comment'] ?? '');
            if (!$ip || !$hosts) {
                $message = "✗ IP et nom d'hôte obligatoires.";
                $msgType = 'error';
                break;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $message = "✗ Adresse IP invalide : <strong>" . htmlspecialchars($ip) . "</strong>";
                $msgType = 'error';
                break;
            }
            $hostList = preg_split('/[\s,]+/', $hosts, -1, PREG_SPLIT_NO_EMPTY);
            $entries  = parseHostsFile();
            // Vérifier doublons
            foreach ($hostList as $h) {
                if (hostsEntryExists($entries, $ip, $h)) {
                    $message = "✗ L'entrée <strong>$ip $h</strong> existe déjà.";
                    $msgType = 'error';
                    break 2;
                }
            }
            $newEntry = [
                'idx' => count($entries),
                'raw' => '',
                'type' => 'host',
                'ip' => $ip,
                'hosts' => $hostList,
                'inline_comment' => $comment ? "# $comment" : ''
            ];
            $entries[] = $newEntry;
            if (writeHostsFile($entries)) {
                $message = "✓ Entrée <strong>$ip " . implode(' ', $hostList) . "</strong> ajoutée.";
                $msgType = 'success';
            } else {
                $message = "✗ Impossible d'écrire " . HOSTS_FILE . " — vérifiez les permissions (sudo tee).";
                $msgType = 'error';
            }
            break;

        case 'hosts_edit':
            $idx     = (int)($_POST['h_idx']     ?? -1);
            $ip      = trim($_POST['h_ip']       ?? '');
            $hosts   = trim($_POST['h_hosts']    ?? '');
            $comment = trim($_POST['h_comment']  ?? '');
            if (!$ip || !$hosts || $idx < 0) {
                $message = "✗ Données invalides.";
                $msgType = 'error';
                break;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $message = "✗ Adresse IP invalide.";
                $msgType = 'error';
                break;
            }
            $hostList = preg_split('/[\s,]+/', $hosts, -1, PREG_SPLIT_NO_EMPTY);
            $entries  = parseHostsFile();
            if (!isset($entries[$idx])) {
                $message = "✗ Entrée introuvable.";
                $msgType = 'error';
                break;
            }
            $entries[$idx]['ip']             = $ip;
            $entries[$idx]['hosts']          = $hostList;
            $entries[$idx]['inline_comment'] = $comment ? "# $comment" : '';
            $entries[$idx]['type']           = 'host';
            if (writeHostsFile($entries)) {
                $message = "✓ Entrée modifiée.";
                $msgType = 'success';
            } else {
                $message = "✗ Impossible d'écrire " . HOSTS_FILE . ".";
                $msgType = 'error';
            }
            break;

        case 'hosts_delete':
            $idx     = (int)($_POST['h_idx'] ?? -1);
            $entries = parseHostsFile();
            if ($idx < 0 || !isset($entries[$idx])) {
                $message = "✗ Entrée introuvable.";
                $msgType = 'error';
                break;
            }
            $removed = $entries[$idx]['ip'] . ' ' . implode(' ', $entries[$idx]['hosts']);
            array_splice($entries, $idx, 1);
            // Réindexer
            foreach ($entries as $k => &$e) $e['idx'] = $k;
            if (writeHostsFile($entries)) {
                $message = "✓ Entrée <strong>" . htmlspecialchars($removed) . "</strong> supprimée.";
                $msgType = 'success';
            } else {
                $message = "✗ Impossible d'écrire " . HOSTS_FILE . ".";
                $msgType = 'error';
            }
            break;

        case 'hosts_raw_save':
            $content = $_POST['raw_hosts'] ?? '';
            // Sécurité basique : vérifier que localhost est toujours présent
            if (strpos($content, 'localhost') === false) {
                $message = "✗ Refus : 'localhost' absent du fichier. Entrées système obligatoires.";
                $msgType = 'error';
                break;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'hosts_');
            file_put_contents($tmp, $content);
            $res = runCommand("sudo tee " . HOSTS_FILE . " < " . escapeshellarg($tmp) . " > /dev/null");
            unlink($tmp);
            if ($res['code'] === 0) {
                $message = "✓ " . HOSTS_FILE . " sauvegardé.";
                $msgType = 'success';
            } else {
                $message = "✗ Impossible d'écrire " . HOSTS_FILE . " — vérifiez les permissions (sudo tee).";
                $msgType = 'error';
            }
            break;

        case 'hosts_toggle_comment':
            $idx     = (int)($_POST['h_idx'] ?? -1);
            $entries = parseHostsFile();
            if ($idx < 0 || !isset($entries[$idx])) {
                $message = "✗ Entrée introuvable.";
                $msgType = 'error';
                break;
            }
            $e = &$entries[$idx];
            if ($e['type'] === 'host') {
                // Commenter
                $e['raw']  = '# ' . $e['ip'] . '    ' . implode(' ', $e['hosts']);
                $e['type'] = 'comment';
                $e['comment'] = $e['raw'];
                $message = "✓ Entrée commentée (désactivée).";
                $msgType = 'success';
            } elseif ($e['type'] === 'comment' && preg_match('/^#\s*([\d:.]+)\s+(\S.*)/', $e['comment'], $cm)) {
                // Décommenter
                $parts = preg_split('/\s+/', trim($cm[2]));
                $e['type'] = 'host';
                $e['ip'] = $cm[1];
                $e['hosts'] = $parts;
                $e['inline_comment'] = '';
                $e['raw'] = '';
                $message = "✓ Entrée décommentée (activée).";
                $msgType = 'success';
            } else {
                $message = "✗ Impossible de basculer ce type de ligne.";
                $msgType = 'error';
                break;
            }
            if (!writeHostsFile($entries)) {
                $message = "✗ Impossible d'écrire " . HOSTS_FILE . ".";
                $msgType = 'error';
            }
            break;
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message ?? '') . "&type=$msgType");
    exit;
}

if (isset($_GET['msg']) && $_GET['msg']) {
    $message = urldecode($_GET['msg']);
    $msgType = $_GET['type'] ?? 'info';
}

$vhosts      = getAllVhosts();
$apache      = getApacheStatus();
$enCount     = count(array_filter($vhosts, fn($v) => $v['enabled']));
$diCount     = count($vhosts) - $enCount;
$sslCount    = count(array_filter($vhosts, fn($v) => $v['ssl']));
$hostsEntries = parseHostsFile();
$hostCount   = count(array_filter($hostsEntries, fn($e) => $e['type'] === 'host'));
// Onglet actif
$activeTab   = $_GET['tab'] ?? 'vhosts';

// ─── THEME DEFINITIONS ─────────────────────────────────────────────────────
$themes = [
    'carbon' => [
        'label' => 'Carbon',
        'emoji' => '🖤',
        '--bg' => '#0d0f12',
        '--bg2' => '#131619',
        '--bg3' => '#1a1e23',
        '--border' => '#252a30',
        '--border2' => '#2e3540',
        '--accent' => '#00e5a0',
        '--accent2' => '#00b87a',
        '--red' => '#ff4d6d',
        '--yellow' => '#ffd166',
        '--blue' => '#4da6ff',
        '--text' => '#e2e8f0',
        '--text2' => '#8a96a3',
        '--text3' => '#505a65',
        '--font-ui' => "'Syne', sans-serif",
        '--font-mono' => "'Space Mono', monospace",
        'fonts' => 'https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Syne:wght@400;600;800&display=swap',
    ],
    'frost' => [
        'label' => 'Frost',
        'emoji' => '❄️',
        '--bg' => '#f0f4f8',
        '--bg2' => '#ffffff',
        '--bg3' => '#e8edf3',
        '--border' => '#d1dae6',
        '--border2' => '#b8c6d6',
        '--accent' => '#0066cc',
        '--accent2' => '#0052a3',
        '--red' => '#cc2244',
        '--yellow' => '#c07800',
        '--blue' => '#0066cc',
        '--text' => '#1a2433',
        '--text2' => '#4a6080',
        '--text3' => '#8899aa',
        '--font-ui' => "'Plus Jakarta Sans', sans-serif",
        '--font-mono' => "'IBM Plex Mono', monospace",
        'fonts' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;700&family=Plus+Jakarta+Sans:wght@400;600;800&display=swap',
    ],
    'amber' => [
        'label' => 'Amber',
        'emoji' => '🟡',
        '--bg' => '#0f0a00',
        '--bg2' => '#1a1100',
        '--bg3' => '#221800',
        '--border' => '#2e2000',
        '--border2' => '#3d2c00',
        '--accent' => '#ffb300',
        '--accent2' => '#cc8f00',
        '--red' => '#ff4422',
        '--yellow' => '#ffb300',
        '--blue' => '#66aaff',
        '--text' => '#ffe580',
        '--text2' => '#997a00',
        '--text3' => '#554400',
        '--font-ui' => "'VT323', monospace",
        '--font-mono' => "'VT323', monospace",
        'fonts' => 'https://fonts.googleapis.com/css2?family=VT323&display=swap',
    ],
    'dracula' => [
        'label' => 'Dracula',
        'emoji' => '🧛',
        '--bg' => '#0f0f1a',
        '--bg2' => '#1a1a2e',
        '--bg3' => '#16213e',
        '--border' => '#2a2a4a',
        '--border2' => '#3a3a6a',
        '--accent' => '#bd93f9',
        '--accent2' => '#9d73d9',
        '--red' => '#ff5555',
        '--yellow' => '#f1fa8c',
        '--blue' => '#8be9fd',
        '--text' => '#f8f8f2',
        '--text2' => '#9090c0',
        '--text3' => '#555580',
        '--font-ui' => "'Outfit', sans-serif",
        '--font-mono' => "'Fira Code', monospace",
        'fonts' => 'https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;700&family=Outfit:wght@400;600;800&display=swap',
    ],
    'nord' => [
        'label' => 'Nord',
        'emoji' => '🌨',
        '--bg' => '#242933',
        '--bg2' => '#2e3440',
        '--bg3' => '#3b4252',
        '--border' => '#434c5e',
        '--border2' => '#4c566a',
        '--accent' => '#88c0d0',
        '--accent2' => '#5e81ac',
        '--red' => '#bf616a',
        '--yellow' => '#ebcb8b',
        '--blue' => '#81a1c1',
        '--text' => '#eceff4',
        '--text2' => '#d8dee9',
        '--text3' => '#7a8898',
        '--font-ui' => "'Nunito', sans-serif",
        '--font-mono' => "'JetBrains Mono', monospace",
        'fonts' => 'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Nunito:wght@400;600;800&display=swap',
    ],
];
$t = $themes[$theme];
?>
<!DOCTYPE html>
<html lang="fr" data-theme="<?= $theme ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apache2 VHost Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="<?= $t['fonts'] ?>" rel="stylesheet">
    <style>
        :root {
            <?php foreach ($t as $k => $v) if (str_starts_with($k, '--')) echo "  $k: $v;\n"; ?>--radius: 10px;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        html {
            scroll-behavior: smooth
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-ui);
            min-height: 100vh;
            line-height: 1.6;
            transition: background .3s, color .3s
        }

        /* NOISE */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
            opacity: .35
        }

        .wrap {
            position: relative;
            z-index: 1;
            max-width: 1300px;
            margin: 0 auto;
            padding: 0 24px 80px
        }

        /* ── HEADER ── */
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 26px 0 22px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 28px;
            gap: 14px;
            flex-wrap: wrap
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px
        }

        .logo-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            box-shadow: 0 0 20px color-mix(in srgb, var(--accent) 40%, transparent)
        }

        .logo h1 {
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: -.02em
        }

        .logo span {
            font-family: var(--font-mono);
            font-size: .68rem;
            color: var(--text2)
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap
        }

        /* STATUS BADGE */
        .status-badge {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 7px 13px;
            border-radius: 999px;
            font-family: var(--font-mono);
            font-size: .7rem;
            border: 1px solid var(--border2);
            background: var(--bg2)
        }

        .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--red);
            box-shadow: 0 0 6px var(--red)
        }

        .dot.on {
            background: var(--accent);
            box-shadow: 0 0 8px var(--accent);
            animation: pulse 2s infinite
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .35
            }
        }

        /* THEME SWITCHER */
        .theme-switcher {
            display: flex;
            gap: 5px;
            align-items: center;
            background: var(--bg2);
            border: 1px solid var(--border2);
            border-radius: 999px;
            padding: 4px 8px
        }

        .theme-btn {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 2px solid transparent;
            cursor: pointer;
            transition: transform .15s, border-color .15s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .75rem;
            background: transparent
        }

        .theme-btn:hover {
            transform: scale(1.2)
        }

        .theme-btn.active {
            border-color: var(--accent)
        }

        .theme-swatch-carbon {
            background: #00e5a0
        }

        .theme-swatch-frost {
            background: #0066cc
        }

        .theme-swatch-amber {
            background: #ffb300
        }

        .theme-swatch-dracula {
            background: #bd93f9
        }

        .theme-swatch-nord {
            background: #88c0d0
        }

        /* BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 15px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-family: var(--font-ui);
            font-size: .82rem;
            font-weight: 600;
            transition: all .15s;
            text-decoration: none;
            white-space: nowrap
        }

        .btn-ghost {
            background: transparent;
            color: var(--text2);
            border: 1px solid var(--border2)
        }

        .btn-ghost:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: color-mix(in srgb, var(--accent) 8%, transparent)
        }

        .btn-primary {
            background: var(--accent);
            color: <?= $theme === 'frost' ? '#fff' : '#000' ?>;
            font-weight: 700
        }

        .btn-primary:hover {
            background: var(--accent2);
            box-shadow: 0 0 16px color-mix(in srgb, var(--accent) 35%, transparent)
        }

        .btn-danger {
            background: color-mix(in srgb, var(--red) 12%, transparent);
            color: var(--red);
            border: 1px solid color-mix(in srgb, var(--red) 25%, transparent)
        }

        .btn-danger:hover {
            background: color-mix(in srgb, var(--red) 22%, transparent)
        }

        .btn-success {
            background: color-mix(in srgb, var(--accent) 10%, transparent);
            color: var(--accent);
            border: 1px solid color-mix(in srgb, var(--accent) 25%, transparent)
        }

        .btn-success:hover {
            background: color-mix(in srgb, var(--accent) 20%, transparent)
        }

        .btn-warning {
            background: color-mix(in srgb, var(--yellow) 10%, transparent);
            color: var(--yellow);
            border: 1px solid color-mix(in srgb, var(--yellow) 25%, transparent)
        }

        .btn-warning:hover {
            background: color-mix(in srgb, var(--yellow) 20%, transparent)
        }

        .btn-sm {
            padding: 5px 11px;
            font-size: .76rem
        }

        /* STATS */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 28px
        }

        .stat {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 18px;
            position: relative;
            overflow: hidden
        }

        .stat::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--accent)
        }

        .stat.red::before {
            background: var(--red)
        }

        .stat.blue::before {
            background: var(--blue)
        }

        .stat.yellow::before {
            background: var(--yellow)
        }

        .stat-label {
            font-size: .68rem;
            color: var(--text2);
            text-transform: uppercase;
            letter-spacing: .08em;
            margin-bottom: 5px
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            line-height: 1
        }

        .stat-sub {
            font-family: var(--font-mono);
            font-size: .65rem;
            color: var(--text3);
            margin-top: 3px
        }

        /* TOAST */
        .toast {
            padding: 13px 17px;
            border-radius: var(--radius);
            margin-bottom: 22px;
            font-size: .86rem;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn .3s ease;
            border: 1px solid transparent
        }

        .toast.success {
            background: color-mix(in srgb, var(--accent) 10%, transparent);
            border-color: color-mix(in srgb, var(--accent) 30%, transparent);
            color: var(--accent)
        }

        .toast.error {
            background: color-mix(in srgb, var(--red) 10%, transparent);
            border-color: color-mix(in srgb, var(--red) 30%, transparent);
            color: var(--red)
        }

        .toast.info {
            background: color-mix(in srgb, var(--blue) 10%, transparent);
            border-color: color-mix(in srgb, var(--blue) 30%, transparent);
            color: var(--blue)
        }

        @keyframes slideIn {
            from {
                transform: translateY(-8px);
                opacity: 0
            }

            to {
                transform: translateY(0);
                opacity: 1
            }
        }

        /* TOOLBAR */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 18px;
            flex-wrap: wrap
        }

        .search-box {
            position: relative;
            flex: 1;
            min-width: 180px;
            max-width: 340px
        }

        .search-box input {
            width: 100%;
            background: var(--bg2);
            border: 1px solid var(--border2);
            color: var(--text);
            font-family: var(--font-mono);
            font-size: .8rem;
            padding: 8px 10px 8px 36px;
            border-radius: 8px;
            outline: none;
            transition: border-color .15s
        }

        .search-box input:focus {
            border-color: var(--accent)
        }

        .search-ico {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text3);
            font-size: .85rem;
            pointer-events: none
        }

        .filters {
            display: flex;
            gap: 5px;
            flex-wrap: wrap
        }

        .ftab {
            padding: 6px 13px;
            border-radius: 7px;
            font-size: .76rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--border2);
            background: transparent;
            color: var(--text2);
            transition: all .15s;
            font-family: var(--font-ui)
        }

        .ftab.active,
        .ftab:hover {
            background: var(--accent);
            color: <?= $theme === 'frost' ? '#fff' : '#000' ?>;
            border-color: var(--accent)
        }

        /* VHOST CARDS */
        .vhost-grid {
            display: flex;
            flex-direction: column;
            gap: 9px
        }

        .vhost-card {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: border-color .2s, transform .15s;
            animation: fadeUp .3s ease both
        }

        .vhost-card:hover {
            border-color: var(--border2);
            transform: translateY(-1px)
        }

        .vhost-card.disabled {
            opacity: .6
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(8px)
            }
        }

        .vhost-row {
            display: grid;
            grid-template-columns: 28px 1fr auto auto;
            align-items: center;
            gap: 14px;
            padding: 15px 18px;
            cursor: pointer
        }

        .vind {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--text3);
            justify-self: center
        }

        .vind.on {
            background: var(--accent);
            box-shadow: 0 0 7px var(--accent)
        }

        .vinfo {
            min-width: 0
        }

        .vname {
            font-weight: 700;
            font-size: .92rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .vname a {
            color: var(--text);
            text-decoration: none;
            transition: color .15s
        }

        .vname a:hover {
            color: var(--accent)
        }

        .vmeta {
            font-family: var(--font-mono);
            font-size: .68rem;
            color: var(--text2);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px
        }

        .tags {
            display: flex;
            gap: 5px;
            align-items: center;
            flex-wrap: wrap
        }

        .tag {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 7px;
            border-radius: 5px;
            font-size: .65rem;
            font-family: var(--font-mono);
            font-weight: 700
        }

        .tag-ssl {
            background: color-mix(in srgb, var(--blue) 12%, transparent);
            color: var(--blue);
            border: 1px solid color-mix(in srgb, var(--blue) 20%, transparent)
        }

        .tag-on {
            background: color-mix(in srgb, var(--accent) 10%, transparent);
            color: var(--accent);
            border: 1px solid color-mix(in srgb, var(--accent) 20%, transparent)
        }

        .tag-off {
            background: color-mix(in srgb, var(--text3) 10%, transparent);
            color: var(--text3);
            border: 1px solid var(--border)
        }

        .tag-port {
            background: color-mix(in srgb, var(--yellow) 8%, transparent);
            color: var(--yellow);
            border: 1px solid color-mix(in srgb, var(--yellow) 15%, transparent)
        }

        .vactions {
            display: flex;
            gap: 6px;
            align-items: center
        }

        .chev {
            color: var(--text3);
            transition: transform .2s;
            font-size: .75rem;
            user-select: none
        }

        /* DETAILS */
        .vdetails {
            border-top: 1px solid var(--border);
            padding: 16px 18px 16px 60px;
            background: var(--bg);
            display: none
        }

        .vdetails.open {
            display: block
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 10px
        }

        .di label {
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--text3);
            display: block;
            margin-bottom: 2px
        }

        .di .val {
            font-family: var(--font-mono);
            font-size: .78rem;
            color: var(--text);
            word-break: break-all
        }

        .di .val a {
            color: var(--accent)
        }

        /* MODAL */
        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 100;
            background: rgba(0, 0, 0, .7);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
            padding: 20px
        }

        .overlay.open {
            display: flex
        }

        .modal {
            background: var(--bg2);
            border: 1px solid var(--border2);
            border-radius: 14px;
            width: 100%;
            max-width: 780px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: scaleIn .2s ease
        }

        .modal.wide {
            max-width: 860px
        }

        @keyframes scaleIn {
            from {
                transform: scale(.95);
                opacity: 0
            }
        }

        .modal-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0
        }

        .modal-head h2 {
            font-size: .92rem;
            font-weight: 700
        }

        .modal-body {
            overflow-y: auto;
            padding: 20px;
            flex: 1
        }

        .modal-body::-webkit-scrollbar {
            width: 5px
        }

        .modal-body::-webkit-scrollbar-track {
            background: var(--bg)
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: var(--border2);
            border-radius: 3px
        }

        .modal-foot {
            padding: 14px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            flex-shrink: 0
        }

        /* FORMS */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px
        }

        .form-group.full {
            grid-column: 1/-1
        }

        .form-group label {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--text2);
            font-weight: 600
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            background: var(--bg);
            border: 1px solid var(--border2);
            color: var(--text);
            font-family: var(--font-mono);
            font-size: .82rem;
            padding: 9px 12px;
            border-radius: 8px;
            outline: none;
            transition: border-color .15s;
            width: 100%
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--accent)
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
            line-height: 1.6
        }

        .form-group select option {
            background: var(--bg2)
        }

        .form-hint {
            font-size: .68rem;
            color: var(--text3);
            margin-top: 2px
        }

        .toggle-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0
        }

        .toggle-row label {
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer
        }

        input[type=checkbox] {
            accent-color: var(--accent);
            width: 16px;
            height: 16px;
            cursor: pointer
        }

        .raw-code {
            font-family: var(--font-mono);
            font-size: .78rem;
            color: var(--accent);
            background: var(--bg);
            border: 1px solid var(--border2);
            border-radius: 8px;
            padding: 16px;
            white-space: pre-wrap;
            word-break: break-all;
            line-height: 1.7;
            width: 100%;
            outline: none;
            resize: vertical;
            min-height: 300px
        }

        .mode-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 16px;
            border: 1px solid var(--border2);
            border-radius: 8px;
            overflow: hidden
        }

        .mode-tab {
            flex: 1;
            padding: 9px;
            text-align: center;
            cursor: pointer;
            font-size: .8rem;
            font-weight: 600;
            background: transparent;
            border: none;
            color: var(--text2);
            font-family: var(--font-ui);
            transition: all .15s
        }

        .mode-tab.active {
            background: var(--accent);
            color: <?= $theme === 'frost' ? '#fff' : '#000' ?>
        }

        .sep {
            height: 1px;
            background: var(--border);
            margin: 16px 0
        }

        .danger-zone {
            background: color-mix(in srgb, var(--red) 5%, transparent);
            border: 1px solid color-mix(in srgb, var(--red) 20%, transparent);
            border-radius: var(--radius);
            padding: 16px;
            margin-top: 8px
        }

        .danger-zone h3 {
            font-size: .82rem;
            color: var(--red);
            margin-bottom: 6px
        }

        .danger-zone p {
            font-size: .78rem;
            color: var(--text2);
            margin-bottom: 12px
        }

        /* EMPTY */
        .empty {
            text-align: center;
            padding: 60px 24px;
            border: 1px dashed var(--border2);
            border-radius: var(--radius);
            color: var(--text2)
        }

        .empty .e {
            font-size: 2.5rem;
            margin-bottom: 10px
        }

        /* TABS NAV */
        .tab-nav {
            display: flex;
            gap: 0;
            margin-bottom: 24px;
            border-bottom: 2px solid var(--border)
        }

        .tab-nav-item {
            padding: 11px 22px;
            font-weight: 700;
            font-size: .86rem;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all .15s;
            text-decoration: none;
            color: var(--text2);
            display: flex;
            align-items: center;
            gap: 7px
        }

        .tab-nav-item:hover {
            color: var(--text)
        }

        .tab-nav-item.active {
            color: var(--accent);
            border-bottom-color: var(--accent)
        }

        .tab-badge {
            background: var(--accent);
            color: #000;
            border-radius: 999px;
            padding: 1px 7px;
            font-size: .65rem;
            font-weight: 800
        }

        .tab-pane {
            display: none
        }

        .tab-pane.active {
            display: block
        }

        /* HOSTS TABLE */
        .htable {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem
        }

        .htable thead tr {
            border-bottom: 2px solid var(--border2)
        }

        .htable th {
            padding: 9px 12px;
            text-align: left;
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--text3);
            font-weight: 700;
            white-space: nowrap
        }

        .htable tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .1s
        }

        .htable tbody tr:hover {
            background: color-mix(in srgb, var(--accent) 4%, transparent)
        }

        .htable tbody tr.is-comment {
            opacity: .45
        }

        .htable tbody tr.is-comment:hover {
            background: color-mix(in srgb, var(--yellow) 4%, transparent)
        }

        .htable td {
            padding: 9px 12px;
            vertical-align: middle
        }

        .htable td:first-child {
            font-family: var(--font-mono);
            font-weight: 700;
            color: var(--blue);
            white-space: nowrap
        }

        .host-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: color-mix(in srgb, var(--accent) 8%, transparent);
            border: 1px solid color-mix(in srgb, var(--accent) 18%, transparent);
            color: var(--accent);
            padding: 2px 8px;
            border-radius: 5px;
            font-family: var(--font-mono);
            font-size: .72rem;
            margin: 2px
        }

        .host-pill.local {
            background: color-mix(in srgb, var(--blue) 8%, transparent);
            border-color: color-mix(in srgb, var(--blue) 18%, transparent);
            color: var(--blue)
        }

        .htable-actions {
            display: flex;
            gap: 5px;
            align-items: center;
            white-space: nowrap
        }

        .hcomment-badge {
            font-family: var(--font-mono);
            font-size: .68rem;
            color: var(--text3);
            font-style: italic
        }

        .hosts-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap
        }

        .hosts-search {
            position: relative;
            flex: 1;
            min-width: 160px;
            max-width: 300px
        }

        .hosts-search input {
            width: 100%;
            background: var(--bg2);
            border: 1px solid var(--border2);
            color: var(--text);
            font-family: var(--font-mono);
            font-size: .8rem;
            padding: 8px 10px 8px 34px;
            border-radius: 8px;
            outline: none;
            transition: border-color .15s
        }

        .hosts-search input:focus {
            border-color: var(--accent)
        }

        .hosts-search .sico {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text3);
            pointer-events: none
        }

        .table-wrap {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden
        }

        .table-wrap table {
            margin: 0
        }

        /* FOOTER */
        footer {
            margin-top: 44px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .7rem;
            color: var(--text3);
            font-family: var(--font-mono);
            flex-wrap: wrap;
            gap: 8px
        }

        @media(max-width:640px) {
            .vhost-row {
                grid-template-columns: 20px 1fr auto
            }

            .vactions .btn-sm:not([data-keep]) {
                display: none
            }

            .stat-value {
                font-size: 1.4rem
            }
        }
    </style>
</head>

<body>
    <div class="wrap">

        <!-- HEADER -->
        <header>
            <div class="logo">
                <div class="logo-icon">⚡</div>
                <div>
                    <h1>VHost Manager</h1>
                    <span>Apache2 · Ubuntu · v<?= APP_VERSION ?></span>
                </div>
            </div>
            <div class="header-right">
                <!-- THEME SWITCHER -->
                <div class="theme-switcher" title="Choisir un thème">
                    <?php foreach ($themes as $tid => $td): ?>
                        <a href="?theme=<?= $tid ?>" class="theme-btn <?= $tid === $theme ? 'active' : '' ?>" title="<?= $td['label'] ?>">
                            <span class="theme-swatch-<?= $tid ?>" style="width:14px;height:14px;border-radius:50%;display:block;background:<?= explode(';', $td['--accent'])[0] ?>"></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="status-badge">
                    <div class="dot <?= $apache['running'] ? 'on' : '' ?>"></div>
                    Apache <?= htmlspecialchars($apache['version']) ?> · <?= htmlspecialchars($apache['status']) ?>
                </div>

                <form method="POST" style="display:contents">
                    <input type="hidden" name="site" value="apache2">
                    <button type="submit" name="action" value="reload" class="btn btn-ghost btn-sm">↺ Reload</button>
                    <button type="submit" name="action" value="restart" class="btn btn-ghost btn-sm">⟳ Restart</button>
                </form>
                <button class="btn btn-primary" id="headerAddBtn"
                    onclick="activeTab==='hosts'?openHostAdd():openCreate()">＋ Nouveau VHost</button>
            </div>
        </header>

        <!-- TOAST -->
        <?php if ($message): ?>
            <div class="toast <?= htmlspecialchars($msgType) ?>">
                <?= $msgType === 'success' ? '✓' : ($msgType === 'error' ? '✗' : 'ℹ') ?> <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- STATS -->
        <div class="stats">
            <div class="stat">
                <div class="stat-label">Total</div>
                <div class="stat-value"><?= count($vhosts) ?></div>
                <div class="stat-sub">sites-available/</div>
            </div>
            <div class="stat">
                <div class="stat-label">Actifs</div>
                <div class="stat-value" style="color:var(--accent)"><?= $enCount ?></div>
                <div class="stat-sub">sites-enabled/</div>
            </div>
            <div class="stat red">
                <div class="stat-label">Inactifs</div>
                <div class="stat-value" style="color:var(--red)"><?= $diCount ?></div>
                <div class="stat-sub">désactivés</div>
            </div>
            <div class="stat blue">
                <div class="stat-label">Statut</div>
                <div class="stat-value" style="color:var(--blue);font-size:1rem;margin-top:5px"><?= $apache['running'] ? '● En ligne' : '○ Offline' ?></div>
                <div class="stat-sub">apache2.service</div>
            </div>
            <div class="stat yellow">
                <div class="stat-label">SSL / HTTPS</div>
                <div class="stat-value" style="color:var(--yellow)"><?= $sslCount ?></div>
                <div class="stat-sub">port 443</div>
            </div>
        </div>

        <!-- TAB NAVIGATION -->
        <nav class="tab-nav">
            <a href="?tab=vhosts" class="tab-nav-item <?= $activeTab === 'vhosts' ? 'active' : '' ?>">
                🌐 VirtualHosts <span class="tab-badge"><?= count($vhosts) ?></span>
            </a>
            <a href="?tab=hosts" class="tab-nav-item <?= $activeTab === 'hosts' ? 'active' : '' ?>">
                📋 DNS / Hosts <span class="tab-badge" style="background:var(--blue);color:#fff"><?= $hostCount ?></span>
            </a>
        </nav>

        <!-- ══════════════ TAB: VHOSTS ══════════════ -->
        <div class="tab-pane <?= $activeTab === 'vhosts' ? 'active' : '' ?>" id="tab-vhosts">
            <div class="toolbar">
                <div class="search-box">
                    <span class="search-ico">🔍</span>
                    <input type="text" id="search" placeholder="Rechercher…" autocomplete="off" oninput="filterV()">
                </div>
                <div class="filters">
                    <button class="ftab active" onclick="setF('all',this)">Tous</button>
                    <button class="ftab" onclick="setF('enabled',this)">Actifs</button>
                    <button class="ftab" onclick="setF('disabled',this)">Inactifs</button>
                    <button class="ftab" onclick="setF('ssl',this)">SSL</button>
                </div>
            </div>

            <!-- VHOST LIST -->
            <div class="vhost-grid" id="grid">
                <?php if (empty($vhosts)): ?>
                    <div class="empty">
                        <div class="e">🗂</div>
                        <p>Aucun VirtualHost trouvé dans sites-available/</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($vhosts as $i => $v): ?>
                        <?php
                        $scheme = $v['ssl'] ? 'https' : 'http';
                        $host   = $v['server_name'] ?: $v['file'];
                        $url    = $scheme . '://' . $host . ($v['port'] !== '80' && $v['port'] !== '443' ? ':' . $v['port'] : '');
                        ?>
                        <div class="vhost-card <?= $v['enabled'] ? '' : 'disabled' ?>"
                            data-en="<?= $v['enabled'] ? 1 : 0 ?>"
                            data-ssl="<?= $v['ssl'] ? 1 : 0 ?>"
                            data-q="<?= strtolower(htmlspecialchars($v['server_name'] . ' ' . $v['file'] . ' ' . $v['doc_root'])) ?>"
                            style="animation-delay:<?= $i * 35 ?>ms">

                            <div class="vhost-row" onclick="toggleD('d<?= $i ?>','c<?= $i ?>')">
                                <div class="vind <?= $v['enabled'] ? 'on' : '' ?>"></div>
                                <div class="vinfo">
                                    <div class="vname">
                                        <a href="<?= htmlspecialchars($url) ?>" target="_blank" onclick="event.stopPropagation()">
                                            <?= htmlspecialchars($host) ?>
                                        </a>
                                    </div>
                                    <div class="vmeta">📄 <?= htmlspecialchars($v['file']) ?><?= $v['doc_root'] ? ' &nbsp;·&nbsp; 📁 ' . htmlspecialchars($v['doc_root']) : '' ?></div>
                                </div>
                                <div class="tags">
                                    <?php if ($v['ssl']): ?><span class="tag tag-ssl">🔒 SSL</span><?php endif; ?>
                                    <span class="tag tag-port">:<?= htmlspecialchars($v['port']) ?></span>
                                    <span class="tag <?= $v['enabled'] ? 'tag-on' : 'tag-off' ?>"><?= $v['enabled'] ? '● ON' : '○ OFF' ?></span>
                                </div>
                                <div class="vactions" onclick="event.stopPropagation()">
                                    <button class="btn btn-ghost btn-sm" title="Voir config" onclick="viewCfg('<?= htmlspecialchars($v['file']) ?>')">&#60;/&#62;</button>
                                    <button class="btn btn-warning btn-sm" title="Éditer" onclick="openEdit('<?= htmlspecialchars($v['file']) ?>')">✎</button>
                                    <?php if ($v['enabled']): ?>
                                        <form method="POST" style="display:contents">
                                            <input type="hidden" name="site" value="<?= htmlspecialchars($v['file']) ?>">
                                            <button type="submit" name="action" value="disable" class="btn btn-danger btn-sm" data-keep="1"
                                                onclick="return confirm('Désactiver <?= htmlspecialchars($host) ?> ?')">✕</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:contents">
                                            <input type="hidden" name="site" value="<?= htmlspecialchars($v['file']) ?>">
                                            <button type="submit" name="action" value="enable" class="btn btn-success btn-sm" data-keep="1">✓</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <span class="chev" id="c<?= $i ?>">▼</span>
                            </div>

                            <div class="vdetails" id="d<?= $i ?>">
                                <div class="detail-grid">
                                    <div class="di"><label>ServerName</label>
                                        <div class="val"><?= $v['server_name'] ? '<a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars($v['server_name']) . '</a>' : '—' ?></div>
                                    </div>
                                    <?php if ($v['server_alias']): ?><div class="di"><label>ServerAlias</label>
                                            <div class="val"><?= htmlspecialchars(implode(', ', $v['server_alias'])) ?></div>
                                        </div><?php endif; ?>
                                    <div class="di"><label>DocumentRoot</label>
                                        <div class="val"><?= $v['doc_root'] ? htmlspecialchars($v['doc_root']) : '—' ?></div>
                                    </div>
                                    <div class="di"><label>Port</label>
                                        <div class="val"><?= htmlspecialchars($v['port']) ?></div>
                                    </div>
                                    <div class="di"><label>SSL</label>
                                        <div class="val"><?= $v['ssl'] ? '✓ Activé' : '✗ Non' ?></div>
                                    </div>
                                    <div class="di"><label>Fichier</label>
                                        <div class="val"><?= htmlspecialchars(SITES_AVAILABLE . '/' . $v['file']) ?></div>
                                    </div>
                                    <?php if ($v['admin_email']): ?><div class="di"><label>ServerAdmin</label>
                                            <div class="val"><?= htmlspecialchars($v['admin_email']) ?></div>
                                        </div><?php endif; ?>
                                    <div class="di"><label>Statut</label>
                                        <div class="val"><?= $v['enabled'] ? '<span style="color:var(--accent)">● Activé</span>' : '<span style="color:var(--text3)">○ Désactivé</span>' ?></div>
                                    </div>
                                </div>
                                <div style="margin-top:14px;display:flex;gap:8px">
                                    <button class="btn btn-warning btn-sm" onclick="openEdit('<?= htmlspecialchars($v['file']) ?>')">✎ Modifier</button>
                                    <button class="btn btn-danger btn-sm" onclick="confirmDelete('<?= htmlspecialchars($v['file']) ?>','<?= htmlspecialchars($host) ?>')">🗑 Supprimer</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div><!-- /vhost-grid -->
        </div><!-- /tab-pane vhosts -->

        <!-- ══════════════ TAB: DNS / HOSTS ══════════════ -->
        <div class="tab-pane <?= $activeTab === 'hosts' ? 'active' : '' ?>" id="tab-hosts">

            <div class="hosts-toolbar">
                <div class="hosts-search">
                    <span class="sico">🔍</span>
                    <input type="text" id="hostsSearch" placeholder="Filtrer IP ou hostname…" oninput="filterHosts()">
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <span style="font-family:var(--font-mono);font-size:.72rem;color:var(--text3)">
                        📄 <?= HOSTS_FILE ?>
                    </span>
                    <button class="btn btn-warning btn-sm" onclick="openHostsRaw()">✎ Éditer brut</button>
                    <button class="btn btn-primary btn-sm" onclick="openHostAdd()">＋ Ajouter entrée</button>
                </div>
            </div>

            <div class="table-wrap">
                <table class="htable">
                    <thead>
                        <tr>
                            <th>Adresse IP</th>
                            <th>Noms d'hôtes</th>
                            <th>Commentaire</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="hostsTbody">
                        <?php foreach ($hostsEntries as $e): ?>
                            <?php if ($e['type'] === 'blank') continue; ?>
                            <?php if ($e['type'] === 'comment'): ?>
                                <?php
                                // Afficher les commentaires qui ressemblent à des sections (## Section ##)
                                $isSection = preg_match('/^#{1,2}\s*\S/', $e['comment']);
                                ?>
                                <tr class="is-comment" data-hq="<?= htmlspecialchars(strtolower($e['comment'])) ?>">
                                    <td colspan="3">
                                        <span class="hcomment-badge"><?= htmlspecialchars($e['comment']) ?></span>
                                    </td>
                                    <td class="htable-actions">
                                        <?php
                                        // Si c'est une entrée commentée (IP désactivée), proposer de la réactiver
                                        if (preg_match('/^#\s*([\d:.]+)\s+\S/', $e['comment'])):
                                        ?>
                                            <form method="POST" style="display:contents">
                                                <input type="hidden" name="action" value="hosts_toggle_comment">
                                                <input type="hidden" name="h_idx" value="<?= $e['idx'] ?>">
                                                <input type="hidden" name="tab" value="hosts">
                                                <button type="submit" class="btn btn-success btn-sm" title="Activer">▶ Activer</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $isLocal = in_array($e['ip'], ['127.0.0.1', '::1', '127.0.1.1']);
                                ?>
                                <tr data-hq="<?= htmlspecialchars(strtolower($e['ip'] . ' ' . implode(' ', $e['hosts']))) ?>">
                                    <td>
                                        <?php if ($isLocal): ?>
                                            <span style="color:var(--yellow)"><?= htmlspecialchars($e['ip']) ?></span>
                                        <?php else: ?>
                                            <?= htmlspecialchars($e['ip']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php foreach ($e['hosts'] as $h): ?>
                                            <span class="host-pill <?= $isLocal ? 'local' : '' ?>"><?= htmlspecialchars($h) ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td><span class="hcomment-badge"><?= htmlspecialchars(ltrim($e['inline_comment'], '# ')) ?></span></td>
                                    <td class="htable-actions">
                                        <button class="btn btn-ghost btn-sm" title="Modifier"
                                            onclick="openHostEdit(<?= $e['idx'] ?>, '<?= htmlspecialchars($e['ip']) ?>', '<?= htmlspecialchars(implode(' ', $e['hosts'])) ?>', '<?= htmlspecialchars(ltrim($e['inline_comment'], '# ')) ?>')">✎</button>
                                        <form method="POST" style="display:contents">
                                            <input type="hidden" name="action" value="hosts_toggle_comment">
                                            <input type="hidden" name="h_idx" value="<?= $e['idx'] ?>">
                                            <input type="hidden" name="tab" value="hosts">
                                            <button type="submit" class="btn btn-warning btn-sm" title="Commenter / Désactiver">⏸</button>
                                        </form>
                                        <button class="btn btn-danger btn-sm" title="Supprimer"
                                            onclick="confirmHostDelete(<?= $e['idx'] ?>, '<?= htmlspecialchars($e['ip']) ?>', '<?= htmlspecialchars(implode(' ', $e['hosts'])) ?>')">🗑</button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div><!-- /tab-pane hosts -->

        <footer>
            <span>Apache2 VHost Manager · <?= htmlspecialchars($_SERVER['SERVER_NAME'] ?? 'localhost') ?></span>
            <span><?= date('d/m/Y H:i') ?> · PHP <?= PHP_VERSION ?> · Thème: <?= ucfirst($theme) ?></span>
        </footer>
    </div><!-- /wrap -->

    <!-- ════════════════════════════ MODALS ════════════════════════════ -->

    <!-- VIEW CONFIG -->
    <div class="overlay" id="viewModal" onclick="closeOverlay('viewModal',event)">
        <div class="modal">
            <div class="modal-head">
                <h2 id="viewTitle">Configuration</h2><button class="btn btn-ghost btn-sm" onclick="closeOverlay('viewModal')">✕</button>
            </div>
            <div class="modal-body"><textarea id="viewCode" class="raw-code" readonly style="min-height:340px">Chargement…</textarea></div>
        </div>
    </div>

    <!-- CREATE VHOST -->
    <div class="overlay" id="createModal" onclick="closeOverlay('createModal',event)">
        <div class="modal wide">
            <div class="modal-head">
                <h2>＋ Nouveau VirtualHost</h2><button class="btn btn-ghost btn-sm" onclick="closeOverlay('createModal')">✕</button>
            </div>
            <div class="modal-body">
                <div class="mode-tabs">
                    <button class="mode-tab active" onclick="setMode('guided','createModal',this)">🧙 Guidé</button>
                    <button class="mode-tab" onclick="setMode('raw','createModal',this)">📄 Brut</button>
                </div>
                <form method="POST" id="createForm">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="raw_mode" id="createRawMode" value="">

                    <!-- GUIDED -->
                    <div id="createGuided" class="form-grid">
                        <div class="form-group">
                            <label>ServerName *</label>
                            <input type="text" name="server_name" id="guidedServerName" placeholder="mon-site.local" required
                                oninput="autoFillFilename(this.value)">
                            <span class="form-hint">Nom de domaine principal (ex: web.gabonia.com)</span>
                        </div>
                        <div class="form-group">
                            <label>ServerAlias</label>
                            <input type="text" name="server_alias" placeholder="www.mon-site.local alias.local">
                            <span class="form-hint">Séparés par des espaces</span>
                        </div>
                        <div class="form-group full">
                            <label>DocumentRoot *</label>
                            <input type="text" name="doc_root" placeholder="/var/www/html/mon-site" required>
                        </div>
                        <div class="form-group">
                            <label>Port</label>
                            <select name="port">
                                <option value="80">80 (HTTP)</option>
                                <option value="443">443 (HTTPS)</option>
                                <option value="8080">8080</option>
                                <option value="8443">8443</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>ServerAdmin</label>
                            <input type="text" name="admin_email" placeholder="webmaster@localhost" value="webmaster@localhost">
                        </div>
                        <div class="form-group full">
                            <div class="toggle-row">
                                <input type="checkbox" name="ssl" id="sslCheck" value="1">
                                <label for="sslCheck">🔒 Activer SSL (SSLEngine on)</label>
                            </div>
                        </div>
                        <div class="form-group full">
                            <label>Directives supplémentaires (optionnel)</label>
                            <textarea name="extra" placeholder="# Ex: ProxyPass, Alias, etc."></textarea>
                        </div>
                        <div class="form-group full">
                            <label>Nom du fichier .conf <span style="color:var(--text3);font-weight:400">(auto-rempli, modifiable)</span></label>
                            <div style="display:flex;align-items:center;gap:8px">
                                <input type="text" name="filename" id="guidedFilename" placeholder="mon-site"
                                    style="font-family:var(--font-mono)"
                                    oninput="updateFilenamePreview(this.value)">
                                <span style="font-family:var(--font-mono);color:var(--text3);font-size:.82rem;white-space:nowrap">.conf</span>
                            </div>
                            <span class="form-hint" id="filenamePreview" style="font-family:var(--font-mono);color:var(--accent)">—</span>
                        </div>
                    </div>

                    <!-- RAW -->
                    <div id="createRaw" style="display:none">
                        <div class="form-group">
                            <label>Contenu du fichier .conf</label>
                            <textarea name="raw_content" id="createRawContent" class="raw-code" style="min-height:340px" placeholder="<VirtualHost *:80>
    ServerName mon-site.local
    DocumentRoot &quot;/var/www/html/mon-site&quot;
    …
</VirtualHost>"></textarea>
                        </div>
                        <div class="sep"></div>
                        <div class="form-group">
                            <label>Nom du fichier <span style="color:var(--text3);font-weight:400">(sans .conf)</span></label>
                            <div style="display:flex;align-items:center;gap:8px">
                                <input type="text" name="filename" id="createRawName" placeholder="mon-site"
                                    style="max-width:300px;font-family:var(--font-mono)">
                                <span style="font-family:var(--font-mono);color:var(--text3);font-size:.82rem">.conf</span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-foot">
                <button class="btn btn-ghost" onclick="closeOverlay('createModal')">Annuler</button>
                <button class="btn btn-primary" onclick="document.getElementById('createForm').submit()">＋ Créer le VHost</button>
            </div>
        </div>
    </div>

    <!-- EDIT VHOST -->
    <div class="overlay" id="editModal" onclick="closeOverlay('editModal',event)">
        <div class="modal wide">
            <div class="modal-head">
                <h2 id="editTitle">✎ Modifier</h2><button class="btn btn-ghost btn-sm" onclick="closeOverlay('editModal')">✕</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editForm">
                    <input type="hidden" name="action" value="edit_save">
                    <input type="hidden" name="site" id="editSite">
                    <div class="form-group">
                        <label>Contenu du fichier .conf</label>
                        <textarea name="raw_content" id="editCode" class="raw-code" style="min-height:380px">Chargement…</textarea>
                    </div>
                    <div class="sep"></div>
                    <div class="danger-zone">
                        <h3>⚠ Zone de danger</h3>
                        <p>Supprimer définitivement ce VirtualHost (désactivation automatique si actif).</p>
                        <button type="button" class="btn btn-danger btn-sm" id="editDeleteBtn">🗑 Supprimer ce VHost</button>
                    </div>
                </form>
            </div>
            <div class="modal-foot">
                <button class="btn btn-ghost" onclick="closeOverlay('editModal')">Annuler</button>
                <button class="btn btn-primary" onclick="document.getElementById('editForm').submit()">💾 Sauvegarder</button>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRM -->
    <div class="overlay" id="deleteModal" onclick="closeOverlay('deleteModal',event)">
        <div class="modal" style="max-width:440px">
            <div class="modal-head">
                <h2>🗑 Confirmer la suppression</h2><button class="btn btn-ghost btn-sm" onclick="closeOverlay('deleteModal')">✕</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:14px;font-size:.9rem">Voulez-vous vraiment supprimer :</p>
                <div style="background:var(--bg);border:1px solid var(--border2);border-radius:8px;padding:12px;font-family:var(--font-mono);margin-bottom:16px">
                    <strong id="delSiteName" style="color:var(--red)"></strong><br>
                    <span id="delFileName" style="font-size:.75rem;color:var(--text2)"></span>
                </div>
                <p style="font-size:.82rem;color:var(--text2)">Cette action est <strong>irréversible</strong>. Le fichier .conf sera supprimé.</p>
            </div>
            <div class="modal-foot">
                <form method="POST" id="deleteForm">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="site" id="deleteFile">
                </form>
                <button class="btn btn-ghost" onclick="closeOverlay('deleteModal')">Annuler</button>
                <button class="btn btn-danger" onclick="document.getElementById('deleteForm').submit()">🗑 Supprimer définitivement</button>
            </div>
        </div>
    </div>

    <!-- ADD HOST -->
    <div class="overlay" id="hostAddModal" onclick="closeOverlay('hostAddModal',event)">
        <div class="modal" style="max-width:560px">
            <div class="modal-head">
                <h2>＋ Ajouter une entrée DNS</h2><button class="btn btn-ghost btn-sm" onclick="closeOverlay('hostAddModal')">✕</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="hostAddForm">
                    <input type="hidden" name="action" value="hosts_add">
                    <input type="hidden" name="tab" value="hosts">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Adresse IP *</label>
                            <input type="text" name="h_ip" id="haIp" placeholder="127.0.0.1" required>
                            <span class="form-hint">IPv4 ou IPv6</span>
                        </div>
                        <div class="form-group">
                            <label>Nom(s) d'hôte *</label>
                            <input type="text" name="h_hosts" id="haHosts" placeholder="mon-site.local www.mon-site.local" required>
                            <span class="form-hint">Séparés par des espaces</span>
                        </div>
                        <div class="form-group full">
                            <label>Commentaire <span style="color:var(--text3);font-weight:400">(optionnel)</span></label>
                            <input type="text" name="h_comment" placeholder="Mon projet local">
                        </div>
                    </div>
                    <div class="sep"></div>
                    <div style="background:var(--bg);border:1px solid var(--border2);border-radius:8px;padding:11px 14px">
                        <div style="font-size:.68rem;color:var(--text3);margin-bottom:5px;text-transform:uppercase;letter-spacing:.07em">Prévisualisation</div>
                        <div id="haPreview" style="font-family:var(--font-mono);font-size:.8rem;color:var(--accent)">—</div>
                    </div>
                    <div class="sep"></div>
                    <div style="background:color-mix(in srgb,var(--blue) 6%,transparent);border:1px solid color-mix(in srgb,var(--blue) 20%,transparent);border-radius:8px;padding:12px;font-size:.78rem;color:var(--text2)">
                        <strong style="color:var(--blue)">💡 Astuce :</strong> Pour accéder à vos VHosts depuis ce PC, utilisez <code style="font-family:var(--font-mono);color:var(--accent)">127.0.0.1</code>. Pour les autres PC du réseau, utilisez l'IP LAN du serveur.
                    </div>
                </form>
            </div>
            <div class="modal-foot">
                <button class="btn btn-ghost" onclick="closeOverlay('hostAddModal')">Annuler</button>
                <button class="btn btn-primary" onclick="document.getElementById('hostAddForm').submit()">＋ Ajouter</button>
            </div>
        </div>
    </div>

    <!-- EDIT HOST -->
    <div class="overlay" id="hostEditModal" onclick="closeOverlay('hostEditModal',event)">
        <div class="modal" style="max-width:560px">
            <div class="modal-head">
                <h2>✎ Modifier une entrée DNS</h2><button class="btn btn-ghost btn-sm" onclick="closeOverlay('hostEditModal')">✕</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="hostEditForm">
                    <input type="hidden" name="action" value="hosts_edit">
                    <input type="hidden" name="tab" value="hosts">
                    <input type="hidden" name="h_idx" id="heIdx">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Adresse IP *</label>
                            <input type="text" name="h_ip" id="heIp" required>
                        </div>
                        <div class="form-group">
                            <label>Nom(s) d'hôte *</label>
                            <input type="text" name="h_hosts" id="heHosts" required>
                            <span class="form-hint">Séparés par des espaces</span>
                        </div>
                        <div class="form-group full">
                            <label>Commentaire</label>
                            <input type="text" name="h_comment" id="heComment" placeholder="">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-foot">
                <button class="btn btn-ghost" onclick="closeOverlay('hostEditModal')">Annuler</button>
                <button class="btn btn-primary" onclick="document.getElementById('hostEditForm').submit()">💾 Sauvegarder</button>
            </div>
        </div>
    </div>

    <!-- DELETE HOST CONFIRM -->
    <div class="overlay" id="hostDeleteModal" onclick="closeOverlay('hostDeleteModal',event)">
        <div class="modal" style="max-width:420px">
            <div class="modal-head">
                <h2>🗑 Supprimer l'entrée DNS</h2><button class="btn btn-ghost btn-sm" onclick="closeOverlay('hostDeleteModal')">✕</button>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:12px;font-size:.88rem">Supprimer cette entrée de <code style="font-family:var(--font-mono);color:var(--accent)">/etc/hosts</code> ?</p>
                <div style="background:var(--bg);border:1px solid var(--border2);border-radius:8px;padding:11px 14px;font-family:var(--font-mono);font-size:.82rem;margin-bottom:14px">
                    <span style="color:var(--red)" id="hdEntry"></span>
                </div>
            </div>
            <div class="modal-foot">
                <form method="POST" id="hostDeleteForm">
                    <input type="hidden" name="action" value="hosts_delete">
                    <input type="hidden" name="tab" value="hosts">
                    <input type="hidden" name="h_idx" id="hdIdx">
                </form>
                <button class="btn btn-ghost" onclick="closeOverlay('hostDeleteModal')">Annuler</button>
                <button class="btn btn-danger" onclick="document.getElementById('hostDeleteForm').submit()">🗑 Supprimer</button>
            </div>
        </div>
    </div>

    <!-- RAW HOSTS EDITOR -->
    <div class="overlay" id="hostsRawModal" onclick="closeOverlay('hostsRawModal',event)">
        <div class="modal wide">
            <div class="modal-head">
                <h2>✎ Éditer <?= HOSTS_FILE ?> brut</h2>
                <button class="btn btn-ghost btn-sm" onclick="closeOverlay('hostsRawModal')">✕</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="hostsRawForm">
                    <input type="hidden" name="action" value="hosts_raw_save">
                    <input type="hidden" name="tab" value="hosts">
                    <div class="form-group">
                        <textarea name="raw_hosts" id="hostsRawCode" class="raw-code" style="min-height:420px"><?= htmlspecialchars(file_get_contents(HOSTS_FILE) ?: '') ?></textarea>
                    </div>
                </form>
                <div style="margin-top:10px;font-size:.75rem;color:var(--text3)">
                    ⚠ Modification directe — assurez-vous de ne pas supprimer les entrées système (127.0.0.1 localhost, ::1, etc.)
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn btn-ghost" onclick="closeOverlay('hostsRawModal')">Fermer</button>
            </div>
        </div>
    </div>

    <script>
        // ── TABS ─────────────────────────────────────────────────────────────────
        let activeTab = '<?= $activeTab ?>';
        document.addEventListener('DOMContentLoaded', () => {
            const btn = document.getElementById('headerAddBtn');
            if (btn) {
                const updateBtn = () => {
                    btn.textContent = activeTab === 'hosts' ? '＋ Ajouter entrée DNS' : '＋ Nouveau VHost';
                };
                updateBtn();
            }
        });

        // ── HOSTS FILTER ─────────────────────────────────────────────────────────
        function filterHosts() {
            const q = document.getElementById('hostsSearch').value.toLowerCase();
            document.querySelectorAll('#hostsTbody tr').forEach(tr => {
                tr.style.display = !q || tr.dataset.hq?.includes(q) ? '' : 'none';
            });
        }

        // ── HOST ADD ─────────────────────────────────────────────────────────────
        function openHostAdd() {
            document.getElementById('haIp').value = '127.0.0.1';
            document.getElementById('haHosts').value = '';
            document.getElementById('haPreview').textContent = '—';
            openOverlay('hostAddModal');
        }
        // Live preview
        document.addEventListener('DOMContentLoaded', () => {
            ['haIp', 'haHosts'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', updateHaPreview);
            });
        });

        function updateHaPreview() {
            const ip = document.getElementById('haIp')?.value || '';
            const h = document.getElementById('haHosts')?.value || '';
            document.getElementById('haPreview').textContent = ip && h ? ip + '    ' + h : '—';
        }

        // ── HOST EDIT ────────────────────────────────────────────────────────────
        function openHostEdit(idx, ip, hosts, comment) {
            document.getElementById('heIdx').value = idx;
            document.getElementById('heIp').value = ip;
            document.getElementById('heHosts').value = hosts;
            document.getElementById('heComment').value = comment;
            openOverlay('hostEditModal');
        }

        // ── HOST DELETE ──────────────────────────────────────────────────────────
        function confirmHostDelete(idx, ip, hosts) {
            document.getElementById('hdIdx').value = idx;
            document.getElementById('hdEntry').textContent = ip + '    ' + hosts;
            openOverlay('hostDeleteModal');
        }

        // ── HOSTS RAW ────────────────────────────────────────────────────────────
        function openHostsRaw() {
            openOverlay('hostsRawModal');
        }

        // ── FILTER ──────────────────────────────────────────────────────────────
        let activeF = 'all';

        function setF(f, btn) {
            activeF = f;
            document.querySelectorAll('.ftab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filterV();
        }

        function filterV() {
            const q = document.getElementById('search').value.toLowerCase();
            document.querySelectorAll('.vhost-card').forEach(c => {
                const en = c.dataset.en === '1',
                    ssl = c.dataset.ssl === '1',
                    qm = !q || c.dataset.q.includes(q);
                const fm = activeF === 'all' ? true : activeF === 'enabled' ? en : activeF === 'disabled' ? !en : ssl;
                c.style.display = qm && fm ? '' : 'none';
            });
        }

        // ── DETAILS TOGGLE ──────────────────────────────────────────────────────
        function toggleD(id, chevId) {
            const el = document.getElementById(id),
                ch = document.getElementById(chevId);
            el.classList.toggle('open');
            if (ch) ch.style.transform = el.classList.contains('open') ? 'rotate(180deg)' : '';
        }

        // ── OVERLAY ─────────────────────────────────────────────────────────────
        function openOverlay(id) {
            document.getElementById(id).classList.add('open');
        }

        function closeOverlay(id, e) {
            if (!e || e.target === document.getElementById(id)) document.getElementById(id).classList.remove('open');
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.querySelectorAll('.overlay.open').forEach(o => o.classList.remove('open'));
        });

        // ── VIEW CONFIG ──────────────────────────────────────────────────────────
        async function viewCfg(file) {
            document.getElementById('viewTitle').textContent = '📄 ' + file;
            document.getElementById('viewCode').value = 'Chargement…';
            openOverlay('viewModal');
            try {
                const r = await fetch('?view=' + encodeURIComponent(file));
                document.getElementById('viewCode').value = await r.text();
            } catch (e) {
                document.getElementById('viewCode').value = 'Erreur : ' + e.message;
            }
        }

        // ── EDIT ─────────────────────────────────────────────────────────────────
        async function openEdit(file) {
            document.getElementById('editTitle').textContent = '✎ ' + file;
            document.getElementById('editSite').value = file;
            document.getElementById('editCode').value = 'Chargement…';
            document.getElementById('editDeleteBtn').onclick = () => confirmDelete(file, '');
            openOverlay('editModal');
            try {
                const r = await fetch('?edit_raw=' + encodeURIComponent(file));
                document.getElementById('editCode').value = await r.text();
            } catch (e) {
                document.getElementById('editCode').value = 'Erreur : ' + e.message;
            }
        }

        // ── CREATE ───────────────────────────────────────────────────────────────
        function openCreate() {
            openOverlay('createModal');
        }

        // Quand l'utilisateur tape le ServerName → auto-remplit le champ filename
        // SEULEMENT si l'utilisateur n'a pas déjà modifié le filename manuellement
        let filenameManuallyEdited = false;

        function autoFillFilename(sn) {
            if (filenameManuallyEdited) return;
            const clean = sn.replace(/[^a-zA-Z0-9.\-_]/g, '');
            const fi = document.getElementById('guidedFilename');
            if (fi) {
                fi.value = clean;
                updateFilenamePreview(clean);
            }
        }

        function updateFilenamePreview(v) {
            const clean = v.replace(/[^a-zA-Z0-9.\-_]/g, '');
            const el = document.getElementById('filenamePreview');
            if (el) el.textContent = clean ? '📄 Fichier : ' + clean + '.conf' : '—';
        }
        document.addEventListener('DOMContentLoaded', () => {
            const fi = document.getElementById('guidedFilename');
            if (fi) fi.addEventListener('input', () => {
                filenameManuallyEdited = true;
                updateFilenamePreview(fi.value);
            });
        });
        let createMode = 'guided';

        function setMode(mode, modalId, btn) {
            createMode = mode;
            btn.closest('.mode-tabs').querySelectorAll('.mode-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            if (modalId === 'createModal') {
                document.getElementById('createGuided').style.display = mode === 'guided' ? '' : 'none';
                document.getElementById('createRaw').style.display = mode === 'raw' ? '' : 'none';
                document.getElementById('createRawMode').value = mode === 'raw' ? '1' : '';
            }
        }

        // ── DELETE ───────────────────────────────────────────────────────────────
        function confirmDelete(file, name) {
            document.getElementById('delSiteName').textContent = name || file;
            document.getElementById('delFileName').textContent = file;
            document.getElementById('deleteFile').value = file;
            closeOverlay('editModal');
            openOverlay('deleteModal');
        }

        // ── TOAST AUTO-HIDE ──────────────────────────────────────────────────────
        setTimeout(() => {
            const t = document.querySelector('.toast');
            if (t) {
                t.style.transition = 'opacity .4s';
                t.style.opacity = '0';
                setTimeout(() => t.remove(), 400);
            }
        }, 5000);
    </script>
</body>

</html>