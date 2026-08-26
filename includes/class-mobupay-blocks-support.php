<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integration de la passerelle au tunnel de commande « blocs » de WooCommerce.
 *
 * PLAN-581. WooCommerce pousse le tunnel en blocs par defaut depuis la 8.3. Une
 * passerelle classique (`WC_Payment_Gateway`) n'y apparait tout simplement pas :
 * le marchand installait le plugin, renseignait sa cle, et ne voyait jamais
 * Mobupay au paiement. C'etait le premier motif d'echec silencieux
 * d'installation, et il ne laissait aucune trace exploitable.
 *
 * Le modele de redirection que nous employons est le cas le plus simple a porter :
 * il n'y a aucun champ carte a afficher cote client, donc le composant se reduit
 * a un libelle et une description. Le paiement lui-meme reste traite par
 * `WC_Gateway_Mobupay::process_payment()`, dont la reponse `redirect` est honoree
 * par l'API Store exactement comme par le tunnel classique.
 *
 * Le script est du JavaScript SOURCE, non minifie et sans etape de construction :
 * wordpress.org exige les sources de tout actif minifie, et un plugin sans chaine
 * de build reste verifiable ligne a ligne par leur equipe de revue.
 */
final class Mobupay_Blocks_Support extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
{
    /** Doit valoir l'identifiant de la passerelle : c'est la cle de jonction. */
    protected $name = 'mobupay';

    public function initialize(): void
    {
        // Les reglages de la passerelle sont stockes par WooCommerce sous cette
        // cle. On les lit directement : instancier la passerelle ici serait
        // premature dans le cycle de chargement des blocs.
        $this->settings = get_option('woocommerce_mobupay_settings', []);
    }

    public function is_active(): bool
    {
        return ($this->get_setting('enabled') === 'yes');
    }

    /**
     * @return string[]
     */
    public function get_payment_method_script_handles(): array
    {
        $handle = 'mobupay-blocks';

        wp_register_script(
            $handle,
            plugins_url('assets/js/blocks.js', MOBUPAY_WC_PLUGIN_FILE),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            MOBUPAY_WC_VERSION,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'mobupay-for-woocommerce');
        }

        return [$handle];
    }

    /**
     * Donnees exposees au script sous la cle `mobupay_data`.
     *
     * @return array<string,mixed>
     */
    public function get_payment_method_data(): array
    {
        return [
            'title' => $this->get_setting('title', __('Carte bancaire (Mobupay)', 'mobupay-for-woocommerce')),
            'description' => $this->get_setting('description', __('Vous serez redirigé vers une page sécurisée pour payer par carte.', 'mobupay-for-woocommerce')),
            'supports' => $this->get_supported_features(),
        ];
    }
}
