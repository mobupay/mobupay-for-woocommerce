<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Construit l'objet `order` envoye a l'API Mobupay depuis une commande WooCommerce.
 *
 * PLAN-581. Jusqu'ici le connecteur n'envoyait que `reference`, `amount` et
 * `currency` : le module Facturation ne pouvait donc produire qu'une facture a
 * une seule ligne « Commande X », sans nom ni adresse de client, donc non
 * conforme. Cette classe remplit tout ce que le tunnel de commande sait deja.
 *
 * PLAN-598 lot C3 — L'ARITHMETIQUE A DEMENAGE DANS LE SDK (`\Mobupay\OrderPayload`),
 * ou elle est partagee avec PrestaShop et Magento : composer une ligne, faire tomber
 * la somme sur le montant, poser un champ non vide, convertir en unite mineure. Une
 * divergence sur ce calcul ne se voit pas en revue, elle se voit en production sous
 * la forme d'un paiement refuse. Ce fichier ne fait plus que LIRE une commande
 * WooCommerce, ce qui est bien le seul travail qui lui soit propre.
 *
 * Les regles ci-dessous restent vraies ; celles qui portent sur le calcul sont
 * desormais appliquees par le noyau.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CINQ REGLES DE CONSTRUCTION, chacune tiree d'un piege reel
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. LES PRIX PARTENT TAXE COMPRISE. `isInclTaxAmount` vaut `true` par defaut
 *    cote API : les `unitPrice` recus y sont compris comme des montants TTC, et
 *    c'est le serveur qui extrait le HT pour la facture. Or dans WooCommerce le
 *    total d'une LIGNE est hors taxe alors que le total de la COMMANDE est taxe
 *    comprise. Envoyer les lignes telles quelles sous-evaluerait la base
 *    imposable ET ferait tomber la somme sous le montant. On calcule donc
 *    partout `get_total() + get_total_tax()`.
 *
 * 2. LES FRAIS DE PORT SONT UNE LIGNE D'ARTICLE. Le champ `delivery.fee` est
 *    purement descriptif : aucun calcul serveur ne le lit. Des frais de port qui
 *    n'apparaitraient que la produiraient une somme inferieure au montant, donc
 *    un refus `ORDER_AMOUNT_MISMATCH`. C'est aussi ce qui les fait figurer sur la
 *    facture, ce que le client attend. `delivery` ne porte donc QUE le mode et
 *    l'adresse, jamais le montant.
 *
 * 3. LA SOMME DOIT TOMBER EXACTEMENT SUR LE MONTANT. `validateOrderAmounts()`
 *    exige Somme(unitPrice x quantity - remise ligne) - remise globale = amount,
 *    a l'unite pres, sans marge. WooCommerce arrondit la taxe soit par ligne soit
 *    au sous-total selon un reglage de boutique : un ecart d'une unite est
 *    courant. `reconcile()` le resorbe explicitement.
 *
 * 4. ON N'ENVOIE PAS `taxAmount`. Le controle de coherence des taxes ne se
 *    declenche que si ce champ est fourni. En l'omettant, le serveur recalcule
 *    depuis les `taxDetail` et ce controle ne peut plus echouer. En XPF, ou il
 *    n'y a pas de decimale, l'economie compte double.
 *
 * 5. UNE REMISE DE QUELQUES CENTIMES PEUT APPARAITRE SANS AUCUNE REMISE REELLE.
 *    `unitPrice` est un entier : 9,99 HT a 20 % font 11,988 TTC, non representable
 *    en centimes. On arrondit au superieur (11,99) et l'ecart part en `discount`.
 *    Le net de ligne, la base imposable et le total restent EXACTS ; seul
 *    l'affichage porte une remise de 1 a 4 centimes. Toute autre solution exigerait
 *    un prix unitaire fractionnaire, que l'API n'accepte pas, ou de renoncer a la
 *    quantite, que la facture exige. C'est le prix de l'encodage en entiers.
 *
 * 6. EN CAS DE DOUTE, ON RENONCE AU DETAIL, JAMAIS AU PAIEMENT. Si la
 *    reconciliation echoue, `build()` renvoie la charge minimale d'origine. Un
 *    detail manquant est un desagrement ; un paiement refuse est une vente perdue.
 */
class Mobupay_Order_Payload
{
    /**
     * @param string $invoicing 'no' | 'yes' | 'yes_send'
     * @return array{order: array<string,mixed>, degraded: bool, notes: string[]}
     */
    public static function build(
        WC_Order $order,
        bool $withItems,
        bool $withCustomer,
        string $invoicing = 'no'
    ): array {
        $currency = $order->get_currency();
        $amount = \Mobupay\OrderPayload::toMinorUnits((float) $order->get_total(), $currency);
        $notes = [];

        // Socle : ce que le connecteur envoyait deja, et qui ne doit jamais
        // regresser quoi qu'il arrive plus bas.
        $payload = [
            'reference' => (string) $order->get_order_number(),
            'amount' => $amount,
            'currency' => $currency,
        ];

        $degraded = false;

        if ($withItems) {
            $built = self::build_items($order, $currency);
            if ($built === null) {
                $degraded = true;
                $notes[] = 'items: aucune ligne exploitable, detail abandonne';
            } else {
                $reconciled = \Mobupay\OrderPayload::reconcile($built['items'], $built['discount'], $amount);
                if ($reconciled === null) {
                    $degraded = true;
                    $notes[] = 'items: reconciliation impossible, detail abandonne';
                } else {
                    $payload['items'] = $reconciled['items'];
                    if ($reconciled['discount'] > 0) {
                        $payload['discount'] = $reconciled['discount'];
                    }
                    if ($reconciled['adjusted']) {
                        $notes[] = 'items: ecart d\'arrondi resorbe';
                    }
                }
            }
        }

        if ($withCustomer) {
            $buyer = self::build_buyer($order);
            if (!empty($buyer)) {
                $payload['buyer'] = $buyer;
            }
            $delivery = self::build_delivery($order);
            if (!empty($delivery)) {
                $payload['delivery'] = $delivery;
            }
        }

        if ($invoicing === 'yes' || $invoicing === 'yes_send') {
            $payload['invoicing'] = ['enabled' => true];
            if ($invoicing === 'yes_send') {
                $payload['invoicing']['send'] = true;
            }
        }

        return ['order' => $payload, 'degraded' => $degraded, 'notes' => $notes];
    }

    /**
     * Construit les lignes : articles, frais de port, frais additionnels.
     *
     * Les frais NEGATIFS (remises posees en `fee` par certaines extensions) ne
     * peuvent pas devenir une ligne, `unitPrice` n'acceptant pas de negatif :
     * ils sont cumules dans la remise de niveau commande.
     *
     * @return array{items: array<int,array<string,mixed>>, discount: int}|null
     */
    private static function build_items(WC_Order $order, string $currency): ?array
    {
        $items = [];
        $orderDiscount = 0;

        foreach ($order->get_items() as $item) {
            $grossTtc = \Mobupay\OrderPayload::toMinorUnits(
                (float) $item->get_subtotal() + (float) $item->get_subtotal_tax(),
                $currency
            );
            $netTtc = \Mobupay\OrderPayload::toMinorUnits(
                (float) $item->get_total() + (float) $item->get_total_tax(),
                $currency
            );
            if ($netTtc <= 0 && $grossTtc <= 0) {
                continue; // ligne offerte a 0 : sans objet sur la facture
            }

            $line = \Mobupay\OrderPayload::composeLine(
                (string) $item->get_name(),
                (float) $item->get_quantity(),
                $grossTtc,
                $netTtc,
                self::line_taxes($item),
                // Le SDK ne connait pas gettext : les libelles visibles du client
                // restent traduits ICI et lui sont passes.
                /* translators: %s = quantite reelle, fractionnaire */
                __('Quantité : %s', 'mobupay-for-woocommerce'),
                __('Article', 'mobupay-for-woocommerce')
            );
            if ($line !== null) {
                $items[] = $line;
            }
        }

        // Frais de port : une ligne par methode (regle 2).
        foreach ($order->get_items('shipping') as $shipping) {
            $ttc = \Mobupay\OrderPayload::toMinorUnits(
                (float) $shipping->get_total() + (float) $shipping->get_total_tax(),
                $currency
            );
            if ($ttc <= 0) {
                continue; // port offert : ne pas polluer la facture d'une ligne a 0
            }
            $label = $shipping->get_method_title() ?: __('Frais de livraison', 'mobupay-for-woocommerce');
            $line = \Mobupay\OrderPayload::composeLine(
                (string) $label,
                1.0,
                $ttc,
                $ttc,
                self::line_taxes($shipping),
                /* translators: %s = quantite reelle, fractionnaire */
                __('Quantité : %s', 'mobupay-for-woocommerce'),
                __('Article', 'mobupay-for-woocommerce')
            );
            if ($line !== null) {
                $items[] = $line;
            }
        }

        // Frais additionnels, positifs en ligne, negatifs en remise.
        foreach ($order->get_items('fee') as $fee) {
            $ttc = \Mobupay\OrderPayload::toMinorUnits(
                (float) $fee->get_total() + (float) $fee->get_total_tax(),
                $currency
            );
            if ($ttc === 0) {
                continue;
            }
            if ($ttc < 0) {
                $orderDiscount += -$ttc;
                continue;
            }
            $label = $fee->get_name() ?: __('Frais', 'mobupay-for-woocommerce');
            $line = \Mobupay\OrderPayload::composeLine(
                (string) $label,
                1.0,
                $ttc,
                $ttc,
                self::line_taxes($fee),
                /* translators: %s = quantite reelle, fractionnaire */
                __('Quantité : %s', 'mobupay-for-woocommerce'),
                __('Article', 'mobupay-for-woocommerce')
            );
            if ($line !== null) {
                $items[] = $line;
            }
        }

        if (empty($items)) {
            return null;
        }

        return ['items' => $items, 'discount' => $orderDiscount];
    }


    /**
     * Taxes d'une ligne, en CENTIEMES DE POURCENT (convention unique du depot :
     * 1 % = 100, donc une TGC a 11 % vaut 1100 et une TVA a 5,5 % vaut 550).
     *
     * @param WC_Order_Item $item
     * @return array<int,array<string,mixed>>
     */
    private static function line_taxes($item): array
    {
        if (!class_exists('WC_Tax')) {
            return [];
        }
        $taxes = $item->get_taxes();
        $totals = is_array($taxes) && isset($taxes['total']) && is_array($taxes['total'])
            ? $taxes['total']
            : [];

        $detail = [];
        foreach ($totals as $rateId => $amount) {
            if ((float) $amount === 0.0) {
                continue; // taux applicable mais montant nul : rien a declarer
            }
            $percent = WC_Tax::get_rate_percent_value($rateId);
            if ($percent === '' || $percent === null) {
                continue;
            }
            $hundredths = (int) round(((float) $percent) * 100);
            if ($hundredths <= 0) {
                continue;
            }
            $label = WC_Tax::get_rate_label($rateId);
            $detail[] = [
                'id' => \Mobupay\OrderPayload::trimLabel((string) $label, __('Taxe', 'mobupay-for-woocommerce')),
                'type' => 'PERCENTAGE',
                'value' => $hundredths,
            ];
        }
        return $detail;
    }


    /**
     * Coordonnees de l'acheteur. Tous les champs du tunnel WooCommerce sont
     * facultatifs : on envoie ce qui existe et on omet le reste, sans jamais
     * envoyer de chaine vide (l'API distingue « absent » de « vide »).
     *
     * Le telephone part tel que saisi : le serveur le normalise en E.164
     * (regle 19), y compris les numeros calédoniens a six chiffres sans indicatif.
     *
     * @return array<string,mixed>
     */
    private static function build_buyer(WC_Order $order): array
    {
        $buyer = [];
        $customerId = (int) $order->get_customer_id();
        if ($customerId > 0) {
            $buyer['id'] = (string) $customerId;
        }
        \Mobupay\OrderPayload::put($buyer, 'email', $order->get_billing_email());
        \Mobupay\OrderPayload::put($buyer, 'phone', $order->get_billing_phone());
        \Mobupay\OrderPayload::put($buyer, 'firstName', $order->get_billing_first_name());
        \Mobupay\OrderPayload::put($buyer, 'lastName', $order->get_billing_last_name());

        // Forme CANONIQUE `street` + `complement` (PLAN-598 lot C3). On envoyait
        // `line1` / `line2`, qui ne sont que des ALIAS toleres : le connecteur emet
        // desormais la forme documentee, la meme que PrestaShop et Magento.
        $address = \Mobupay\OrderPayload::composeAddress([
            'street' => (string) $order->get_billing_address_1(),
            'complement' => (string) $order->get_billing_address_2(),
            'city' => (string) $order->get_billing_city(),
            'postalCode' => (string) $order->get_billing_postcode(),
            'country' => (string) $order->get_billing_country(),
            'firstName' => (string) $order->get_billing_first_name(),
            'lastName' => (string) $order->get_billing_last_name(),
            'company' => (string) $order->get_billing_company(),
            'phone' => (string) $order->get_billing_phone(),
            'email' => (string) $order->get_billing_email(),
        ]);
        if (!empty($address)) {
            $buyer['billingAddress'] = $address;
        }
        return $buyer;
    }

    /**
     * Livraison : mode et adresse UNIQUEMENT. Jamais de montant, les frais de
     * port etant deja une ligne d'article (regle 2).
     *
     * @return array<string,mixed>
     */
    private static function build_delivery(WC_Order $order): array
    {
        $delivery = [];
        \Mobupay\OrderPayload::put($delivery, 'mode', $order->get_shipping_method());
        \Mobupay\OrderPayload::put($delivery, 'firstName', $order->get_shipping_first_name());
        \Mobupay\OrderPayload::put($delivery, 'lastName', $order->get_shipping_last_name());

        $address = \Mobupay\OrderPayload::composeAddress([
            'street' => (string) $order->get_shipping_address_1(),
            'complement' => (string) $order->get_shipping_address_2(),
            'city' => (string) $order->get_shipping_city(),
            'postalCode' => (string) $order->get_shipping_postcode(),
            'country' => (string) $order->get_shipping_country(),
            'firstName' => (string) $order->get_shipping_first_name(),
            'lastName' => (string) $order->get_shipping_last_name(),
            'company' => (string) $order->get_shipping_company(),
            // Un seul telephone au tunnel WooCommerce, celui de facturation.
            'phone' => (string) $order->get_billing_phone(),
            'email' => (string) $order->get_billing_email(),
        ]);
        if (!empty($address)) {
            $delivery['address'] = $address;
        }
        return $delivery;
    }




}
