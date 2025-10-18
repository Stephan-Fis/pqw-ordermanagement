<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bestellung abschließen - Artikel (aggregierte Ansicht für on-hold)
 */
function pqw_page_complete_item() {
	global $pqw_order_management;

	$mode = 'complete_item';
	$button_label = __( 'Bestellung abschließen (Artikel)', 'pqw-order-management' );
	$nonce_action  = 'pqw_action_' . $mode;

	// handle POST: queue selected products' order-items into complete queue
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pqw_subpage'] ) && $_POST['pqw_subpage'] === $mode && isset( $_POST['products_action'] ) && $_POST['products_action'] === 'complete' ) {
		if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			wp_die( __( 'Nicht autorisiert', 'pqw-order-management' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( __( 'Nonce ungültig', 'pqw-order-management' ) );
		}

		$selected_products = isset( $_POST['products'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['products'] ) ) : array();
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
						$pid = intval( $item->get_product_id() );
						if ( in_array( $pid, $selected_products, true ) ) {
							$rows[] = array( 'order_id' => $order->get_id(), 'order_item_id' => $item_id );
						}
					}
				}
			}
			$inserted = 0;
			if ( ! empty( $rows ) ) {
				$inserted = $pqw_order_management->queue_complete_rows( $rows );
			}
			$redirect = add_query_arg( array( 'page' => PQW_Order_Management::PLUGIN_SLUG . '_' . $mode, 'pqw_complete_queued' => $inserted ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	// Render header
	echo '<div class="wrap">';
	echo '<h1>' . esc_html( $button_label ) . '</h1>';

	// Notices
	if ( isset( $_GET['pqw_queued'] ) ) {
		$cnt = absint( $_GET['pqw_queued'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Queue-Einträge angelegt.', 'pqw-order-management' ), $cnt ) ) . '</p></div>';
	}
	// Notices: check for pqw_complete_queued and show notice
	if ( isset( $_GET['pqw_complete_queued'] ) ) {
		$cnt = absint( $_GET['pqw_complete_queued'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Complete-Queue Einträge angelegt.', 'pqw-order-management' ), $cnt ) ) . '</p></div>';
	}

	// Check WooCommerce
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-warning"><p><strong>WooCommerce nicht aktiv.</strong> PQW Order-Management benötigt WooCommerce.</p></div>';
		echo '</div>';
		return;
	}

	// Build aggregated products from on-hold orders
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
	if ( $orders ) {
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				$pid  = intval( $item->get_product_id() );
				if ( ! $pid ) {
					continue;
				}
				if ( ! isset( $aggregated[ $pid ] ) ) {
					$aggregated[ $pid ] = array(
						'product_id'   => $pid,
						'product_name' => $item->get_name(),
						'short_desc'   => '', // product short/long descriptions are optional; can be filled from product object if needed
						'full_desc'    => '',
						'quantity'     => 0,
					);
					// try to get descriptions from product object
					$product = $item->get_product();
					if ( $product && is_object( $product ) ) {
						$aggregated[ $pid ]['short_desc'] = $product->get_short_description();
						$aggregated[ $pid ]['full_desc']  = $product->get_description();
					}
				}
				$aggregated[ $pid ]['quantity'] += intval( $item->get_quantity() );
			}
		}
	}

	if ( empty( $aggregated ) ) {
		echo '<p>Keine "wartend" Bestellungen / Artikel gefunden.</p>';
		echo '</div>';
		return;
	}

	// Build per-product per-customer aggregation for export (product_id => customer_key => data)
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
				$person_name = $billing_email ? $billing_email : __( 'Gast', 'pqw-order-management' );
			}

			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				$pid = intval( $item->get_product_id() );
				if ( ! $pid ) {
					continue;
				}
				if ( ! isset( $product_customers[ $pid ] ) ) {
					$product_customers[ $pid ] = array();
				}
				if ( ! isset( $product_customers[ $pid ][ $customer_key ] ) ) {
					$product_customers[ $pid ][ $customer_key ] = array(
						'person_name'  => $person_name,
						'email'        => $billing_email,
						'first_name'   => $billing_first,
						'last_name'    => $billing_last,
						'quantity'     => 0,
						'total'        => 0.0,
						'product_name' => $item->get_name(),
					);
					// try to fill descriptions from product object
					$product = $item->get_product();
					if ( $product && is_object( $product ) ) {
						$product_customers[ $pid ][ $customer_key ]['short_desc'] = $product->get_short_description();
						$product_customers[ $pid ][ $customer_key ]['full_desc']  = $product->get_description();
					} else {
						$product_customers[ $pid ][ $customer_key ]['short_desc'] = '';
						$product_customers[ $pid ][ $customer_key ]['full_desc']  = '';
					}
				}
				$product_customers[ $pid ][ $customer_key ]['quantity'] += intval( $item->get_quantity() );
				$product_customers[ $pid ][ $customer_key ]['total']    += floatval( $item->get_total() );
			}
		}
	}

	// expose aggregated products and per-product-per-customer dataset for client-side export and load SheetJS
	?>
	<script type="text/javascript">
		/* filepath: c:\xampp\htdocs\wp\wp-content\plugins\pqw-order-management\pages\complete-item.php */
		var pqw_export_products = <?php echo wp_json_encode( $aggregated ); ?>;
		var pqw_export_product_orders = <?php echo wp_json_encode( $product_customers ); ?>;
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
					var p = pqw_export_products[key];
					if (!p) continue;
					rows.push({
						'Artikel': p.product_name || '',
						'Beschreibung': (p.full_desc || '').replace(/(\r\n|\n|\r)/gm, " "),
						'Kurzbeschreibung': (p.short_desc || '').replace(/(\r\n|\n|\r)/gm, " "),
						'Gesamtmenge': parseInt(p.quantity || 0, 10)
					});
				}
				if (rows.length === 0) {
					alert('Keine Daten zum Export gefunden.');
					return;
				}
				var ws = XLSX.utils.json_to_sheet(rows, {header:['Artikel','Beschreibung','Kurzbeschreibung','Gesamtmenge']});
				var wb = XLSX.utils.book_new();
				XLSX.utils.book_append_sheet(wb, ws, 'Products');
				var fname = 'orders_export_article_' + localDateStamp(new Date()) + '.xlsx';
				XLSX.writeFile(wb, fname);
			}

			// NEW: export per product per person (Artikel, Person, Beschreibung, Kurzbeschreibung, Menge, Gesamtpreis für die Person)
			function exportProductsWithPersons(){
				var checked = document.querySelectorAll('input[name="products[]"]:checked');
				if (!checked || checked.length === 0) {
					alert('Bitte mindestens einen Artikel auswählen zum Export.');
					return;
				}
				// persons map: personKey => { person_name, email, total, rows: [{product_name, qty, unitPrice, total}] }
				var persons = {};
				for (var i=0;i<checked.length;i++){
					var pid = checked[i].value;
					var map = pqw_export_product_orders[pid];
					if (!map) continue;
					for (var custKey in map) {
						if (!map.hasOwnProperty(custKey)) continue;
						var entry = map[custKey];
						var qty = parseInt(entry.quantity || 0, 10) || 0;
						var total = parseFloat(entry.total || 0) || 0;
						var unitPrice = qty ? (total / qty) : total;
						// ensure person record
						if (!persons[custKey]) {
							persons[custKey] = {
								person_name: entry.person_name || ( (entry.first_name||'') + ' ' + (entry.last_name||'') ).trim() || entry.email || 'Gast',
								email: entry.email || '',
								total: 0,
								rows: []
							};
						}
						persons[custKey].rows.push({
							product_name: entry.product_name || '',
							qty: qty,
							unitPrice: unitPrice,
							total: total
						});
						persons[custKey].total += total;
					}
				}

				// build output rows: Person, Artikel, Menge, Preis, Gesamtpreis
				var out = [];
				for (var pk in persons) {
					if (!persons.hasOwnProperty(pk)) continue;
					var p = persons[pk];
					for (var r = 0; r < p.rows.length; r++) {
						var row = p.rows[r];
						out.push({
							'Person': (r === 0) ? p.person_name : '',
							'Artikel': row.product_name,
							'Menge': row.qty,
							'Preis': formatPrice(row.unitPrice),
							'Gesamtpreis': (r === 0) ? formatPrice(p.total) : ''
						});
					}
				}

				if (out.length === 0) {
					alert('Keine Daten zum Export gefunden.');
					return;
				}
				var ws = XLSX.utils.json_to_sheet(out, {header:['Person','Artikel','Menge','Preis','Gesamtpreis']});
				var wb = XLSX.utils.book_new();
				XLSX.utils.book_append_sheet(wb, ws, 'ProductOrders');
				var fname = 'orders_export_article_name_' + localDateStamp(new Date()) + '.xlsx';
				XLSX.writeFile(wb, fname);
			}

			var btn = document.getElementById('pqw_export_products_btn');
			if (btn) btn.addEventListener('click', exportProducts);

			var btn2 = document.getElementById('pqw_export_products_with_person_btn');
			if (btn2) btn2.addEventListener('click', exportProductsWithPersons);
		});
	</script>
	<?php

	// Form (add export buttons next to the submit)
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . PQW_Order_Management::PLUGIN_SLUG . '_' . $mode ) ) . '">';
	wp_nonce_field( $nonce_action );
	echo '<input type="hidden" name="pqw_subpage" value="' . esc_attr( $mode ) . '" />';
	// NEW: ensure the server-side POST handler sees that this is a "complete" submission
	echo '<input type="hidden" name="products_action" value="complete" />';
	echo '<p>';
	echo '<button type="submit" class="button button-primary" style="margin-right:10px;">' . esc_html( $button_label ) . '</button>';
	// client-side export buttons
	echo '<button type="button" id="pqw_export_products_btn" class="button" style="margin-right:10px;">' . esc_html__( 'Export XLSX', 'pqw-order-management' ) . '</button>';
	echo '<button type="button" id="pqw_export_products_with_person_btn" class="button" style="margin-right:10px;">' . esc_html__( 'Export mit Personen', 'pqw-order-management' ) . '</button>';
	echo '<span class="description">Markierte Artikel: die markierten Artikel werden aus den Bestellungen ausgegliedert und verarbeitet.</span>';
	echo '</p>';

	// Table: Artikel | Beschreibung | Kurzbeschreibung | Gesamtmenge
	echo '<div class="pqw-orders-table"><div class="table-responsive">';
	echo '<table class="table table-striped table-bordered">';
	echo '<thead class="table-dark"><tr>';
	echo '<th scope="col"><input type="checkbox" id="pqw_select_all_products" aria-label="Alle auswählen" /></th>';
	echo '<th scope="col">Artikel</th>';
	echo '<th scope="col">Beschreibung</th>';
	echo '<th scope="col">Kurzbeschreibung</th>';
	echo '<th scope="col">Gesamtmenge</th>';
	echo '</tr></thead><tbody>';

	foreach ( $aggregated as $agg ) {
		$pid   = intval( $agg['product_id'] );
		$prod  = esc_html( $agg['product_name'] );
		$short = esc_html( wp_trim_words( wp_strip_all_tags( $agg['short_desc'] ), 20, '…' ) );
		$full  = esc_html( wp_trim_words( wp_strip_all_tags( $agg['full_desc'] ), 30, '…' ) );
		$qty   = intval( $agg['quantity'] );
		$labelVal = esc_attr( $pid );

		echo '<tr>';
		echo '<td data-label="Auswählen"><input type="checkbox" name="products[]" value="' . $labelVal . '" class="pqw-product-checkbox" /></td>';
		echo '<td data-label="Artikel">' . $prod . '</td>';
		echo '<td data-label="Beschreibung">' . $full . '</td>';
		echo '<td data-label="Kurzbeschreibung">' . $short . '</td>';
		echo '<td data-label="Gesamtmenge">' . $qty . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table></div></div>';

	echo '</form>';

	// JS: select all
	?>
	<script type="text/javascript">
		(function(){
			var selectAll = document.getElementById('pqw_select_all_products');
			if (selectAll) {
				selectAll.addEventListener('change', function(){
					var checkboxes = document.querySelectorAll('input.pqw-product-checkbox');
					for (var i=0;i<checkboxes.length;i++){
						checkboxes[i].checked = selectAll.checked;
					}
				});
			}
		})();
	</script>
	<?php

	// NEW: centered overlay + spinner and polling JS when pqw_complete_queued is present
	if ( isset( $_GET['pqw_complete_queued'] ) && intval( $_GET['pqw_complete_queued'] ) > 0 ) :
		$queued = intval( $_GET['pqw_complete_queued'] );
		?>
		<script type="text/javascript">
		(function(){
			// inject spinner keyframes + minimal styles
			var style = document.createElement('style');
			style.type = 'text/css';
			style.appendChild(document.createTextNode(
				'@keyframes pqw-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
				'#pqw_complete_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
				'#pqw_complete_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
				'.pqw-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:pqw-spin 1s linear infinite;margin-bottom:12px;}'
			));
			document.head.appendChild(style);

			// create overlay element
			var overlay = document.createElement('div');
			overlay.id = 'pqw_complete_overlay';

			var box = document.createElement('div');
			box.id = 'pqw_complete_box';

			var spinner = document.createElement('div');
			spinner.className = 'pqw-spinner';

			var msg = document.createElement('div');
			msg.id = 'pqw_complete_msg';
			msg.style.textAlign = 'center';
			msg.style.fontSize = '14px';
			msg.style.color = '#222';
			msg.textContent = 'Verarbeite ' + <?php echo $queued; ?> + ' Einträge...';

			box.appendChild(spinner);
			box.appendChild(msg);
			overlay.appendChild(box);
			document.body.appendChild(overlay);

			// remove pqw_complete_queued from URL so reload doesn't retrigger the overlay
			try {
				var u = new URL(window.location.href);
				u.searchParams.delete('pqw_complete_queued');
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
							var msgEl = document.getElementById('pqw_complete_msg');
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
				xhr.send('action=pqw_complete_queue_status');
			}
			// start polling shortly
			setTimeout(checkStatus, 800);
		})();
		</script>
	<?php
	endif;

	echo '</div>';
}
