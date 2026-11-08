<?php
// Template of the Phase 12 E2E block.
$items = array();
while ( cfs_have_rows( 'items' ) ) {
	cfs_the_row();
	$items[] = (string) cfs_get_sub_field( 'item' );
}
?>
<h2 class="p12e-heading"><?php echo esc_html( (string) cfs_get_field( 'heading' ) ); ?></h2>
<?php if ( cfs_get_field( 'badge' ) ) : ?><span class="p12e-badge">NEW</span><?php endif; ?>
<ul class="p12e-items"><?php foreach ( $items as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul>
