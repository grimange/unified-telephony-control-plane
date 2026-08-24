<?php

namespace App\RuntimeAdapters\FreeSwitch;

use App\ControlPlane\RuntimeOperations\FailureClass;

final class FreeSwitchEslProtocol
{
    public static function assertAuthRequestGreeting(string $frame): void
    {
        $contentType = self::header($frame, 'Content-Type');
        if (strtolower($contentType) !== 'auth/request') {
            throw new FreeSwitchEslException(FailureClass::Conflict, 'freeswitch_esl_authentication_greeting_invalid', 'FreeSWITCH ESL did not return an auth/request greeting.');
        }
    }

    public static function assertAuthenticated(string $frame): void
    {
        $replyText = self::headerFromParsedFrame(self::parseFrame($frame)['headers'], 'Reply-Text');
        if ($replyText === '' || ! str_starts_with($replyText, '+OK')) {
            throw new FreeSwitchEslException(FailureClass::AuthenticationFailed, 'freeswitch_esl_authentication_failed', 'FreeSWITCH ESL authentication failed.');
        }
    }

    public static function responseTextFromFrame(string $frame): string
    {
        $parsed = self::parseFrame($frame);
        $replyText = self::headerFromParsedFrame($parsed['headers'], 'Reply-Text');

        return $replyText !== '' ? trim($replyText) : trim($parsed['body']);
    }

    /** @param resource $stream */
    public static function readFrame($stream): string
    {
        $headers = '';
        while (($line = fgets($stream)) !== false) {
            if (rtrim($line, "\r\n") === '') {
                break;
            }
            $headers .= $line;
        }

        if ($headers === '' && feof($stream)) {
            throw new FreeSwitchEslException(FailureClass::TransientTransport, 'freeswitch_esl_frame_disconnected', 'FreeSWITCH ESL disconnected while reading a frame.', true);
        }

        $length = 0;
        if (preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $match)) {
            $length = (int) $match[1];
        }
        $body = $length > 0 ? stream_get_contents($stream, $length) : '';
        if ($length > 0 && strlen($body) !== $length) {
            throw new FreeSwitchEslException(FailureClass::TransientTransport, 'freeswitch_esl_frame_truncated', 'FreeSWITCH ESL returned a truncated frame.', true);
        }

        return $headers."\r\n".$body;
    }

    /** @return array{headers:array<string,string>,body:string} */
    public static function parseFrame(string $frame): array
    {
        [$rawHeaders, $body] = array_pad(preg_split("/\r?\n\r?\n/", $frame, 2) ?: [], 2, '');
        $headers = [];
        foreach (preg_split("/\r?\n/", (string) $rawHeaders) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }

        $length = isset($headers['Content-Length']) && ctype_digit($headers['Content-Length'])
            ? (int) $headers['Content-Length']
            : null;
        if ($length !== null && strlen((string) $body) !== $length) {
            throw new FreeSwitchEslException(FailureClass::InvalidRequest, 'freeswitch_esl_frame_truncated', 'FreeSWITCH ESL frame body length is invalid.');
        }

        return ['headers' => $headers, 'body' => (string) $body];
    }

    private static function header(string $frame, string $name): string
    {
        return self::headerFromParsedFrame(self::parseFrame($frame)['headers'], $name);
    }

    /** @param array<string,string> $headers */
    private static function headerFromParsedFrame(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return trim($value);
            }
        }

        return '';
    }
}
