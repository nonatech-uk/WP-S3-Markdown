<?php
/**
 * Minimal S3 GET with AWS Signature V4 signing.
 */

if (!defined('ABSPATH')) {
    exit;
}

class S3MD_S3 {

    private $bucket;
    private $region;
    private $access_key;
    private $secret_key;

    public function __construct($bucket, $region, $access_key, $secret_key) {
        $this->bucket     = $bucket;
        $this->region     = $region;
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
    }

    /**
     * Fetch an object from S3.
     *
     * @param  string $key  The S3 object key (e.g. "index.md").
     * @return string|WP_Error  The object body or WP_Error on failure.
     */
    public function get_object($key) {
        $service  = 's3';
        $host     = $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
        $uri      = '/' . rawurlencode($key);
        // Preserve slashes in the path — S3 uses them as key separators.
        $uri      = str_replace('%2F', '/', $uri);
        $now      = gmdate('Ymd\THis\Z');
        $date     = gmdate('Ymd');

        // Canonical request components
        $method           = 'GET';
        $query_string     = '';
        $payload_hash     = hash('sha256', '');
        $canonical_headers = "host:{$host}\nx-amz-content-sha256:{$payload_hash}\nx-amz-date:{$now}\n";
        $signed_headers    = 'host;x-amz-content-sha256;x-amz-date';

        $canonical_request = implode("\n", array(
            $method,
            $uri,
            $query_string,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ));

        // String to sign
        $scope          = "{$date}/{$this->region}/{$service}/aws4_request";
        $string_to_sign = implode("\n", array(
            'AWS4-HMAC-SHA256',
            $now,
            $scope,
            hash('sha256', $canonical_request),
        ));

        // Signing key
        $signing_key = $this->derive_signing_key($date, $this->region, $service);

        // Signature
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        // Authorization header
        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access_key,
            $scope,
            $signed_headers,
            $signature
        );

        $url = "https://{$host}{$uri}";

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization'        => $authorization,
                'x-amz-content-sha256' => $payload_hash,
                'x-amz-date'           => $now,
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 403) {
            return new WP_Error('s3_forbidden', 'S3 access denied (403) for key: ' . $key);
        }

        if ($code === 404) {
            return new WP_Error('s3_not_found', 'S3 object not found (404): ' . $key);
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error('s3_error', 'S3 returned HTTP ' . $code . ' for key: ' . $key);
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Derive the AWS Signature V4 signing key.
     */
    private function derive_signing_key($date, $region, $service) {
        $k_date    = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
        $k_region  = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        return $k_signing;
    }
}
