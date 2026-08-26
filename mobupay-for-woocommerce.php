<?php
/**
 * Plugin Name: Mobupay for WooCommerce
 * Description: Acceptez les paiements par carte via Mobupay (agent de paiement eZyness). Le client paie sur une page hébergée sécurisée, votre commande est mise à jour par webhook signé.
 * Version: 1.2.0
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

define('MOBUPAY_WC_VERSION', '1.2.0');
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
    // PLAN-598 lot C3 — noyau arithmetique de construction de l'objet `order`,
    // partage avec les autres connecteurs PHP.
    require_once __DIR__ . '/lib/mobupay-php/src/OrderPayload.php';
}

/**
 * Declare ce que ce plugin sait faire.
 *
 * HPOS : compatible, verifie sur WooCommerce 11.0 avec le stockage active.
 *
 * Tunnel « blocs » : COMPATIBLE depuis la 1.1.0 (PLAN-581), via
 * `Mobupay_Blocks_Support` enregistre plus bas. Jusque-la l'incompatibilite
 * etait declaree, faute d'integration : la passerelle n'apparaissait pas dans
 * le bloc `woocommerce/checkout`, qui est le format par defaut des
 * installations recentes, et le marchand lisait « Aucun moyen de paiement
 * disponible » sur sa propre page de commande sans pouvoir le relier a ce
 * plugin. C'etait le premier motif d'echec silencieux d'installation.
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
            true
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
    require_once __DIR__ . '/includes/class-mobupay-order-payload.php';
    require_once __DIR__ . '/includes/class-mobupay-admin-notices.php';
    require_once __DIR__ . '/includes/class-wc-gateway-mobupay.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_Mobupay';
        return $gateways;
    });

    if (is_admin()) {
        Mobupay_Admin_Notices::init();
    }
});

/**
 * Enregistre le moyen de paiement dans le tunnel « blocs ».
 *
 * Ce point d'accroche n'existe que si le paquet WooCommerce Blocks est charge :
 * la classe parente n'est donc requise qu'ici, jamais au chargement du plugin.
 */
add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    require_once __DIR__ . '/includes/class-mobupay-blocks-support.php';
    $registry->register(new Mobupay_Blocks_Support());
});

/**
 * Lien "Reglages" depuis la liste des extensions.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=mobupay');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Réglages', 'mobupay-for-woocommerce') . '</a>');
    return $links;
});
