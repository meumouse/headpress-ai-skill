<?php
// Smoke test for the skill's add-on template. Run with: wp eval-file addon-test.php
$out = array();
$ok  = function( $cond, $msg ) use ( &$out ) { $out[] = ( $cond ? 'PASS ' : 'FAIL ' ) . $msg; };

$ok( headpress()->container()->get( 'modules' )->is_active( 'acme_quotes' ), 'module acme_quotes is active' );

$config = rest_do_request( new WP_REST_Request( 'GET', '/headpress/v1/store/config' ) )->get_data();
$ok( isset( $config['modules']['acme_quotes'] ), 'store config has modules.acme_quotes' );
$ok( isset( $config['modules']['acme_quotes']['min_quantity'] ) && ! isset( $config['modules']['acme_quotes']['notify_email'] ), 'public setting exposed, private setting hidden' );
$ok( ! empty( $config['modules']['acme_quotes']['endpoint'] ), 'computed public_config endpoint present' );

$product = new WC_Product_Simple();
$product->set_name( 'Acme template test product' );
$product->set_status( 'publish' );
$product->set_regular_price( '10' );
$product->update_meta_data( '_acme_quotable', 'yes' );
$pid = $product->save();

$post = function( array $body ) {
	$r = new WP_REST_Request( 'POST', '/headpress/v1/store/acme/quotes' );
	$r->set_header( 'Content-Type', 'application/json' );
	$r->set_body( wp_json_encode( $body ) );
	return rest_do_request( $r );
};

$r = $post( array( 'name' => 'Test', 'email' => 'not-an-email', 'product_id' => $pid, 'quantity' => 1 ) );
$ok( 400 === $r->get_status(), 'invalid email → 400 (got ' . $r->get_status() . ')' );

$r = $post( array( 'name' => 'Test', 'email' => 'quote-test@example.com', 'product_id' => 999999999, 'quantity' => 1 ) );
$ok( 404 === $r->get_status(), 'unknown product → 404 (got ' . $r->get_status() . ')' );

$r = $post( array( 'name' => 'Test', 'email' => 'quote-test@example.com', 'product_id' => $pid, 'quantity' => 2 ) );
$ok( 201 === $r->get_status(), 'valid request → 201 (got ' . $r->get_status() . ' ' . wp_json_encode( $r->get_data() ) . ')' );
$qid = (int) ( $r->get_data()['id'] ?? 0 );
$ok( $qid > 0 && 'acme_quote' === get_post_type( $qid ), 'quote stored as acme_quote' );

$sreq = new WP_REST_Request( 'GET', '/wc/store/v1/products/' . $pid );
$data = rest_do_request( $sreq )->get_data();
$ext  = json_decode( wp_json_encode( $data['extensions'] ?? array() ), true );
$ok( true === ( $ext['headpress']['acme_quotes']['quotable'] ?? null ), 'Store API product has extensions.headpress.acme_quotes.quotable' );

// Cleanup.
if ( $qid ) { wp_delete_post( $qid, true ); }
wp_delete_post( $pid, true );
delete_transient( 'headpress_store_config' );

echo implode( "\n", $out ) . "\n";
