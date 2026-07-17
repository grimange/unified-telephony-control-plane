#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = getopt('', [
    'host:',
    'connect-host::',
    'port::',
    'path::',
    'origin::',
    'ca::',
    'username:',
    'realm:',
    'secret::',
    'secret-stdin',
    'action:',
    'expect::',
    'contact-token::',
]);

$host = required($options, 'host');
$connectHost = (string) ($options['connect-host'] ?? '127.0.0.1');
$port = (int) ($options['port'] ?? 443);
$path = (string) ($options['path'] ?? '/ws');
$origin = (string) ($options['origin'] ?? 'https://app.utcp.local.test');
$ca = (string) ($options['ca'] ?? '');
$username = required($options, 'username');
$realm = required($options, 'realm');
$secret = array_key_exists('secret-stdin', $options)
    ? rtrim((string) stream_get_contents(STDIN), "\r\n")
    : required($options, 'secret');
$action = required($options, 'action');
$expect = (string) ($options['expect'] ?? (($action === 'wrong-password' || $action === 'sha256') ? 'rejected' : 'accepted'));
$contactToken = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($options['contact-token'] ?? 'primary')) ?: 'primary';

if ($secret === '') {
    fwrite(STDERR, "missing SIP secret\n");
    exit(2);
}

if (! in_array($action, ['register', 'refresh', 'replace', 'deregister', 'wrong-password', 'sha256'], true)) {
    fwrite(STDERR, "unsupported action\n");
    exit(2);
}
if (! in_array($expect, ['accepted', 'rejected'], true)) {
    fwrite(STDERR, "unsupported expectation\n");
    exit(2);
}

$socket = connectWebSocket($connectHost, $port, $host, $path, $origin, $ca);
$callId = bin2hex(random_bytes(8)).'@utcp-proof';
$tag = bin2hex(random_bytes(6));
$contact = "sip:{$username}@{$contactToken}.wss.invalid;transport=ws";
$secretForAction = $action === 'wrong-password' ? ($secret.'-wrong') : $secret;
$algorithm = $action === 'sha256' ? 'SHA-256' : 'MD5';
$expires = $action === 'deregister' ? 0 : 120;

$initial = sipRegister($username, $realm, $contact, $callId, $tag, 1, $expires);
writeFrame($socket, $initial);
$challenge = readSipResponse($socket);
if ($challenge['status'] !== 401) {
    fail("expected_digest_challenge status={$challenge['status']}");
}

$authHeader = digestAuthorization($challenge['headers']['www-authenticate'] ?? '', $username, $realm, $secretForAction, $algorithm);
$authorized = sipRegister($username, $realm, $contact, $callId, $tag, 2, $expires, $authHeader);
writeFrame($socket, $authorized);
$response = readSipResponse($socket);

printf("websocket_subprotocol=sip\n");
printf("sip_action=%s\n", $action);
printf("sip_status=%d\n", $response['status']);
$result = ($response['status'] >= 200 && $response['status'] < 300) ? 'accepted' : 'rejected';
printf("sip_result=%s\n", $result);

fclose($socket);
exit($result === $expect ? 0 : 1);

function required(array $options, string $key): string
{
    if (! isset($options[$key]) || ! is_string($options[$key]) || $options[$key] === '') {
        fwrite(STDERR, "missing --{$key}\n");
        exit(2);
    }

    return $options[$key];
}

/**
 * @return resource
 */
function connectWebSocket(string $connectHost, int $port, string $host, string $path, string $origin, string $ca)
{
    $contextOptions = [
        'ssl' => [
            'peer_name' => $host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
        ],
    ];
    if ($ca !== '' && is_file($ca)) {
        $contextOptions['ssl']['cafile'] = $ca;
    }

    $socket = @stream_socket_client(
        "ssl://{$connectHost}:{$port}",
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT,
        stream_context_create($contextOptions),
    );
    if (! is_resource($socket)) {
        fail("tls_connect_failed");
    }
    stream_set_timeout($socket, 10);

    $key = base64_encode(random_bytes(16));
    $request = "GET {$path} HTTP/1.1\r\n"
        ."Host: {$host}\r\n"
        ."Upgrade: websocket\r\n"
        ."Connection: Upgrade\r\n"
        ."Sec-WebSocket-Key: {$key}\r\n"
        ."Sec-WebSocket-Version: 13\r\n"
        ."Sec-WebSocket-Protocol: sip\r\n"
        ."Origin: {$origin}\r\n"
        ."\r\n";
    fwrite($socket, $request);

    $response = '';
    while (! str_contains($response, "\r\n\r\n")) {
        $chunk = fread($socket, 8192);
        if ($chunk === false || $chunk === '') {
            fail("websocket_handshake_failed");
        }
        $response .= $chunk;
    }
    if (! preg_match('/^HTTP\/1\.[01] 101 /', $response)) {
        $statusLine = strtok($response, "\r\n") ?: 'HTTP status unavailable';
        fail("websocket_upgrade_rejected {$statusLine}");
    }
    if (! preg_match('/Sec-WebSocket-Protocol:\s*sip/i', $response)) {
        fail("sip_subprotocol_missing");
    }

    return $socket;
}

function sipRegister(string $username, string $realm, string $contact, string $callId, string $tag, int $cseq, int $expires, ?string $authorization = null): string
{
    $branch = 'z9hG4bK'.bin2hex(random_bytes(8));
    $headers = [
        "REGISTER sip:{$realm} SIP/2.0",
        "Via: SIP/2.0/WSS proof.invalid;branch={$branch};rport",
        'Max-Forwards: 70',
        "From: <sip:{$username}@{$realm}>;tag={$tag}",
        "To: <sip:{$username}@{$realm}>",
        "Call-ID: {$callId}",
        "CSeq: {$cseq} REGISTER",
        "Contact: <{$contact}>;expires={$expires}",
        "Expires: {$expires}",
        'User-Agent: UTCP-T1B-Proof',
    ];
    if ($authorization !== null) {
        $headers[] = $authorization;
    }
    $headers[] = 'Content-Length: 0';

    return implode("\r\n", $headers)."\r\n\r\n";
}

function digestAuthorization(string $challenge, string $username, string $realm, string $secret, string $algorithm): string
{
    $fields = parseDigestFields($challenge);
    $nonce = $fields['nonce'] ?? '';
    if ($nonce === '') {
        fail("digest_nonce_missing");
    }
    $qop = 'auth';
    $nc = '00000001';
    $cnonce = bin2hex(random_bytes(8));
    $uri = "sip:{$realm}";

    if ($algorithm === 'SHA-256') {
        $ha1 = hash('sha256', "{$username}:{$realm}:{$secret}");
        $ha2 = hash('sha256', "REGISTER:{$uri}");
        $response = hash('sha256', "{$ha1}:{$nonce}:{$nc}:{$cnonce}:{$qop}:{$ha2}");
    } else {
        $ha1 = md5("{$username}:{$realm}:{$secret}");
        $ha2 = md5("REGISTER:{$uri}");
        $response = md5("{$ha1}:{$nonce}:{$nc}:{$cnonce}:{$qop}:{$ha2}");
    }

    return 'Authorization: Digest username="'.$username.'", realm="'.$realm.'", nonce="'.$nonce.'", uri="'.$uri.'", response="'.$response.'", algorithm='.$algorithm.', cnonce="'.$cnonce.'", nc='.$nc.', qop='.$qop;
}

/**
 * @return array<string, string>
 */
function parseDigestFields(string $header): array
{
    $header = preg_replace('/^Digest\s+/i', '', $header) ?? $header;
    preg_match_all('/([a-zA-Z0-9_-]+)=("([^"]*)"|([^,\s]*))/', $header, $matches, PREG_SET_ORDER);
    $fields = [];
    foreach ($matches as $match) {
        $fields[strtolower($match[1])] = $match[3] !== '' ? $match[3] : $match[4];
    }

    return $fields;
}

/**
 * @param resource $socket
 */
function writeFrame($socket, string $payload): void
{
    $length = strlen($payload);
    $header = chr(0x81);
    if ($length < 126) {
        $header .= chr(0x80 | $length);
    } elseif ($length <= 0xffff) {
        $header .= chr(0x80 | 126).pack('n', $length);
    } else {
        $header .= chr(0x80 | 127).pack('J', $length);
    }
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $length; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }
    fwrite($socket, $header.$mask.$masked);
}

/**
 * @param resource $socket
 * @return array{status:int, headers:array<string, string>, body:string}
 */
function readSipResponse($socket): array
{
    $payload = readFrame($socket);
    if (! preg_match('/^SIP\/2\.0\s+(\d{3})/m', $payload, $status)) {
        fail("sip_response_invalid");
    }
    [$rawHeaders, $body] = array_pad(explode("\r\n\r\n", $payload, 2), 2, '');
    $headers = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return ['status' => (int) $status[1], 'headers' => $headers, 'body' => $body];
}

/**
 * @param resource $socket
 */
function readFrame($socket): string
{
    $header = readBytes($socket, 2);
    $byte1 = ord($header[0]);
    $byte2 = ord($header[1]);
    $opcode = $byte1 & 0x0f;
    $masked = ($byte2 & 0x80) !== 0;
    $length = $byte2 & 0x7f;
    if ($length === 126) {
        $length = unpack('n', readBytes($socket, 2))[1];
    } elseif ($length === 127) {
        $parts = unpack('N2', readBytes($socket, 8));
        $length = ($parts[1] << 32) | $parts[2];
    }
    $mask = $masked ? readBytes($socket, 4) : '';
    $payload = readBytes($socket, $length);
    if ($masked) {
        $decoded = '';
        for ($i = 0; $i < $length; $i++) {
            $decoded .= $payload[$i] ^ $mask[$i % 4];
        }
        $payload = $decoded;
    }
    if ($opcode === 0x8) {
        fail("websocket_closed");
    }

    return $payload;
}

/**
 * @param resource $socket
 */
function readBytes($socket, int $length): string
{
    $buffer = '';
    while (strlen($buffer) < $length) {
        $chunk = fread($socket, $length - strlen($buffer));
        if ($chunk === false || $chunk === '') {
            fail("socket_read_failed");
        }
        $buffer .= $chunk;
    }

    return $buffer;
}

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}
