<?php
// Phase 12 E2E: registers a custom block. Copy to wp-content/mu-plugins/ and p12-templates-hero.php (as p12-templates/hero.php).
add_action( 'init', function () {
	if ( ! function_exists( 'cfstudio_register_block' ) ) {
		return;
	}
	cfstudio_register_block(
		array(
			'name'            => 'p12e-hero',
			'title'           => 'P12e Hero',
			'description'     => 'E2E hero block',
			'icon'            => 'star-filled',
			'category'        => 'text',
			'render_template' => __DIR__ . '/p12-templates/hero.php',
		)
	);
}, 1 );
