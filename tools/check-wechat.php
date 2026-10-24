<?php
declare(strict_types=1);

// CLI only. This probe never reads .env, connects to MySQL, or sends a player code.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function probeWechat(?string $caFile = null): array
{
    // Deliberately omit credentials. A JSON API error still proves the TLS connection works.
    $curl = curl_init('https://api.weixin.qq.com/sns/jscode2session');
    $options = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if ($caFile !== null) $options[CURLOPT_CAINFO] = $caFile;
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $payload = is_string($body) ? json_decode($body, true) : null;
    $result = [
        'http_status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
        'curl_errno' => curl_errno($curl), 'curl_error' => curl_error($curl),
        'tls_verify_result' => (int) curl_getinfo($curl, CURLINFO_SSL_VERIFYRESULT),
        'wechat_error_code' => is_array($payload) ? ($payload['errcode'] ?? null) : null,
        'received_wechat_json' => is_array($payload) && isset($payload['errcode']),
    ];
    curl_close($curl);
    return $result;
}

$bundle = dirname(__DIR__) . '/certs/cacert.pem';
$report = [
    'php_version' => PHP_VERSION, 'php_binary' => PHP_BINARY,
    'loaded_ini' => php_ini_loaded_file(), 'scanned_ini' => php_ini_scanned_files(),
    'curl_loaded' => extension_loaded('curl'),
    'curl_cainfo' => ini_get('curl.cainfo'), 'openssl_cafile' => ini_get('openssl.cafile'),
    'bundled_ca_readable' => is_file($bundle) && is_readable($bundle),
    'note' => 'No credentials sent. A WeChat API error with HTTP 200 is expected for this connectivity probe.',
];
if ($report['curl_loaded']) {
    $version = curl_version();
    $report['curl_version'] = $version['version'];
    $report['tls_backend'] = $version['ssl_version'];
    $report['default_ca'] = probeWechat();
    if ($report['bundled_ca_readable']) $report['bundled_ca'] = probeWechat($bundle);
}
$report['game_ca_source'] = $report['bundled_ca_readable'] ? 'project' : 'php_default';
$selected = $report[$report['bundled_ca_readable'] ? 'bundled_ca' : 'default_ca'] ?? [];
$report['game_connectivity_ok'] = ($selected['http_status'] ?? 0) === 200 && ($selected['received_wechat_json'] ?? false);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($report['game_connectivity_ok'] ? 0 : 1);
