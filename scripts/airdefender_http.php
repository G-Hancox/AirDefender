<?php
/*
 * Air Defender - Copyright (C) 2026 G Hancox. Created by G Hancox, built with Claude.
 * Licensed under the GNU GPL v2; see LICENSE.
 *
 * One HTTP request, with its status code. The URL is opened with fopen() and
 * the status read from stream_get_meta_data(): PHP's $http_response_header
 * variable is deprecated from PHP 8.4 (FPP 11 on Debian Trixie), and is not
 * used anywhere in Air Defender.
 */

/*
 * Request $url with the given 'http' stream options (method, header, content,
 * timeout...). Returns [status, body]: status 0 when nothing answered, body
 * false when nothing could be read. After a redirect, the last status counts.
 */
function airdefender_http(string $url, array $httpOptions): array
{
    $httpOptions['ignore_errors'] = true;   // read the body of a 4xx/5xx too
    $timeout = (float) ($httpOptions['timeout'] ?? 5);   // always a timeout: a hung server can't stall the loop
    $context = stream_context_create(['http' => array_merge($httpOptions, ['timeout' => $timeout])]);
    $fp = @fopen($url, 'rb', false, $context);
    if ($fp === false) {
        return [0, false];
    }
    stream_set_timeout($fp, (int) max(1, ceil($timeout)));
    $body = stream_get_contents($fp);
    $meta = stream_get_meta_data($fp);
    fclose($fp);

    $status = 0;
    foreach ($meta['wrapper_data'] ?? [] as $line) {
        if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    if (!empty($meta['timed_out'])) {
        return [$status, false];
    }
    return [$status, $body === false ? false : $body];
}
