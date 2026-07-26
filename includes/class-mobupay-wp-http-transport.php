<?php

if (!defined('ABSPATH')) {
    exit;
}

use Mobupay\HttpTransportInterface;
use Mobupay\MobupayException;

/**
 * Transport HTTP du SDK Mobupay base sur l'API HTTP de WordPress (PLAN-291,
 * lot 1.A.2). Le repertoire wordpress.org exige `wp_remote_request` plutot que
 * cURL direct : ce transport est injecte dans MobupayClient par la passerelle.
 */
class Mobupay_WP_Http_Transport implements HttpTransportInterface
{
    /**
     * @param string               $method  Verbe HTTP.
     * @param string               $url     URL absolue.
     * @param array<string,string> $headers Headers (cle => valeur).
     * @param string|null          $body    Corps JSON deja serialise, ou null.
     * @param int                  $timeout Timeout en secondes.
     * @return array{status:int, body:string}
     * @throws MobupayException En cas d'echec reseau.
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
            'redirection' => 0,
        ]);

        if (is_wp_error($response)) {
            throw new MobupayException('Echec reseau vers Mobupay : ' . esc_html($response->get_error_message()));
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }
}
