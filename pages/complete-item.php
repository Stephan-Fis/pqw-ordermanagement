<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bestellung abschließen - Artikel (aggregierte Ansicht für on-hold)
 */
function om_page_complete_item() {
	global $order_management;

	$mode = 'complete_item';
	$button_label = __( 'Bestellung abschließen (Artikel)', 'om-order-management' );
	$nonce_action  = 'om_action_' . $mode;

	// handle POST: queue selected products' order-items into complete queue
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['om_subpage'] ) && $_POST['om_subpage'] === $mode && isset( $_POST['products_action'] ) && $_POST['products_action'] === 'complete' ) {
		if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			wp_die( __( 'Nicht autorisiert', 'om-order-management' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( __( 'Nonce ungültig', 'om-order-management' ) );
		}

		// we use string keys like 'v123' for variants and 'p123' for products
		$selected_products = isset( $_POST['products'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['products'] ) ) : array();
		$selected_products = array_filter( array_unique( $selected_products ) );

		if ( ! empty( $selected_products ) ) {
			// load on-hold orders and collect matching order_item_ids
			$args = array( 'limit'=>-1, 'status'=>'on-hold', 'return'=>'objects' );
			$orders = wc_get_orders( $args );
			$rows = array();
			if ( $orders ) {
				foreach ( $orders as $order ) {
					foreach ( $order->get_items() as $item_id => $item ) {
						if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) continue;
						$pid  = intval( $item->get_product_id() );
						$vid  = method_exists( $item, 'get_variation_id' ) ? intval( $item->get_variation_id() ) : 0;
						$key  = $vid > 0 ? 'v' . $vid : 'p' . $pid;
						if ( in_array( $key, $selected_products, true ) ) {
							$rows[] = array( 'order_id' => $order->get_id(), 'order_item_id' => $item_id );
						}
					}
				}
			}
			$inserted = 0;
			if ( ! empty( $rows ) ) {
				$inserted = $order_management->queue_complete_rows( $rows );
			}
			$redirect = add_query_arg( array( 'page' => Order_Management::PLUGIN_SLUG . '_' . $mode, 'om_complete_queued' => $inserted ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	// Render header
	echo '<div class="wrap">';
	echo '<h1>' . esc_html( $button_label ) . '</h1>';

	// Notices
	if ( isset( $_GET['om_queued'] ) ) {
		$cnt = absint( $_GET['om_queued'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Queue-Einträge angelegt.', 'om-order-management' ), $cnt ) ) . '</p></div>';
	}
	// Notices: check for om_complete_queued and show notice
	if ( isset( $_GET['om_complete_queued'] ) ) {
		$cnt = absint( $_GET['om_complete_queued'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Complete-Queue Einträge angelegt.', 'om-order-management' ), $cnt ) ) . '</p></div>';
	}

	// Check WooCommerce
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-warning"><p><strong>Order-Management benötigt WooCommerce.</strong></p></div>';
		echo '</div>';
		return;
	}

	// Build aggregated products from on-hold orders (now: aggregate by variant when present)
	// Load orders with status "on-hold" and aggregate items by product_id
	$args = array(
		'limit'  => -1,
		'orderby'=> 'date',
		'order'  => 'ASC',
		'return' => 'objects',
		'status' => 'on-hold',
	);
	$orders = wc_get_orders( $args );
	$aggregated = array(); // product_id => [product_name, short_desc, full_desc, quantity]
	$product_options = array(); // key => array of option strings found across orders
	if ( $orders ) {
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				$pid  = intval( $item->get_product_id() );
				$vid  = method_exists( $item, 'get_variation_id' ) ? intval( $item->get_variation_id() ) : 0;
				if ( ! $pid && ! $vid ) {
					continue;
				}
				$key = $vid > 0 ? 'v' . $vid : 'p' . $pid;
				if ( ! isset( $aggregated[ $key ] ) ) {
					// Use parent product (pid) for name and descriptions. Do NOT use the variation as fallback.
					$parent_obj = $pid > 0 ? wc_get_product( $pid ) : null;
					$parent_name  = $parent_obj && method_exists( $parent_obj, 'get_name' ) ? (string) $parent_obj->get_name() : $item->get_name();
					$parent_short = $parent_obj && method_exists( $parent_obj, 'get_short_description' ) ? (string) $parent_obj->get_short_description() : '';
					$parent_full  = $parent_obj && method_exists( $parent_obj, 'get_description' ) ? (string) $parent_obj->get_description() : '';
					// variant label from item (variation) only
					$variant_label = '';
					if ( $vid > 0 ) {
						try {
							$prod_for_label = $item->get_product();
							if ( function_exists( 'wc_get_formatted_variation' ) && $prod_for_label ) {
								$variant_label = wc_get_formatted_variation( $prod_for_label, true );
							}
						} catch ( Exception $e ) { $variant_label = ''; }
					}
					$aggregated[ $key ] = array(
						// always store parent product id
						'product_id'   => $pid,
						'product_name' => $parent_name,
						'variant_label'=> $variant_label,
						'short_desc'   => $parent_short,
						'full_desc'    => $parent_full,
						'quantity'     => 0,
					);
				}
				$aggregated[ $key ]['quantity'] += intval( $item->get_quantity() );
				// collect option/meta string for this order item for aggregated product-level options
				$meta_data = method_exists( $item, 'get_meta_data' ) ? $item->get_meta_data() : array();
				$meta_parts = array();
				if ( ! empty( $meta_data ) ) {
					foreach ( $meta_data as $md ) {
						if ( empty( $md->key ) ) continue;
						$mkey = $md->key;
						if ( strpos( $mkey, '_' ) === 0 ) continue;
						$mval = $item->get_meta( $mkey );
						if ( $mval === '' || $mval === null ) continue;
						if ( is_array( $mval ) ) {
							$mval = implode( ', ', array_map( 'strval', $mval ) );
						} else {
							$mval = (string) $mval;
						}
						$title = $mkey;
						if ( false !== strpos( $mkey, '_' ) ) {
							$parts_k = explode( '_', $mkey, 2 );
							if ( isset( $parts_k[1] ) && $parts_k[1] !== '' ) {
								$title = $parts_k[1];
							}
						}
						$title = str_replace( array( '-', '_' ), ' ', $title );
						$title = ucwords( trim( $title ) );
						$meta_parts[] = $title . ': ' . $mval;
					}
				}
				$optstr = empty( $meta_parts ) ? '' : implode( '; ', $meta_parts );
				if ( ! isset( $product_options[ $key ] ) ) $product_options[ $key ] = array();
				if ( $optstr !== '' ) $product_options[ $key ][] = $optstr;
			}
		}
	}

	// Sortiere aggregierte Produktliste alphabetisch nach Produktname (A-Z, case-insensitive)
	if ( ! empty( $aggregated ) ) {
		uasort( $aggregated, function( $a, $b ) {
			return strcasecmp( $a['product_name'], $b['product_name'] );
		} );
	}

	// Wenn keine aggregierten Artikel vorhanden sind: Tabelle nicht zeigen, stattdessen Hinweis und beenden
	if ( empty( $aggregated ) ) {
		echo '<p>Keine "wartend" Bestellungen / Artikel gefunden.</p>';
		echo '</div>';
		return;
	}

	// Build per-product per-customer aggregation for export (key => customer_key => data)
	$product_customers = array();
	if ( $orders ) {
		foreach ( $orders as $order ) {
			$user_id = $order->get_user_id();
			$billing_email = $order->get_billing_email();
			$billing_first = $order->get_billing_first_name();
			$billing_last  = $order->get_billing_last_name();
			$customer_key  = $user_id ? 'user_' . intval( $user_id ) : 'guest_' . sanitize_email( $billing_email );
			$person_name   = trim( $billing_first . ' ' . $billing_last );
			if ( empty( $person_name ) ) {
				$person_name = $billing_email ? $billing_email : __( 'Gast', 'om-order-management' );
			}

			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				$pid = intval( $item->get_product_id() );
				$vid = method_exists( $item, 'get_variation_id' ) ? intval( $item->get_variation_id() ) : 0;
				$key = $vid > 0 ? 'v' . $vid : 'p' . $pid;
				if ( ! isset( $product_customers[ $key ] ) ) {
					$product_customers[ $key ] = array();
				}
				if ( ! isset( $product_customers[ $key ][ $customer_key ] ) ) {
					// Use parent product (pid) for descriptions and product name (no variant fallback).
					$parent_obj = $pid > 0 ? wc_get_product( $pid ) : null;
					$parent_name  = $parent_obj && method_exists( $parent_obj, 'get_name' ) ? (string) $parent_obj->get_name() : $item->get_name();
					$parent_short = $parent_obj && method_exists( $parent_obj, 'get_short_description' ) ? (string) $parent_obj->get_short_description() : '';
					$parent_full  = $parent_obj && method_exists( $parent_obj, 'get_description' ) ? (string) $parent_obj->get_description() : '';
					$variant_label = '';
					if ( $vid > 0 ) {
						try {
							$prod_for_label = $item->get_product();
							if ( function_exists( 'wc_get_formatted_variation' ) && $prod_for_label ) {
								$variant_label = wc_get_formatted_variation( $prod_for_label, true );
							}
						} catch ( Exception $e ) { $variant_label = ''; }
					}
					$product_customers[ $key ][ $customer_key ] = array(
						'person_name'  => $person_name,
						'email'        => $billing_email,
						'first_name'   => $billing_first,
						'last_name'    => $billing_last,
						'quantity'     => 0,
						'total'        => 0.0,
						'product_name' => $parent_name,
						'short_desc'   => $parent_short,
						'full_desc'    => $parent_full,
						'variant_label' => $variant_label,
						// store per-order-item details so export can include options per original item
						'per_items'    => array(),
					);
 				}
				$product_customers[ $key ][ $customer_key ]['quantity'] += intval( $item->get_quantity() );
				$product_customers[ $key ][ $customer_key ]['total']    += floatval( $item->get_total() );

				// build option string for this specific order item
				$meta_data = method_exists( $item, 'get_meta_data' ) ? $item->get_meta_data() : array();
				$meta_parts = array();
				if ( ! empty( $meta_data ) ) {
					foreach ( $meta_data as $md ) {
						if ( empty( $md->key ) ) continue;
						$mkey = $md->key;
						if ( strpos( $mkey, '_' ) === 0 ) continue;
						$mval = $item->get_meta( $mkey );
						if ( $mval === '' || $mval === null ) continue;
						if ( is_array( $mval ) ) {
							$mval = implode( ', ', array_map( 'strval', $mval ) );
						} else {
							$mval = (string) $mval;
						}
						$title = $mkey;
						if ( false !== strpos( $mkey, '_' ) ) {
							$parts_k = explode( '_', $mkey, 2 );
							if ( isset( $parts_k[1] ) && $parts_k[1] !== '' ) {
								$title = $parts_k[1];
							}
						}
						$title = str_replace( array( '-', '_' ), ' ', $title );
						$title = ucwords( trim( $title ) );
						$meta_parts[] = $title . ': ' . $mval;
					}
				}
				$optstr = empty( $meta_parts ) ? '' : implode( '; ', $meta_parts );
				// push per-item entry so client export can create one row per original item (with options)
				$product_customers[ $key ][ $customer_key ]['per_items'][] = array(
					'qty'   => intval( $item->get_quantity() ),
					'total' => floatval( $item->get_total() ),
					'option'=> $optstr,
				);
			}
		}
	}

	// expose aggregated products and per-product-per-customer dataset for client-side export and load SheetJS
	// attach aggregated product-level options (unique)
	if ( ! empty( $aggregated ) ) {
		foreach ( $aggregated as $k => &$a ) {
			$opts = isset( $product_options[ $k ] ) ? array_values( array_unique( $product_options[ $k ] ) ) : array();
			$a['options'] = $opts;
		}
		unset( $a );
	}
	?>
	<script type="text/javascript">
		/* filepath: c:\xampp\htdocs\wp\wp-content\plugins\om-order-management\pages\complete-item.php */
		var om_export_products = <?php echo wp_json_encode( $aggregated ); ?>;
		var om_export_product_orders = <?php echo wp_json_encode( $product_customers ); ?>;
	</script>
	<script src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . '../assets/xlsx.full.min.js' ); ?>"></script>
	<script type="text/javascript">
		document.addEventListener('DOMContentLoaded', function () {
			function formatPrice(num){
				try {
					// Fixed two decimals and use comma as decimal separator
					return (Math.round((num||0)*100)/100).toFixed(2).replace('.', ',');
				} catch(e) {
					return '0,00';
				}
			}

			// local timestamp helper for filenames
			function localDateStamp(date){
				var pad = function(n){ return (n<10 ? '0' + n : n); };
				var y = date.getFullYear();
				var m = pad(date.getMonth() + 1);
				var d = pad(date.getDate());
				var hh = pad(date.getHours());
				var mm = pad(date.getMinutes());
				var ss = pad(date.getSeconds());
				return y + '-' + m + '-' + d + '_' + hh + '-' + mm + '-' + ss;
			}

			// existing product export (keeps as-is)
			function exportProducts(){
				var checked = document.querySelectorAll('input[name="products[]"]:checked');
				if (!checked || checked.length === 0) {
					alert('Bitte mindestens einen Artikel auswählen zum Export.');
					return;
				}
				var rows = [];
				for (var i=0;i<checked.length;i++){
					var key = checked[i].value;
					var p = om_export_products[key];
					if (!p) continue;
					var title = (p.product_name || '');
					if (p.variant_label) title = title + ' — ' + p.variant_label;

					// If we have per-customer/order-item details, output one row per ordered item including its options
					var map = om_export_product_orders[key];
					if (map) {
						for (var cust in map) {
							if (!map.hasOwnProperty(cust)) continue;
							var entry = map[cust];
							var perItems = entry.per_items && Array.isArray(entry.per_items) ? entry.per_items : null;
							if (perItems && perItems.length) {
								for (var pi = 0; pi < perItems.length; pi++) {
									var it = perItems[pi];
									rows.push({
										'Artikel': title,
										'Beschreibung': (p.full_desc || '').replace(/(\r\n|\n|\r)/gm, " "),
										'Kurzbeschreibung': (p.short_desc || '').replace(/(\r\n|\n|\r)/gm, " "),
										'Optionen': it.option || '',
										'Gesamtmenge': parseInt(it.qty || 0, 10)
									});
								}
								continue;
							}
						}
					}
				}
				if (rows.length === 0) {
					alert('Keine Daten zum Export gefunden.');
					return;
				}
				var ws = XLSX.utils.json_to_sheet(rows, {header:['Artikel','Beschreibung','Kurzbeschreibung','Optionen','Gesamtmenge']});
				var wb = XLSX.utils.book_new();
				XLSX.utils.book_append_sheet(wb, ws, 'Products');
				var fname = 'orders_export_article_' + localDateStamp(new Date()) + '.xlsx';
				XLSX.writeFile(wb, fname);
			}

			// export per product per person (Person, Artikel, Menge, Preis, Gesamtpreis)
			function exportProductsWithPersons(){
				var checked = document.querySelectorAll('input[name="products[]"]:checked');
				if (!checked || checked.length === 0) {
					alert('Bitte mindestens einen Artikel auswählen zum Export.');
					return;
				}
				// persons map: personKey => { person_name, email, total, rows: [{product_name, qty, unitPrice, total}], firstName, lastName }
				var persons = {};
				for (var i=0;i<checked.length;i++){
					var pid = checked[i].value;
					var map = om_export_product_orders[pid];
					if (!map) continue;
					for (var custKey in map) {
						if (!map.hasOwnProperty(custKey)) continue;
						var entry = map[custKey];
						// If per_items present, use them (one row per original item including options)
						var perItems = entry.per_items && Array.isArray(entry.per_items) ? entry.per_items : null;
						if (!persons[custKey]) {
							// build person display name as "Nachname, Vorname" with fallbacks
							var _ln = (entry.last_name || '').trim();
							var _fn = (entry.first_name || '').trim();
							var _pn = '';
							if (_ln && _fn) {
								_pn = _ln + ', ' + _fn;
							} else if (_ln) {
								_pn = _ln;
							} else if (_fn) {
								_pn = _fn;
							} else if (entry.person_name && entry.person_name.trim()) {
								_pn = entry.person_name.trim();
							} else if (entry.email) {
								_pn = entry.email;
							} else {
								_pn = 'Gast';
							}
							persons[custKey] = {
								person_name: _pn,
								email: entry.email || '',
								total: 0,
								rows: [],
								firstName: entry.first_name || '',
								lastName: entry.last_name || ''
							};
						}
						var title = (entry.product_name || '');
						if (entry.variant_label) title = title + ' — ' + entry.variant_label;
						if (perItems) {
							for (var pi = 0; pi < perItems.length; pi++) {
								var it = perItems[pi];
								var pqty = parseInt(it.qty || 0, 10) || 0;
								var ptotal = parseFloat(it.total || 0) || 0;
								var punit = pqty ? (ptotal / pqty) : ptotal;
								var popt = it.option || '';
								persons[custKey].rows.push({
									product_name: title,
									qty: pqty,
									unitPrice: punit,
									total: ptotal,
									options: popt
								});
								persons[custKey].total += ptotal;
							}
						} else {
							var qty = parseInt(entry.quantity || 0, 10) || 0;
							var total = parseFloat(entry.total || 0) || 0;
							var unitPrice = qty ? (total / qty) : total;
							persons[custKey].rows.push({
								product_name: title,
								qty: qty,
								unitPrice: unitPrice,
								total: total,
								options: ''
							});
							persons[custKey].total += total;
						}
					}
				}

				// build output rows: Person, Artikel, Menge, Preis, Gesamtpreis
				var out = [];
				// create sortable list of persons
				var personList = [];
				for (var pk in persons) {
					if (!persons.hasOwnProperty(pk)) continue;
					personList.push({ key: pk, data: persons[pk] });
				}
				// sort by last name then first name (case-insensitive, locale-aware), fallback to person_name/email/'Gast'
				personList.sort(function(a,b){
					var la = (a.data.lastName || '').trim();
					var lb = (b.data.lastName || '').trim();
					// compare last names first
					if (la || lb) {
						var cmp = la.localeCompare(lb, undefined, { sensitivity: 'base' });
						if (cmp !== 0) return cmp;
					}
					// last names equal or missing -> compare first names
					var fa = (a.data.firstName || '').trim();
					var fb = (b.data.firstName || '').trim();
					if (fa || fb) {
						var cmp2 = fa.localeCompare(fb, undefined, { sensitivity: 'base' });
						if (cmp2 !== 0) return cmp2;
					}
					// fallback to person_name or email
					var da = (a.data.person_name && a.data.person_name.trim()) || a.data.email || 'Gast';
					var db = (b.data.person_name && b.data.person_name.trim()) || b.data.email || 'Gast';
					return da.localeCompare(db, undefined, { sensitivity: 'base' });
				});

				// iterate sorted persons and build rows
				for (var i = 0; i < personList.length; i++) {
					var p = personList[i].data;
					for (var r = 0; r < p.rows.length; r++) {
						var row = p.rows[r];
						out.push({
							'Person': (r === 0) ? p.person_name : '',
							'Artikel': row.product_name,
							'Menge': row.qty,
							'Preis': formatPrice(row.unitPrice),
							'Optionen': row.options || '',
							'Gesamtpreis': (r === 0) ? formatPrice(p.total) : ''
						});
					}
				}

				if (out.length === 0) {
					alert('Keine Daten zum Export gefunden.');
					return;
				}
				var ws = XLSX.utils.json_to_sheet(out, {header:['Person','Artikel','Menge','Preis','Optionen','Gesamtpreis']});
				var wb = XLSX.utils.book_new();
				XLSX.utils.book_append_sheet(wb, ws, 'ProductOrders');
				var fname = 'orders_export_article_name_' + localDateStamp(new Date()) + '.xlsx';
				XLSX.writeFile(wb, fname);
			}

			// robust init: wait for buttons to exist and ensure SheetJS is loaded before calling exports
			function loadSheetJS(cb){
				if (window.XLSX) { cb(); return; }
				var s = document.createElement('script');
				s.src = '<?php echo esc_url( plugin_dir_url( __FILE__ ) . "../assets/xlsx.full.min.js" ); ?>';
				s.onload = cb;
				document.head.appendChild(s);
			}

			function waitFor(selector, cb){
				var el = document.querySelector(selector);
				if (el) { cb(el); return; }
				var t = setInterval(function(){
					el = document.querySelector(selector);
					if (el) { clearInterval(t); cb(el); }
				}, 150);
			}

			waitFor('#om_export_products_btn', function(el){
				el.addEventListener('click', function(){
					loadSheetJS(function(){ exportProducts(); });
				});
			});
			waitFor('#om_export_products_with_person_btn', function(el){
				el.addEventListener('click', function(){
					loadSheetJS(function(){ exportProductsWithPersons(); });
				});
			});
		});
	</script>
	<?php

	// Form (add export buttons next to the submit)
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . Order_Management::PLUGIN_SLUG . '_' . $mode ) ) . '">';
	wp_nonce_field( $nonce_action );
	echo '<input type="hidden" name="om_subpage" value="' . esc_attr( $mode ) . '" />';
	// NEW: ensure the server-side POST handler sees that this is a "complete" submission
	echo '<input type="hidden" name="products_action" value="complete" />';
	echo '<p>';
	echo '<button type="submit" class="button button-primary" style="margin-right:10px;">' . esc_html( $button_label ) . '</button>';
	// client-side export buttons
	echo '<button type="button" id="om_export_products_btn" class="button" style="margin-right:10px;">' . esc_html__( 'Export XLSX', 'om-order-management' ) . '</button>';
	echo '<button type="button" id="om_export_products_with_person_btn" class="button" style="margin-right:10px;">' . esc_html__( 'Export mit Personen', 'om-order-management' ) . '</button>';
	echo '<span class="description">Markierte Artikel: die markierten Artikel werden aus den Bestellungen ausgegliedert und verarbeitet.</span>';
	echo '</p>';

	// Table: Artikel | Beschreibung | Kurzbeschreibung | Gesamtmenge
	echo '<div class="om-orders-table"><div class="table-responsive">';
	echo '<table class="table table-striped table-bordered">';
	echo '<thead class="table-dark"><tr>';
	echo '<th scope="col"><input type="checkbox" id="om_select_all_products" aria-label="Alle auswählen" /></th>';
	echo '<th scope="col">Artikel</th>';
	echo '<th scope="col">Beschreibung</th>';
	echo '<th scope="col">Kurzbeschreibung</th>';
	echo '<th scope="col">Optionen</th>';
	echo '<th scope="col">Gesamtmenge</th>';
	echo '</tr></thead><tbody>';

	foreach ( $aggregated as $key => $agg ) {
		// Use parent product only for product info (no variant fallback)
		$prod_raw  = isset( $agg['product_name'] ) ? trim( $agg['product_name'] ) : '';
		$short_raw = isset( $agg['short_desc'] ) ? trim( $agg['short_desc'] ) : '';
		$full_raw  = isset( $agg['full_desc'] ) ? trim( $agg['full_desc'] ) : '';
		$pid       = isset( $agg['product_id'] ) ? intval( $agg['product_id'] ) : 0;
		if ( ( $prod_raw === '' || $short_raw === '' || $full_raw === '' ) && $pid > 0 ) {
			$prod_obj = wc_get_product( $pid );
			if ( $prod_obj ) {
				if ( $prod_raw === '' && method_exists( $prod_obj, 'get_name' ) ) $prod_raw = (string) $prod_obj->get_name();
				if ( $short_raw === '' && method_exists( $prod_obj, 'get_short_description' ) ) $short_raw = (string) $prod_obj->get_short_description();
				if ( $full_raw === '' && method_exists( $prod_obj, 'get_description' ) ) $full_raw = (string) $prod_obj->get_description();
			}
			// wenn parent keine Beschreibung hat, bleibt das Feld leer (kein Variant-Fallback)
		}
		$prod = esc_html( $prod_raw );
		$variant_label = ! empty( $agg['variant_label'] ) ? esc_html( $agg['variant_label'] ) : '';
		$short = esc_html( wp_trim_words( wp_strip_all_tags( $short_raw ), 20, '…' ) );
		$full  = esc_html( wp_trim_words( wp_strip_all_tags( $full_raw ), 30, '…' ) );
		$qty   = intval( $agg['quantity'] );
		$labelVal = esc_attr( $key ); // key is 'v123' or 'p123'

		// make variants visible by appending variant label to the product display
		$prod_display = $prod;
		if ( $variant_label !== '' ) {
			$prod_display = $prod_display . ' — ' . $variant_label;
		}

		// Collect per-product option/meta rows from original orders' items
		$option_rows = array();
		if ( $orders ) {
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item_obj ) {
					if ( ! is_a( $item_obj, 'WC_Order_Item_Product' ) ) continue;
					$pid_i = intval( $item_obj->get_product_id() );
					$vid_i = method_exists( $item_obj, 'get_variation_id' ) ? intval( $item_obj->get_variation_id() ) : 0;
					$k_i = $vid_i > 0 ? 'v' . $vid_i : 'p' . $pid_i;
					if ( $k_i !== $key ) continue;
					$meta_data = method_exists( $item_obj, 'get_meta_data' ) ? $item_obj->get_meta_data() : array();
					$meta_parts = array();
					if ( ! empty( $meta_data ) ) {
						foreach ( $meta_data as $md ) {
							if ( empty( $md->key ) ) continue;
							$mkey = $md->key;
							if ( strpos( $mkey, '_' ) === 0 ) continue;
							$mval = $item_obj->get_meta( $mkey );
							if ( $mval === '' || $mval === null ) continue;
							if ( is_array( $mval ) ) {
								$mval = implode( ', ', array_map( 'strval', $mval ) );
							} else {
								$mval = (string) $mval;
							}
							$title = $mkey;
							if ( false !== strpos( $mkey, '_' ) ) {
								$parts_k = explode( '_', $mkey, 2 );
								if ( isset( $parts_k[1] ) && $parts_k[1] !== '' ) {
									$title = $parts_k[1];
								}
							}
							$title = str_replace( array( '-', '_' ), ' ', $title );
							$title = ucwords( trim( $title ) );
							$meta_parts[] = $title . ': ' . $mval;
						}
					}
					if ( empty( $meta_parts ) ) {
						$option_rows[] = '';
					} else {
						$option_rows[] = implode( '; ', $meta_parts );
					}
				}
			}
		}
		if ( empty( $option_rows ) ) $option_rows[] = '';

		$rowspan = max( 1, count( $option_rows ) );

		// render first row with product info and first Optionen entry
		echo '<tr>';
		echo '<td data-label="Auswählen" rowspan="' . esc_attr( $rowspan ) . '"><input type="checkbox" name="products[]" value="' . $labelVal . '" class="om-product-checkbox" /></td>';
		echo '<td data-label="Artikel" rowspan="' . esc_attr( $rowspan ) . '">' . esc_html( $prod_display ) . '</td>';
		echo '<td data-label="Beschreibung" rowspan="' . esc_attr( $rowspan ) . '">' . $full . '</td>';
		echo '<td data-label="Kurzbeschreibung" rowspan="' . esc_attr( $rowspan ) . '">' . $short . '</td>';
		echo '<td data-label="Optionen">' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $option_rows[0] ), 20, '…' ) ) . '</td>';
		echo '<td data-label="Gesamtmenge" rowspan="' . esc_attr( $rowspan ) . '">' . $qty . '</td>';
		echo '</tr>';

		// additional option rows (if any)
		for ( $oi = 1; $oi < count( $option_rows ); $oi++ ) {
			echo '<tr>';
			echo '<td data-label="Optionen">' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $option_rows[ $oi ] ), 20, '…' ) ) . '</td>';
			echo '</tr>';
		}
	}

	echo '</tbody></table></div></div>';

	echo '</form>';

	// JS: select all
	?>
	<script type="text/javascript">
		(function(){
			var selectAll = document.getElementById('om_select_all_products');
			if (selectAll) {
				selectAll.addEventListener('change', function(){
					var checkboxes = document.querySelectorAll('input.om-product-checkbox');
					for (var i=0;i<checkboxes.length;i++){
						checkboxes[i].checked = selectAll.checked;
					}
				});
			}
		})();
	</script>
	<?php

	// NEW: centered overlay + spinner and polling JS when om_complete_queued is present
	if ( isset( $_GET['om_complete_queued'] ) && intval( $_GET['om_complete_queued'] ) > 0 ) :
		$queued = intval( $_GET['om_complete_queued'] );
		?>
		<script type="text/javascript">
		(function(){
			// inject spinner keyframes + minimal styles
			var style = document.createElement('style');
			style.type = 'text/css';
			style.appendChild(document.createTextNode(
				'@keyframes om-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
				'#om_complete_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
				'#om_complete_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
				'.om-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:om-spin 1s linear infinite;margin-bottom:12px;}'
			));
			document.head.appendChild(style);

			// create overlay element
			var overlay = document.createElement('div');
			overlay.id = 'om_complete_overlay';

			var box = document.createElement('div');
			box.id = 'om_complete_box';

			var spinner = document.createElement('div');
			spinner.className = 'om-spinner';

			var msg = document.createElement('div');
			msg.id = 'om_complete_msg';
			msg.style.textAlign = 'center';
			msg.style.fontSize = '14px';
			msg.style.color = '#222';
			msg.textContent = 'Verarbeite ' + <?php echo $queued; ?> + ' Einträge...';

			box.appendChild(spinner);
			box.appendChild(msg);
			overlay.appendChild(box);
			document.body.appendChild(overlay);

			// remove om_complete_queued from URL so reload doesn't retrigger the overlay
			try {
				var u = new URL(window.location.href);
				u.searchParams.delete('om_complete_queued');
				history.replaceState && history.replaceState(null, '', u.toString());
			} catch (e) { /* ignore */ }

			// poll complete queue status
			function checkStatus(){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.onload = function(){
					try {
						var res = JSON.parse(xhr.responseText);
						if (res && res.success && res.data) {
							var pending = parseInt(res.data.pending,10);
							var msgEl = document.getElementById('om_complete_msg');
							msgEl.textContent = 'Verbleibend: ' + pending;
							if (pending > 0) {
								setTimeout(checkStatus, 1500);
							} else {
								msgEl.textContent = 'Abarbeitung abgeschlossen.';
								setTimeout(function(){
									window.location.reload();
								}, 1000);
							}
						}
					} catch(e){}
				};
				xhr.send('action=om_complete_queue_status');
			}
			// start polling shortly
			setTimeout(checkStatus, 800);
		})();
		</script>
	<?php
	endif;

	// NEW: ensure processing starts also when there are pending complete-queue entries (page load)
	?>
	<script type="text/javascript">
		(function(){
			function omCreateCompleteOverlay(initial){
				if (document.getElementById('om_complete_overlay')) return;
				var style = document.createElement('style');
				style.type = 'text/css';
				style.appendChild(document.createTextNode(
					'@keyframes om-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
					'#om_complete_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
					'#om_complete_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
					'.om-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:om-spin 1s linear infinite;margin-bottom:12px;}'
				));
				document.head.appendChild(style);

				var overlay = document.createElement('div');
				overlay.id = 'om_complete_overlay';

				var box = document.createElement('div');
				box.id = 'om_complete_box';

				var spinner = document.createElement('div');
				spinner.className = 'om-spinner';

				var msg = document.createElement('div');
				msg.id = 'om_complete_msg';
				msg.style.textAlign = 'center';
				msg.style.fontSize = '14px';
				msg.style.color = '#222';
				msg.textContent = 'Verarbeite ' + (initial||0) + ' Einträge...';

				box.appendChild(spinner);
				box.appendChild(msg);
				overlay.appendChild(box);
				document.body.appendChild(overlay);
			}

			function omRemoveQueryParam(param){
				try {
					var u = new URL(window.location.href);
					u.searchParams.delete(param);
					history.replaceState && history.replaceState(null, '', u.toString());
				} catch (e) { /* ignore */ }
			}

			function omCheckCompleteStatus(cb){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.onload = function(){
					try {
						var res = JSON.parse(xhr.responseText);
						cb && cb(res);
					} catch(e){
						cb && cb(null);
					}
				};
				xhr.send('action=om_complete_queue_status');
			}

			function omTriggerCompleteProcessing(){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.send('action=om_process_complete_queue_async');
			}

			function omStartCompletePolling(){
				(function poll(){
					omCheckCompleteStatus(function(res){
						try {
							if (res && res.success && res.data) {
								var pending = parseInt(res.data.pending,10) || 0;
								var msgEl = document.getElementById('om_complete_msg');
								if (msgEl) msgEl.textContent = 'Verbleibend: ' + pending;
								if (pending > 0) {
									setTimeout(poll, 1500);
								} else {
									if (msgEl) msgEl.textContent = 'Abarbeitung abgeschlossen.';
									setTimeout(function(){ window.location.reload(); }, 1000);
								}
							} else {
								setTimeout(poll, 2500);
							}
						} catch(e){
							setTimeout(poll, 2500);
						}
					});
				})();
			}

			document.addEventListener('DOMContentLoaded', function(){
				if (document.getElementById('om_complete_overlay')) return;
				omCheckCompleteStatus(function(res){
					if (res && res.success && res.data) {
						var pending = parseInt(res.data.pending,10) || 0;
						if (pending > 0) {
							omCreateCompleteOverlay(pending);
							omRemoveQueryParam('om_complete_queued');
							omTriggerCompleteProcessing();
							setTimeout(omStartCompletePolling, 700);
						}
					}
				});
			});
		})();
	</script>
	<?php

	echo '</div>';
}
