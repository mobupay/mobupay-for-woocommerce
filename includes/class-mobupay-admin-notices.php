<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Alerte le marchand quand une facture n'a pas pu etre etablie (PLAN-581).
 *
 * Le paiement, lui, a abouti : la passerelle retente sans la demande de facture
 * plutot que de refuser la vente. Mais sans un avertissement visible, le marchand
 * n'a aucun moyen de savoir que ses factures ne sortent pas. La note de commande
 * ne suffit pas : personne ne relit les notes d'une commande payee.
 *
 * L'alerte est POSEE a l'encaissement et LEVEE par le marchand. Elle n'expire pas
 * d'elle-meme : une facturation qui ne fonctionne pas est un probleme comptable,
 * pas une notification passagere.
 */
class Mobupay_Admin_Notices
{
    private const OPTION = 'mobupay_invoicing_incomplete';

    public static function init(): void
    {
        add_action('admin_init', [__CLASS__, 'maybe_dismiss']);
        add_action('admin_notices', [__CLASS__, 'render']);
    }

    /** Appele par la passerelle quand la facture a du etre abandonnee. */
    public static function flag_invoicing_incomplete(int $orderId, string $reason): void
    {
        update_option(
            self::OPTION,
            [
                'at' => time(),
                'order' => $orderId,
                'reason' => $reason,
            ],
            false // pas d'autoload : lu uniquement dans l'administration
        );
    }

    /** Efface l'alerte une fois que le marchand l'a vue. */
    public static function maybe_dismiss(): void
    {
        if (!isset($_GET['mobupay_dismiss_notice'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        // Verifie le nonce AVANT de toucher a quoi que ce soit.
        check_admin_referer('mobupay_dismiss_notice');
        delete_option(self::OPTION);
        wp_safe_redirect(remove_query_arg(['mobupay_dismiss_notice', '_wpnonce']));
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $flag = get_option(self::OPTION);
        if (!is_array($flag) || empty($flag['order'])) {
            return;
        }

        $orderId = (int) $flag['order'];
        $settingsUrl = admin_url('admin.php?page=wc-settings&tab=checkout&section=mobupay');
        $dismissUrl = wp_nonce_url(
            add_query_arg('mobupay_dismiss_notice', '1'),
            'mobupay_dismiss_notice'
        );

        echo '<div class="notice notice-warning"><p><strong>'
            . esc_html__('Mobupay : une facture n\'a pas pu être établie', 'mobupay-for-woocommerce')
            . '</strong></p><p>'
            . esc_html(
                sprintf(
                    /* translators: %d = numero de la commande concernee */
                    __('Le paiement de la commande %d a bien été encaissé, mais la facture n\'a pas pu être demandée : les informations exigées par la réglementation étaient incomplètes.', 'mobupay-for-woocommerce'),
                    $orderId
                )
            )
            . '</p><p>'
            . esc_html__('Vérifiez que le réglage « Coordonnées du client » est activé, et que votre tunnel de commande demande bien le nom et l\'adresse de facturation. Les commandes suivantes seront facturées dès que ces informations seront transmises.', 'mobupay-for-woocommerce')
            . '</p><p>'
            . '<a class="button button-primary" href="' . esc_url($settingsUrl) . '">'
            . esc_html__('Ouvrir les réglages Mobupay', 'mobupay-for-woocommerce')
            . '</a> <a class="button" href="' . esc_url($dismissUrl) . '">'
            . esc_html__('J\'ai corrigé, masquer cette alerte', 'mobupay-for-woocommerce')
            . '</a>'
            . '</p></div>';
    }
}
