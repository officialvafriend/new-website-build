<?php
/**
 * 상품 목록 — 전체 상품 · 분류 · 검색 결과.
 *
 * 테마 목록은 제목을 1px 로 숨기고 결과 수도 없이 카드만 깐다. 여기서는
 * 제목 · 건수 · 정렬을 한 줄로 두고, 카드는 홈과 같은 `card()` 로 그린다.
 * 쿼리는 워드프레스 메인 쿼리 그대로다 — 페이지네이션 · 정렬 · 검색이 그대로 돈다.
 *
 * @package Duckhoo\Redesign
 */

defined( 'ABSPATH' ) || exit;

use function Duckhoo\Redesign\Front\{card, icon};

$dha_total = (int) $GLOBALS['wp_query']->found_posts;
$dha_title = is_search() ? '“' . get_search_query() . '” 검색 결과' : (string) woocommerce_page_title( false );
$dha_desc  = is_product_taxonomy() ? (string) term_description() : '';
$dha_shop  = wc_get_page_permalink( 'shop' );
$dha_cats  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 8 ) );
$dha_cats  = is_wp_error( $dha_cats ) ? array() : $dha_cats;
$dha_cur   = is_product_taxonomy() ? get_queried_object_id() : 0;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'dhr dhr-wrap dha-page' ); ?>>
<?php wp_body_open(); ?>
<main id="content" class="dha-main">
<div class="wrap">
	<?php wc_print_notices(); ?>

	<header class="dha-head">
		<div class="dha-head__t">
			<h1><?php echo esc_html( $dha_title ); ?></h1>
			<?php if ( $dha_total ) : ?><span class="dha-n"><?php echo esc_html( number_format_i18n( $dha_total ) ); ?>종</span><?php endif; ?>
		</div>
		<?php if ( '' !== trim( wp_strip_all_tags( $dha_desc ) ) ) : ?><p class="dha-desc"><?php echo wp_kses_post( $dha_desc ); ?></p><?php endif; ?>
	</header>

	<?php if ( $dha_cats ) : ?>
	<nav class="dha-chips" aria-label="분류">
		<a class="<?php echo ( is_shop() && ! is_search() ) ? 'on' : ''; ?>" href="<?php echo esc_url( $dha_shop ); ?>">전체</a>
		<?php foreach ( $dha_cats as $dha_c ) : ?>
		<a class="<?php echo $dha_c->term_id === $dha_cur ? 'on' : ''; ?>" href="<?php echo esc_url( get_term_link( $dha_c ) ); ?>"><?php echo esc_html( $dha_c->name ); ?></a>
		<?php endforeach; ?>
	</nav>
	<?php endif; ?>

	<?php if ( have_posts() ) : ?>
		<div class="dha-bar">
			<span class="dha-bar__n"><?php echo esc_html( number_format_i18n( $dha_total ) ); ?>개 상품</span>
			<?php woocommerce_catalog_ordering(); ?>
		</div>
		<div class="grid grid4 dha-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				$dha_p = wc_get_product( get_the_ID() );
				if ( $dha_p ) {
					echo card( $dha_p ); // phpcs:ignore
				}
			endwhile;
			?>
		</div>
		<?php woocommerce_pagination(); ?>
	<?php else : ?>
		<div class="dha-empty">
			<span class="dha-empty__ic"><?php echo icon( 'search' ); // phpcs:ignore ?></span>
			<p><b>찾는 상품이 없습니다.</b><br>다른 이름으로 찾아보거나, 전체 상품에서 골라 보세요.</p>
			<a class="btn btn-d" href="<?php echo esc_url( $dha_shop ); ?>">전체 상품 보기 <?php echo icon( 'arrow' ); // phpcs:ignore ?></a>
		</div>
	<?php endif; ?>
</div>
</main>
<?php wp_footer(); ?>
</body>
</html>
