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

    /**
     * PLAN-598 lot B — apres l'enregistrement des reglages, on recupere le secret de
     * signature TOUT SEUL, avec la cle qui vient d'etre saisie.
     *
     * L'appel sert deux choses d'un coup : il pose le secret, et il PROUVE que la cle
     * est valide. Un marchand qui se trompe de cle, ou qui colle une cle de test en
     * croyant etre en production, l'apprend maintenant plutot qu'a sa premiere vente.
     *
     * Un echec ne bloque JAMAIS l'enregistrement : le reglage est deja ecrit quand on
     * arrive ici, et une API momentanement injoignable ne doit pas empecher un
     * marchand de configurer sa boutique. On avertit, et la prochaine sauvegarde
     * reessaiera.
     */
    public function process_admin_options(): bool
    {
        $saved = parent::process_admin_options();

        $key = $this->get_api_key();
        if ($key === '') {
            // Aucune cle pour le mode actif : rien a verifier, et le dire est plus
            // utile qu'un echec reseau incomprehensible.
            \WC_Admin_Settings::add_error(
                $this->is_test_mode()
                    ? __('Aucune clé API de test renseignée : la boutique ne pourra pas encaisser en mode test.', 'mobupay-for-woocommerce')
                    : __('Aucune clé API de production renseignée : la boutique ne pourra pas encaisser.', 'mobupay-for-woocommerce')
            );
            return $saved;
        }

        try {
            $secret = $this->client()->getSigningSecret();
        } catch (MobupayException $e) {
            $this->log('signing secret fetch failed: ' . $e->getMessage());
            \WC_Admin_Settings::add_error(
                __('Réglages enregistrés, mais Mobupay n\'a pas pu être contacté pour vérifier votre clé. Vérifiez la clé et réenregistrez.', 'mobupay-for-woocommerce')
            );
            return $saved;
        }

        if ($secret === '') {
            \WC_Admin_Settings::add_error(
                __('Réglages enregistrés, mais aucun secret de signature n\'a été renvoyé. Les paiements fonctionneront, mais les confirmations ne pourront pas être vérifiées.', 'mobupay-for-woocommerce')
            );
            return $saved;
        }

        $this->update_option('webhook_secret', $secret);
        \WC_Admin_Settings::add_message(
            $this->is_test_mode()
                ? __('Connexion à Mobupay vérifiée. Vous êtes en environnement de TEST : aucun paiement réel ne sera encaissé.', 'mobupay-for-woocommerce')
                : __('Connexion à Mobupay vérifiée. Vous êtes en environnement de PRODUCTION : les paiements seront réels.', 'mobupay-for-woocommerce')
        );

        return $saved;
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
            // PLAN-598 lot B — le champ « Secret de signature des webhooks » a ETE
            // RETIRE. Le marchand devait aller le chercher dans son espace et le
            // coller ici : deux secrets a saisir au lieu d'un, et une source d'erreur
            // silencieuse (un secret absent ou mal copie ne se voit qu'au premier
            // paiement, quand le webhook est rejete en 403 et que la commande reste
            // en attente). Le secret est desormais recupere automatiquement a
            // l'enregistrement des reglages, avec la seule cle API : celle-ci y donnait
            // deja acces, donc aucun droit nouveau n'est accorde.
            //
            // Il reste stocke sous la meme option `webhook_secret`, ce qui evite toute
            // migration : une boutique deja configuree continue de fonctionner avec le
            // secret qu'elle a, et le prochain enregistrement le rafraichit.
            'data_section' => [
                'title' => __('Données transmises à Mobupay', 'mobupay-for-woocommerce'),
                'type' => 'title',
                'description' => __('Ces réglages décident de ce que votre boutique transmet à chaque paiement. Tout est déduit automatiquement de la commande : vous n\'avez aucun champ à remplir. Les informations absentes du tunnel de commande sont simplement omises.', 'mobupay-for-woocommerce'),
            ],
            'send_order_details' => [
                'title' => __('Détail de la commande', 'mobupay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Transmettre les articles, les taxes, les frais de port et les remises', 'mobupay-for-woocommerce'),
                'default' => 'yes',
                'description' => __('Le client voit le récapitulatif de son panier sur la page de paiement, et vos factures Mobupay détaillent chaque ligne au lieu d\'afficher une ligne unique.', 'mobupay-for-woocommerce'),
            ],
            'send_customer_details' => [
                'title' => __('Coordonnées du client', 'mobupay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Transmettre nom, adresse, téléphone et adresse de livraison', 'mobupay-for-woocommerce'),
                'default' => 'yes',
                'description' => __('Nécessaire pour qu\'une facture porte les mentions obligatoires. Sans ces informations, une facture ne peut pas être émise.', 'mobupay-for-woocommerce'),
            ],
            'invoicing' => [
                'title' => __('Facture Mobupay', 'mobupay-for-woocommerce'),
                'type' => 'select',
                'default' => 'no',
                'options' => [
                    'no' => __('Ne pas établir de facture', 'mobupay-for-woocommerce'),
                    'yes' => __('Établir une facture pour chaque paiement', 'mobupay-for-woocommerce'),
                    'yes_send' => __('Établir une facture et l\'envoyer au client', 'mobupay-for-woocommerce'),
                ],
                'description' => __('Demande l\'émission d\'une facture Mobupay à chaque encaissement. Exige les coordonnées du client ci-dessus. Si une facture ne peut pas être établie, le paiement aboutit quand même et l\'anomalie est journalisée.', 'mobupay-for-woocommerce'),
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

        // PLAN-581 — detail de commande et coordonnees client. Tout est deduit de
        // la commande WooCommerce : aucun champ a saisir cote marchand. Ce qui
        // manque dans le tunnel est omis, jamais envoye vide.
        $built = Mobupay_Order_Payload::build(
            $order,
            $this->get_option('send_order_details', 'yes') === 'yes',
            $this->get_option('send_customer_details', 'yes') === 'yes',
            (string) $this->get_option('invoicing', 'no')
        );
        foreach ($built['notes'] as $note) {
            $this->log('order payload: ' . $note);
        }

        $idempotencyKey = 'wc-' . $order->get_id() . '-' . $order_key;
        $redirectUrl = $this->get_return_url($order);                       // retour client (page "merci")
        $notificationUrl = add_query_arg('wc-api', 'mobupay', home_url('/')); // webhook

        try {
            $session = $this->client()->createCheckoutSession(
                $built['order'],
                $redirectUrl,
                $notificationUrl,
                $opts,
                // Idempotency-Key : stable par tentative de paiement -> un rejeu reseau
                // ne cree pas 2 paiements. On inclut order_key (rouvert si echec).
                $idempotencyKey
            );
        } catch (MobupayException $e) {
            // UN PAIEMENT NE DOIT JAMAIS ECHOUER POUR UN MOTIF DE FACTURATION.
            // Si l'API refuse faute de mentions obligatoires, on retente une fois
            // sans la demande de facture : le marchand encaisse, et l'anomalie
            // reste dans le journal pour qu'il la corrige. Refuser la vente serait
            // le pire des deux maux.
            $retried = null;
            if (self::is_invoicing_error($e) && isset($built['order']['invoicing'])) {
                $fallback = $built['order'];
                unset($fallback['invoicing']);
                $this->log('invoicing refused by API, retrying without it: ' . $e->getMessage());
                try {
                    $retried = $this->client()->createCheckoutSession(
                        $fallback,
                        $redirectUrl,
                        $notificationUrl,
                        $opts,
                        $idempotencyKey
                    );
                    $order->add_order_note(__('Paiement Mobupay accepté, mais la facture n\'a pas pu être demandée : les coordonnées du client sont incomplètes.', 'mobupay-for-woocommerce'));
                    // La note de commande ne suffit pas, personne ne relit les notes
                    // d'une commande payee : on alerte aussi dans l'administration.
                    if (class_exists('Mobupay_Admin_Notices')) {
                        Mobupay_Admin_Notices::flag_invoicing_incomplete((int) $order->get_id(), $e->getMessage());
                    }
                } catch (MobupayException $inner) {
                    $this->log('retry without invoicing also failed: ' . $inner->getMessage());
                }
            }
            if ($retried === null) {
                $this->log('createCheckoutSession failed: ' . $e->getMessage());
                wc_add_notice(__('Le paiement n\'a pas pu être initialisé. Réessayez.', 'mobupay-for-woocommerce'), 'error');
                return ['result' => 'failure'];
            }
            $session = $retried;
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
            // PLAN-598 lot B — le secret a pu etre fait tourner cote Mobupay, ou n'avoir
            // jamais ete pose (boutique configuree avant cette version). On le redemande
            // UNE fois, puis on rejoue la verification.
            //
            // Garde anti-boucle obligatoire : sans elle, n'importe qui pourrait
            // declencher un appel sortant a chaque requete forgee envoyee sur cette URL,
            // qui est publique. Une tentative toutes les dix minutes au plus.
            $retried = null;
            if (false === get_transient('mobupay_secret_refetch_lock') && $this->get_api_key() !== '') {
                set_transient('mobupay_secret_refetch_lock', 1, 10 * MINUTE_IN_SECONDS);
                try {
                    $fresh = $this->client()->getSigningSecret();
                    if ($fresh !== '' && $fresh !== $secret) {
                        $this->update_option('webhook_secret', $fresh);
                        $this->log('signing secret refreshed after a rejected webhook');
                        $retried = Webhook::verify((string) $payload, $headers, $fresh);
                    }
                } catch (MobupayException $inner) {
                    $this->log('signing secret refetch failed: ' . $inner->getMessage());
                }
            }
            if ($retried === null) {
                $this->log('Webhook rejected: ' . $e->getMessage());
                status_header(403);
                exit;
            }
            $event = $retried;
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
        // array_filter : un meta vide donne ('') apres cast, ce qui laissait une
        // entree vide en tete de la liste anti-doublon a chaque premiere reception.
        $processed = array_values(array_filter((array) $order->get_meta('_mobupay_processed_events')));
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

        // `$minor` est dans la devise D'ORIGINE de la commande : des francs en XPF,
        // des centimes en EUR. C'est ce que l'API attend sous `amountCents`.
        $minor = $amount !== null ? $this->to_minor_units((float) $amount, $order->get_currency()) : null;
        try {
            // Le motif saisi par le marchand dans WooCommerce etait jete : il part
            // desormais dans le journal d'audit Mobupay.
            $this->client()->refund($paymentId, $minor, $reason !== '' ? $reason : null);
        } catch (MobupayException $e) {
            $this->log('refund failed: ' . $e->getMessage());
            return new WP_Error('mobupay_refund', $e->getMessage());
        }
        return true;
    }

    /** Convertit un montant decimal en unite mineure attendue par l'API (centimes EUR ; XPF sans decimale). */
    /**
     * Vrai si l'API a refuse pour un motif de FACTURATION et non de paiement.
     * Le corps d'erreur porte un `code` metier ecrit pour etre lu.
     */
    private static function is_invoicing_error(MobupayException $e): bool
    {
        $body = $e->getResponseBody();
        $code = is_array($body) && isset($body['code']) ? (string) $body['code'] : '';
        return strpos($code, 'INVOICING') !== false || strpos($code, 'BILLING') !== false;
    }

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
