<?php
/**
 * Plugin Name: Disable WooCommerce Cart Fragments for Empty Carts
 * Description: Dequeues the wc-cart-fragments script for visitors with no items
 *              in their cart. Cart fragment AJAX fires on every page load and costs
 *              0.9-1.4 seconds regardless of Souin cache state. Visitors without
 *              the woocommerce_items_in_cart cookie have an empty cart — there is
 *              nothing for the fragment script to update. Users who have added items
 *              (cookie is present) still get the live cart counter update.
 * Version:     1.0.0
 * Author:      Code Agency
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_enqueue_scripts', static function (): void {
    if ( is_admin() ) {
        return;
    }

    // Keep fragments active for visitors who have items in their cart.
    // woocommerce_items_in_cart is set server-side by WooCommerce only when
    // the cart is non-empty; woocommerce_session_ is set for every visitor
    // and must not be used as this condition.
    if ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) ) {
        return;
    }

    wp_dequeue_script( 'wc-cart-fragments' );
    wp_deregister_script( 'wc-cart-fragments' );
}, 99 );
