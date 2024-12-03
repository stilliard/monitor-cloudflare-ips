<?php

define('CLOUDFLARE_IPS_URL', 'https://www.cloudflare.com/ips-v%d');
define('CLOUDFLARE_IPS_CACHE_FILE',__DIR__ . '/cloudflare-ips-v%d.txt');

// check we have .env file
if (! function_exists('curl_init')) {
    echo "Please install the php curl extension\n";
    exit(1);
if (! file_exists(__DIR__ . '/.env')) {
    echo "Please create a .env file based on the .env.example\n";
    exit(1);
}
}
foreach (['MAILGUN_ENDPOINT', 'MAILGUN_DOMAIN', 'MAILGUN_KEY', 'MAILGUN_TO'] as $key) {
    if (! env($key)) {
        echo "Please set the {$key} in the .env file\n";
        exit(1);
    }
}

function getLatestIps (int $v): array
{
    return explode("\n", file_get_contents(sprintf(CLOUDFLARE_IPS_URL, $v)));
}

function hasCache(int $v): bool
{
    return file_exists(sprintf(CLOUDFLARE_IPS_CACHE_FILE, $v));
}

function readCache(int $v): array
{
    return explode("\n", file_get_contents(sprintf(CLOUDFLARE_IPS_CACHE_FILE, $v)));
}

function writeCache(int $v, array $list): void
{
    file_put_contents(sprintf(CLOUDFLARE_IPS_CACHE_FILE, $v), implode("\n", $list));
}

function readEnvFile(): array
{
    $lines = array_filter(explode("\n", file_get_contents(__DIR__ . '/.env')));
    $env = [];
    foreach ($lines as $line) {
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
    return $env;
}

function env(string $key): ?string
{
    static $env;
    if (is_null($env)) {
        $env = readEnvFile();
    }
    return $env[$key] ?? null;
}

function mailgunSend($fields): void
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, env('MAILGUN_ENDPOINT') . '/' . env('MAILGUN_DOMAIN') . '/messages');
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "api:" . env('MAILGUN_KEY'));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    // Check for errors
    if ($response === false) {
        echo 'cURL Error: ' . curl_error($ch);
    } else {
        echo 'Mailgun Response: ' . $response;
    }

    curl_close($ch);
}

function sendEmail(array $changes): void
{
    $email = <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
    </head>
    <body style="background: #EEE; font: 16px/1.2 sans-serif; padding: 15px;">
        <div style="background: #FFF; border: 1px solid #CCC; margin: 0 auto; max-width: 600px; padding: 15px;">
            <h1 style="margin-top: 0;">Cloudflare IP changes detected:</h1>
            <table width="100%%">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>IP</th>
                        <th>Type</th>
                    </tr>
                </thead>
                <tbody>
                    %s
                </tbody>
            </table>
        </div>
    </body>
    </html>
    HTML;

    $rows = [];
    foreach ($changes as $type => $diff) {
        foreach ($diff as $action => $ips) {
            foreach ($ips as $ip) {
                $rows[] = <<<HTML
                <tr>
                    <td style="text-align: center;">{$action}</td>
                    <td style="text-align: center;">{$ip}</td>
                    <td style="text-align: center;">v{$type}</td>
                </tr>
                HTML;
            }
        }
    }
    
    echo "=> Sending email...\n";
    mailgunSend([
        'from' => 'Cloudflare IP Checker <mailgun@' . env('MAILGUN_DOMAIN') . '>',
        'to' => env('MAILGUN_TO'),
        'subject' => 'Cloudflare IP changes detected',
        'html' => sprintf($email, implode("\n", $rows)),
    ]);
}

function changes(array $a, array $b): array
{
    if ($a == $b) {
        return [];
    }
    return [
        'added' => array_filter(array_diff($b, $a)),
        'removed' => array_filter(array_diff($a, $b)),
    ];
}

function checkChanges(): array
{
    $changes = [];
    foreach ([4, 6] as $v) {
        $latest = getLatestIps($v);

        // if we dont have a cache yet, save only for this first run
        if (! hasCache($v)) {
            writeCache($v, $latest);
            continue;
        }

        // any changes?
        if ($changes[$v] = changes(readCache($v), $latest)) {
            writeCache($v, $latest);
        }
    }
    return array_filter($changes);
}

// email if changes
$changes = checkChanges();
if ($changes) {
    sendEmail($changes);
} else {
    echo "No changes detected\n";
}

