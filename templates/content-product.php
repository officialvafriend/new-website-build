<?php
/**
 * 목록 카드 — WooCommerce 루프 안에서 우리 카드를 그린다.
 *
 * @package DuckhooRedesign
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $product;

if ( ! $product instanceof \WC_Product || ! $product->is_visible() ) {
	return;
}

echo '<li class="product">' . \Duckhoo\Redesign\Front\card( $product ) . '</li>'; // phpcs:ignore
