<?php
/**
 * Add Toman currency to Easy Digital Downloads
 *
 * @author 				vandar.io
 * @package 			EVI
 * @subpackage 			Toman
 */

/**
 *  
 * 
 * 
 * 
 * Add Toman currency for EDD
 *
 * @param 				array $currencies Currencies list
 * @return 				array
 */
if ( ! function_exists('edd_irg_add_toman_currency')):
function edd_irg_add_tomain_currency( $currencies ) {
	$currencies['IRT'] = 'تومان';
	return $currencies;
}
endif;
add_filter( 'edd_currencies', 'edd_irg_add_tomain_currency' );

/**
 * Format decimals
 */
add_filter( 'edd_sanitize_amount_decimals', function( $decimals ) {
	
	$currency = function_exists('edd_get_currency') ? edd_get_currency() : '';
	
	global $edd_options;
	
	if ( $edd_options['currency'] == 'IRT' || $currency == 'IRT' || $edd_options['currency'] == 'RIAL' || $currency == 'RIAL' ) {
		return $decimals = 0;
	}

	return $decimals;
} );

add_filter( 'edd_format_amount_decimals', function( $decimals ) {
	
	$currency = function_exists('edd_get_currency') ? edd_get_currency() : '';
	
	global $edd_options;
	
	if ( $edd_options['currency'] == 'IRT' || $currency == 'IRT' || $edd_options['currency'] == 'RIAL' || $currency == 'RIAL' ) {
		return $decimals = 0;
	}
	
	return $decimals;
} );

if ( function_exists('per_number') ) {
	add_filter( 'edd_irt_currency_filter_after', 'per_number', 10, 2 );
}

add_filter( 'edd_irt_currency_filter_after', 'edd_toman_postfix', 10, 2 );
function edd_toman_postfix( $price, $did ) {
	return str_replace( 'IRT', 'تومان', $price );
}

add_filter( 'edd_rial_currency_filter_after', 'edd_rial_postfix', 10, 2 );
function edd_rial_postfix( $price, $did ) {
	return str_replace( 'RIAL', 'ریال', $price );
}