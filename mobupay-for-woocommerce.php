<?php
/**
 * Plugin Name: Mobupay for WooCommerce
 * Description: Acceptez les paiements par carte via Mobupay (agent de paiement eZyness). Le client paie sur une page hébergée sécurisée, votre commande est mise à jour par webhook signé.
 * Version: 1.0.1
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.0
 * Author: Mobupay
 * Author URI: https://mobupay.nc
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mobupay-for-woocommerce
 * Domain Path: /languages
 *
 * PLAN-177 Phase 1 — Connecteur WooCommerce. Modele redirect / page hebergee :
 * le plugin cree une session de paiement via l'API Mobupay (SDK PHP), redirige
 * le client vers la page hebergee (widget Monext), puis la commande est mise a
 * jour par webhook signe. La carte ne touche jamais le serveur du marchand.
 */

if (!defined('ABSPATH')) {
    exit; // Acces direct interdit.
}

define('MOBUPAY_WC_VERSION', '1.0.0');
define('MOBUPAY_WC_PLUGIN_FILE', __FILE__);

// Autoload du SDK PHP Mobupay (mobupay/mobupay-php), bundle dans vendor/ a la
// construction du plugin (composer install). Fallback : SDK copie dans lib/.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/lib/mobupay-php/src/MobupayClient.php')) {
    require_once __DIR__ . '/lib/mobupay-php/src/MobupayException.php';
    require_once __DIR__ . '/lib/mobupay-php/src/HttpTransportInterface.php';
    // CurlTransport volontairement non requis (ni bundlé dans le zip wordpress.org) :
    // la passerelle injecte toujours Mobupay_WP_Http_Transport (pas de cURL direct).
    require_once __DIR__ . '/lib/mobupay-php/src/MobupayClient.php';
    require_once __DIR__ . '/lib/mobupay-php/src/Webhook.php';
}

/**
 * Declare ce que ce plugin sait faire, et ce qu'il ne sait PAS faire.
 *
 * HPOS : compatible, verifie sur WooCommerce 11.0 avec le stockage active.
 *
 * Checkout « blocks » : INCOMPATIBLE, et c'est declare explicitement. La
 * passerelle est une passerelle classique (`WC_Payment_Gateway`) sans
 * integration blocks (`AbstractPaymentMethodType`), donc elle n'apparait pas
 * dans le bloc `woocommerce/checkout` — format par defaut des installations
 * recentes.
 *
 * Ne rien declarer laissait WooCommerce dans l'indetermine : aucun
 * avertissement, et le marchand decouvrait « No payment methods available »
 * sur sa propre page de commande, sans rien pour le relier a ce plugin. Le
 * declarer incompatible ne change pas ce que le plugin sait faire, mais
 * WooCommerce le dit AVANT, a l'endroit prevu pour ca.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            MOBUPAY_WC_PLUGIN_FILE,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            MOBUPAY_WC_PLUGIN_FILE,
            false
        );
    }
});

/**
 * Enregistre la passerelle de paiement une fois WooCommerce charge.
 */
add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        // WooCommerce non actif : on previent l'admin.
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Mobupay for WooCommerce nécessite WooCommerce actif.', 'mobupay-for-woocommerce')
                . '</p></div>';
        });
        return;
    }

    require_once __DIR__ . '/includes/class-mobupay-wp-http-transport.php';
    require_once __DIR__ . '/includes/class-wc-gateway-mobupay.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_Mobupay';
        return $gateways;
    });
});

/**
 * Lien "Reglages" depuis la liste des extensions.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=mobupay');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Réglages', 'mobupay-for-woocommerce') . '</a>');
    return $links;
});
