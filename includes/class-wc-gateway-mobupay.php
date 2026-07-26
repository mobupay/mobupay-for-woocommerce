<?php

if (!defined('ABSPATH')) {
    exit;
}

use Mobupay\MobupayClient;
use Mobupay\Webhook;
use Mobupay\MobupayException;

/**
 * Passerelle de paiement Mobupay pour WooCommerce (PLAN-177 Phase 1).
 *
 * Flux : process_payment() cree une session Mobupay et redirige le client vers
 * la page hebergee ; la commande passe en "on-hold" (en attente de confirmation).
 * Le statut final est pilote par le webhook signe (jamais par le retour navigateur).
 */
class WC_Gateway_Mobupay extends WC_Payment_Gateway
{
    /** Base de l'API selon l'environnement (test/live). */
    private string $apiBase;

    public function __construct()
    {
        $this->id = 'mobupay';
        $this->method_title = __('Mobupay', 'mobupay-for-woocommerce');
        $this->method_description = __('Paiement par carte via Mobupay. Le client paie sur une page hébergée sécurisée (widget Monext).', 'mobupay-for-woocommerce');
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title', __('Carte bancaire (Mobupay)', 'mobupay-for-woocommerce'));
        $this->description = $this->get_option('description', '');
        $this->apiBase = rtrim($this->get_option('api_base', 'https://api.mobupay.nc'), '/');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        // Endpoint webhook : https://maboutique/?wc-api=mobupay
        add_action('woocommerce_api_mobupay', [$this, 'handle_webhook']);
    }

    /** True si on est en mode test (cle sk_test_*). */
    private function is_test_mode(): bool
    {
        return $this->get_option('test_mode') === 'yes';
    }

    /** Cle API active selon le mode. */
    private function get_api_key(): string
    {
        return trim((string) $this->get_option($this->is_test_mode() ? 'test_api_key' : 'live_api_key'));
    }

    private function client(): MobupayClient
    {
        // Transport via l'API HTTP WordPress (wp_remote_request), exigence du
        // repertoire wordpress.org (pas de cURL direct).
        return new MobupayClient($this->get_api_key(), $this->apiBase, 30, new Mobupay_WP_Http_Transport());
    }

    public function init_form_fields(): void
    {
        $webhookUrl = add_query_arg('wc-api', 'mobupay', home_url('/'));

        $this->form_fields = [
            'enabled' => [
                'title' => __('Activer / Désactiver', 'mobupay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Activer le paiement par carte Mobupay', 'mobupay-for-woocommerce'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Titre', 'mobupay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Libellé affiché au client lors du paiement.', 'mobupay-for-woocommerce'),
                'default' => __('Carte bancaire (Mobupay)', 'mobupay-for-woocommerce'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'mobupay-for-woocommerce'),
                'type' => 'textarea',
                'default' => __('Vous serez redirigé vers une page sécurisée pour payer par carte.', 'mobupay-for-woocommerce'),
            ],
            'test_mode' => [
                'title' => __('Mode test (sandbox)', 'mobupay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Utiliser l\'environnement de test (clé sk_test_*)', 'mobupay-for-woocommerce'),
                'default' => 'yes',
                'description' => __('En mode test, aucun paiement réel n\'est encaissé.', 'mobupay-for-woocommerce'),
            ],
            'test_api_key' => [
                'title' => __('Clé API de test', 'mobupay-for-woocommerce'),
                'type' => 'password',
                'description' => __('Clé sk_test_* (espace marchand > Développeurs > Clés API).', 'mobupay-for-woocommerce'),
                'default' => '',
            ],
            'live_api_key' => [
                'title' => __('Clé API de production', 'mobupay-for-woocommerce'),
                'type' => 'password',
                'description' => __('Clé sk_live_* (disponible une fois votre dossier approuvé).', 'mobupay-for-woocommerce'),
                'default' => '',
            ],
            'webhook_secret' => [
                'title' => __('Secret de signature des webhooks', 'mobupay-for-woocommerce'),
                'type' => 'password',
                'description' => sprintf(
                    /* translators: %s = URL du webhook a configurer */
                    __('Secret whsec_* pour vérifier les webhooks. URL à renseigner côté Mobupay (notificationUrl) : %s', 'mobupay-for-woocommerce'),
                    '<code>' . esc_html($webhookUrl) . '</code>'
                ),
                'default' => '',
            ],
            'api_base' => [
                'title' => __('Base API', 'mobupay-for-woocommerce'),
                'type' => 'text',
                'description' => __('Avancé. Ne modifier que sur instruction du support Mobupay.', 'mobupay-for-woocommerce'),
                'default' => 'https://api.mobupay.nc',
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Cree la session de paiement Mobupay et redirige le client.
     *
     * @param int $order_id
     * @return array{result:string, redirect?:string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Commande introuvable.', 'mobupay-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        $currency = $order->get_currency();
        $amount = $this->to_minor_units((float) $order->get_total(), $currency);

        $order_key = (string) $order->get_order_key();
        $opts = ['externalId' => (string) $order->get_id()];               // cle de rapprochement
        $email = $order->get_billing_email();
        if ($email) {
            $opts['email'] = $email;                                       // omis si absent (l'API n'accepte pas null)
        }
        try {
            $session = $this->client()->createCheckoutSession(
                [
                    'reference' => $order->get_order_number(),
                    'amount' => $amount,
                    'currency' => $currency,
                ],
                $this->get_return_url($order),                              // retour client (page "merci")
                add_query_arg('wc-api', 'mobupay', home_url('/')),          // notificationUrl (webhook)
                $opts,
                // Idempotency-Key : stable par tentative de paiement -> un rejeu reseau
                // ne cree pas 2 paiements. On inclut order_key (rouvert si echec).
                'wc-' . $order->get_id() . '-' . $order_key
            );
        } catch (MobupayException $e) {
            $this->log('createCheckoutSession failed: ' . $e->getMessage());
            wc_add_notice(__('Le paiement n\'a pas pu être initialisé. Réessayez.', 'mobupay-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        // Memorise l'id de paiement Mobupay pour le rapprochement / refund.
        $order->update_meta_data('_mobupay_payment_id', $session['paymentId'] ?? '');
        $order->update_status('on-hold', __('En attente du paiement Mobupay.', 'mobupay-for-woocommerce'));
        $order->save();

        return [
            'result' => 'success',
            'redirect' => $session['checkoutUrl'] ?? $this->get_return_url($order),
        ];
    }

    /**
     * Recoit les webhooks Mobupay (payment.*). Verifie la signature puis met a
     * jour la commande. Le webhook est la SOURCE DE VERITE (le retour navigateur
     * ne fait qu'afficher "paiement en cours de confirmation").
     */
    public function handle_webhook(): void
    {
        // Corps BRUT requis : la signature HMAC est calculee sur les octets
        // exacts de la requete, toute re-serialisation l'invaliderait.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- verifie par HMAC ci-dessous (Webhook::verify).
        $payload = file_get_contents('php://input');
        $secret = trim((string) $this->get_option('webhook_secret'));
        $headers = $this->request_headers();

        try {
            $event = Webhook::verify((string) $payload, $headers, $secret);
        } catch (MobupayException $e) {
            $this->log('Webhook rejected: ' . $e->getMessage());
            status_header(403);
            exit;
        }

        $data = $event['data'] ?? [];
        // externalId = id de commande WooCommerce pose par process_payment().
        $orderId = isset($data['externalId']) ? absint($data['externalId']) : 0;
        $order = $orderId > 0 ? wc_get_order($orderId) : null;
        if (!$order) {
            status_header(200); // rien a faire, mais on acquitte pour stopper les retries
            exit;
        }

        // Idempotence cote receiver : on ignore un event deja traite.
        $eventId = (string) ($event['id'] ?? '');
        $processed = (array) $order->get_meta('_mobupay_processed_events');
        if ($eventId !== '' && in_array($eventId, $processed, true)) {
            status_header(200);
            exit;
        }

        switch ($event['type'] ?? '') {
            case 'payment.captured':
            case 'payment.authorized':
                if (!$order->is_paid()) {
                    $order->payment_complete($data['paymentId'] ?? '');
                    $order->add_order_note(__('Paiement Mobupay confirmé.', 'mobupay-for-woocommerce'));
                }
                break;
            case 'payment.failed':
                $order->update_status('failed', __('Paiement Mobupay refusé.', 'mobupay-for-woocommerce'));
                break;
            case 'payment.expired':
            case 'payment.cancelled':
                if (!$order->is_paid()) {
                    $order->update_status('cancelled', __('Paiement Mobupay abandonné / expiré.', 'mobupay-for-woocommerce'));
                }
                break;
            case 'payment.refunded':
                $order->add_order_note(__('Remboursement Mobupay enregistré.', 'mobupay-for-woocommerce'));
                break;
        }

        if ($eventId !== '') {
            $processed[] = $eventId;
            $order->update_meta_data('_mobupay_processed_events', array_slice($processed, -50));
            $order->save();
        }

        status_header(200);
        exit;
    }

    /**
     * Remboursement depuis le back-office WooCommerce.
     *
     * @param int        $order_id
     * @param float|null $amount
     * @param string     $reason
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('mobupay_refund', __('Commande introuvable.', 'mobupay-for-woocommerce'));
        }
        $paymentId = (string) $order->get_meta('_mobupay_payment_id');
        if ($paymentId === '') {
            return new WP_Error('mobupay_refund', __('Identifiant de paiement Mobupay absent.', 'mobupay-for-woocommerce'));
        }

        $minor = $amount !== null ? $this->to_minor_units((float) $amount, $order->get_currency()) : null;
        try {
            $this->client()->refund($paymentId, $minor);
        } catch (MobupayException $e) {
            $this->log('refund failed: ' . $e->getMessage());
            return new WP_Error('mobupay_refund', $e->getMessage());
        }
        return true;
    }

    /** Convertit un montant decimal en unite mineure attendue par l'API (centimes EUR ; XPF sans decimale). */
    private function to_minor_units(float $amount, string $currency): int
    {
        $factor = strtoupper($currency) === 'XPF' ? 1 : 100;
        return (int) round($amount * $factor);
    }

    /** Recupere les headers de la requete entrante (compatibilite hors getallheaders). */
    private function request_headers(): array
    {
        if (function_exists('getallheaders')) {
            $all = getallheaders() ?: [];
            $headers = [];
            foreach ($all as $name => $value) {
                $headers[(string) $name] = sanitize_text_field((string) $value);
            }
            return $headers;
        }
        $headers = [];
        foreach (wp_unslash($_SERVER) as $key => $value) {
            if (is_string($value) && strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = sanitize_text_field($value);
            }
        }
        return $headers;
    }

    private function log(string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning($message, ['source' => 'mobupay']);
        }
    }
}
