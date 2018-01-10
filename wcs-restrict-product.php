<?php
/*
* Plugin Name: WooCommerce Subscriptions Restrict Product
* Plugin URI: https://github.com/Prospress/woocommerce-subscriptions-restrict-product/
* Description: Restricts subscription products to a certain number of total active (unended) subscriptions on a site.
* Author: Prospress Inc.
* Author URI: https://prospress.com/
* License: GPLv3
* Version: 1.0.0
* Requires at least: 4.0
* Tested up to: 4.8
*
* GitHub Plugin URI: Prospress/woocommerce-subscriptions-one-to-one
* GitHub Branch: master
*
* Copyright 2017 Prospress, Inc.  (email : freedoms@prospress.com)
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* @package		WooCommerce Subscriptions
* @author		Prospress Inc.
* @since		1.0
*/

require_once( 'includes/class-pp-dependencies.php' );

if ( false === PP_Dependencies::is_woocommerce_active( '3.0' ) ) {
	PP_Dependencies::enqueue_admin_notice( 'WooCommerce Subscriptions One-to-One', 'WooCommerce', '3.0' );
	return;
}

if ( false === PP_Dependencies::is_subscriptions_active( '2.1' ) ) {
	PP_Dependencies::enqueue_admin_notice( 'WooCommerce Subscriptions One-to-One', 'WooCommerce Subscriptions', '2.1' );
	return;
}

register_activation_hook( __FILE__,'create_wcs_restriction_cache'); // creates array of product IDs in the options table when plugin is activated
add_filter( 'woocommerce_subscription_status_updated', 'update_wcs_restriction_cache', 10, 3 ); // updates the array whenever subscription status is updated
add_filter( 'woocommerce_is_purchasable', 'wcs_restriction_is_purchasable', 10, 2 ); // disallows purchasing from the single product page only
register_deactivation_hook( __FILE__, 'cleanup_wcs_restriction_cache' ); // deletes array when plugin is deactivated

/**
* creates array of product IDs in the options table when plugin is activated
*/
function create_wcs_restriction_cache() {

	$products_with_subscription = [];

	$unended_subscriptions = wcs_get_subscriptions( array(
		'subscription_status' => array( 'active', 'pending', 'on-hold', 'pending-cancel' ),
	) );

	foreach ($unended_subscriptions as $subscription) {
		foreach ( $subscription->get_items() as $item_id => $line_item ) {
			$product_id = $line_item->get_product_id();
			$products_with_subscription[$product_id]++;
		}
	}

	update_option( 'wcs_restriction_cache', $products_with_subscription );
}

/**
* updates the array whenever subscription status is updated
*
* @param instance of a WC_Subscription object
* @param string
* @param string
*/
function update_wcs_restriction_cache($subscription, $new_status, $old_status) {
error_log("updating cache");
	$cache = get_option('wcs_restriction_cache');

	$unended_statuses = array('active', 'on-hold', 'pending-cancel');

	if (in_array($new_status, $unended_statuses) && !in_array($old_status, $unended_statuses)) {
		foreach ( $subscription->get_items() as $item_id => $line_item ) {
			$product_id = $line_item->get_product_id();
			// increment product id in cache
			if (!in_array($product_id, $cache)) {
				$cache[$product_id]++;
				error_log("added product " . $product_id);
			}
		}
		update_option( 'wcs_restriction_cache', $cache );

	} elseif (in_array($old_status, $unended_statuses) && !in_array($new_status, $unended_statuses)) {
		foreach ( $subscription->get_items() as $item_id => $line_item ) {
			$product_id = $line_item->get_product_id();

			if ($cache[$product_id] <= 1) {
				unset($cache[$product_id]); // remove product from array completely
				error_log("deleted product " . $product_id);
			} elseif ($cache[$product_id] > 1) {
				$cache[$product_id]--; // or just decrement its value
				error_log("decremented product " . $product_id);
			}

		}
		update_option( 'wcs_restriction_cache', $cache );
	}

}

/**
* disallows purchasing from the single product page only
*
* @param boolean
* @param instance of WC_Product object
*/
function wcs_restriction_is_purchasable( $purchasable, $product ){
    if( is_woocommerce() && wcs_is_restricted( $product->get_id() ) ) // cart and checkout are standard pages with shortcodes and thus are not included in the is_woocommerce check. this allows the product to run through checkout for failed renewal payments
        $purchasable = false;
    return $purchasable;
}

/**
* checks against cache to see if subscription is restricted
*
* @param integer
*/
function wcs_is_restricted( $id ) {
	$is_restricted = false;
	$cache = get_option('wcs_restriction_cache');
	error_log(print_r($cache, TRUE));
	if ($cache != false) {
		if ( isset($cache[$id]) && ($cache[$id] >= 1) ) {
			$is_restricted = true;
		}
	}
	return $is_restricted;
}

/**
* deletes array when plugin is deactivated
*/
function cleanup_wcs_restriction_cache() {
	delete_option( 'wcs_restriction_cache' );
}