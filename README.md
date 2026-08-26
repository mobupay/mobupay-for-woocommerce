# Mobupay for WooCommerce

Passerelle de paiement Mobupay pour WooCommerce. Modèle **redirect / page hébergée** : le client paie sur une page sécurisée Mobupay (widget Monext), la carte ne touche jamais votre serveur, et la commande est mise à jour par **webhook signé**.

## Prérequis

- WordPress >= 6.0, WooCommerce actif, PHP >= 7.4.
- Une clé API Mobupay (`sk_test_*` pour tester, `sk_live_*` en production), depuis votre espace marchand > Développeurs > Clés API.

## Installation

L'archive distribuée embarque déjà le SDK PHP : **aucune construction, aucune commande à lancer** chez le marchand.

1. Télécharger `mobupay-for-woocommerce-<version>.zip` depuis [les versions publiques](https://github.com/mobupay/mobupay-for-woocommerce/releases).
2. WordPress > Extensions > Ajouter > Téléverser une extension, puis activer.
3. WooCommerce > Réglages > Paiements > **Carte bancaire (Mobupay)**.

L'extension apparaît dans le tunnel de commande **en blocs** comme dans le tunnel **classique**, sans réglage particulier (depuis la 1.1.0).

## Construction du paquet (contributeurs uniquement)

L'archive se construit depuis le monorepo, jamais à la main :

```bash
./scripts/build-connector-zip.sh woocommerce <version>
```

Le script copie le SDK dans `lib/mobupay-php/`, retire le transport cURL (inutile ici, la passerelle injecte toujours `wp_remote_request`) et refuse de produire une archive contenant une URL de recette ou un secret. Le plugin charge `vendor/autoload.php` s'il existe, sinon ce `lib/`, ce qui est le cas de l'archive distribuée. `composer install` ne sert qu'au développement local.

## Configuration

| Champ | Valeur |
|---|---|
| Activer | Oui |
| Mode test (sandbox) | Oui pour démarrer (clé `sk_test_*`) |
| Clé API de test / production | Vos clés `sk_test_*` / `sk_live_*`. **Seul secret à saisir.** Le secret de signature des webhooks est récupéré automatiquement à l'enregistrement, et l'enregistrement vérifie du même coup que la clé est valide et vous dit dans quel environnement vous êtes |
| Base API | `https://api.mobupay.nc` (ne modifier que sur instruction du support Mobupay) |

**URL de webhook (`notificationUrl`)** affichée dans les réglages :
`https://votre-boutique/?wc-api=mobupay`. Elle est transmise automatiquement à chaque paiement ; aucun enregistrement manuel n'est nécessaire côté Mobupay.

## Fonctionnement

1. Le client choisit « Carte bancaire (Mobupay) » et valide.
2. `process_payment()` crée une session Mobupay (`reference` = n° de commande, `externalId` = id de commande, `Idempotency-Key` anti double-paiement) et redirige vers la page hébergée. La commande passe en **en attente** (`on-hold`).
3. Le client paie. Mobupay envoie un **webhook signé** à `notificationUrl`.
4. Le plugin **vérifie la signature** (`Webhook::verify`, V2 anti-rejeu + repli V1), rapproche la commande via `externalId`, puis :
   - `payment.captured` / `payment.authorized` → commande **payée** ;
   - `payment.failed` → **échouée** ;
   - `payment.expired` / `payment.cancelled` → **annulée** ;
   - `payment.refunded` → note de remboursement.
5. Le statut est piloté par le **webhook**, jamais par le retour navigateur (non fiable). Idempotent côté receiver (déduplication par `event.id`).

## Remboursement

Depuis WooCommerce > Commandes > (commande) > Rembourser : `process_refund()` appelle l'API Mobupay (total ou partiel).

## Matrice de test (sandbox)

À exécuter avec une clé `sk_test_*` et une carte de test sandbox (ex. `4970 1051 5151 5140`) :

| Scénario | Attendu |
|---|---|
| Paiement réussi | Commande `on-hold` → `processing`/`completed` après webhook `payment.captured` |
| Refus carte | Webhook `payment.failed` → commande `failed` |
| Abandon / fermeture onglet | Pas de webhook capture ; à l'expiration, `payment.expired` → `cancelled` |
| Double soumission (même panier) | Idempotency-Key → un seul paiement |
| Rejeu de webhook (même `event.id`) | Ignoré (idempotence receiver) |
| Remboursement total puis partiel | API refund OK, notes de commande |
| Signature invalide | Webhook rejeté (403), commande inchangée |

Tester sur les checkouts **classique ET blocks**, et avec HPOS activé.

## Limite connue — checkout « blocks » (2026-07-09)

La passerelle est une passerelle **classique** (`WC_Payment_Gateway`). Elle s'affiche sur
le **checkout classique** (shortcode `[woocommerce_checkout]`) — validé sur
WooCommerce 10.9.4 puis **11.0.0** / WordPress 7.0. En revanche, sur le **checkout
« blocks »** (le bloc `woocommerce/checkout`, format par défaut des installs récentes),
elle **n'apparaît pas** (« No payment methods available »), faute d'intégration de
paiement blocks (pas de `AbstractPaymentMethodType` enregistré via
`woocommerce_blocks_payment_method_type_registration`).

Depuis le 2026-08-10, le plugin **déclare cette incompatibilité** auprès de WooCommerce
(`FeaturesUtil::declare_compatibility('cart_checkout_blocks', ..., false)`). Auparavant il
ne déclarait rien du tout — ce README affirmait le contraire — et WooCommerce restait dans
l'indéterminé : aucun avertissement, et le marchand découvrait le checkout vide sans rien
pour le relier à ce plugin.

Contournement immédiat pour le marchand : utiliser le checkout classique (remplacer le
bloc de la page Commande par le shortcode `[woocommerce_checkout]`).

**À faire (amélioration plugin)** : ajouter une intégration de paiement WooCommerce Blocks
(fichier JS `payment-method` + classe PHP `AbstractPaymentMethodType`) pour supporter le
checkout blocks nativement.
