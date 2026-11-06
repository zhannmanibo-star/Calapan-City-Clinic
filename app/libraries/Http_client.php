<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Http_client - the one place that calls outside services (Google, Brevo) with cURL.
 *
 * Windows tip: if a request fails with an "SSL certificate" error, download
 * https://curl.se/ca/cacert.pem into the project folder and set CURL_CA_BUNDLE=cacert.pem in .env
 */
class Http_client
{
    /** Returns ['code' => HTTP status (0 if unreachable), 'data' => decoded JSON or null, 'error' => text or null] */
    public function request($method, $url, $body = null, array $headers = [], $timeout = 10)
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'data' => null, 'error' => 'The PHP cURL extension is not enabled.'];
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;

        $bundle = trim((string) getenv('CURL_CA_BUNDLE'));
        if ($bundle !== '') {
            $options[CURLOPT_CAINFO] = preg_match('#^([A-Za-z]:)?[\\\\/]#', $bundle) ? $bundle : ROOT_DIR . $bundle;
        }
        curl_setopt_array($ch, $options);

        $raw   = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $raw === false ? curl_error($ch) : null;
        curl_close($ch);

        if ($error && stripos($error, 'certificate') !== false) {
            $error .= ' (Windows fix: download https://curl.se/ca/cacert.pem into the project folder and set CURL_CA_BUNDLE=cacert.pem in .env)';
        }
        return ['code' => $code, 'data' => $raw ? json_decode($raw, true) : null, 'error' => $error];
    }
}
