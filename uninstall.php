<?php

/**
 * Nettoyage a la desinstallation du plugin (PLAN-291, lot 1.A).
 * Supprime les reglages de la passerelle (dont les cles API stockees en option).
 * Les metadonnees de commandes (_mobupay_payment_id, _mobupay_processed_events)
 * sont volontairement conservees : ce sont des donnees comptables de la boutique.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('woocommerce_mobupay_settings');
