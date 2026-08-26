/**
 * Enregistrement de Mobupay dans le tunnel de commande « blocs » de WooCommerce.
 *
 * PLAN-581. Source non minifiee, sans etape de construction : pas de JSX, pas de
 * paquet a compiler, donc rien a fournir en plus a la revue de wordpress.org.
 *
 * Le modele est une REDIRECTION : il n'y a aucun champ a saisir ici. Le tunnel
 * appelle l'API Store, qui appelle `process_payment()` cote PHP, qui renvoie une
 * URL de redirection. Ce fichier ne fait donc qu'afficher le moyen de paiement.
 */
( function ( wc, wp ) {
	'use strict';

	if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp || ! wp.element ) {
		return;
	}

	var settings = wc.wcSettings.getSetting( 'mobupay_data', {} );
	var createElement = wp.element.createElement;
	var decode =
		wp.htmlEntities && wp.htmlEntities.decodeEntities
			? wp.htmlEntities.decodeEntities
			: function ( value ) {
					return value;
			  };

	var label = decode( settings.title || 'Carte bancaire (Mobupay)' );
	var description = decode( settings.description || '' );

	function Description() {
		if ( ! description ) {
			return null;
		}
		return createElement( 'div', { className: 'mobupay-blocks-description' }, description );
	}

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'mobupay',
		label: createElement( 'span', null, label ),
		ariaLabel: label,
		content: createElement( Description, null ),
		edit: createElement( Description, null ),
		// La passerelle n'a aucune contrainte de panier : pas de montant minimum,
		// pas de restriction de pays cote client. Le refus eventuel vient de l'API.
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )( window.wc, window.wp );
