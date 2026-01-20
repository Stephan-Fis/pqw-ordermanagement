<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bestellungen weiterverarbeiten - Name
 */
function om_page_split_name() {
	global $order_management;
	// reuse logic from previous render_subpage for mode 'split_name'
	$mode = 'split_name';
	$is_split = true;
	$button_label = __( 'Bestellungen weiterverarbeiten', 'pqw-order-management' );
	$target_status = 'on-hold';
	$nonce_action = 'om_action_' . $mode;

	// Handle POST
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['om_subpage'] ) && $_POST['om_subpage'] === $mode ) {
		if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			wp_die( __( 'Nicht autorisiert', 'pqw-order-management' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( __( 'Nonce ungültig', 'pqw-order-management' ) );
		}
		$selected_customers = isset( $_POST['customers'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['customers'] ) ) : array();
		$selected_customers = array_filter( array_unique( $selected_customers ) );
		if ( empty( $selected_customers ) ) {
			$redirect = add_query_arg( array( 'page' => Order_Management::PLUGIN_SLUG . '_' . $mode, 'om_updated' => 0 ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$customers = $order_management->get_processing_customers();

		// ALWAYS queue entries (no immediate processing), build rows and insert into queue
		$rows = array();
		foreach ( $selected_customers as $customer_key ) {
			if ( ! isset( $customers[ $customer_key ] ) ) {
				continue;
			}
			foreach ( $customers[ $customer_key ]['rows'] as $r ) {
				// ensure we only pass order_id/order_item_id (queue_rows will ignore other fields)
				$rows[] = array(
					'order_id'      => isset( $r['order_id'] ) ? intval( $r['order_id'] ) : 0,
					'order_item_id' => isset( $r['order_item_id'] ) ? intval( $r['order_item_id'] ) : 0,
				);
			}
		}

		$inserted = 0;
		if ( ! empty( $rows ) ) {
			$inserted = $order_management->queue_rows( $rows );
		}

		$redirect = add_query_arg( array( 'page' => Order_Management::PLUGIN_SLUG . '_' . $mode, 'om_queued' => $inserted ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	// Render page
	echo '<div class="wrap">';
	echo '<h1>' . esc_html( $button_label ) . ' — ' . esc_html( str_replace( '_', ' ', $mode ) ) . '</h1>';

	if ( isset( $_GET['pqw_updated'] ) ) {
		$cnt = absint( $_GET['pqw_updated'] );
		if ( $cnt > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d Bestellung verarbeitet.', '%d Bestellungen verarbeitet.', $cnt, 'pqw-order-management' ), $cnt ) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Keine Bestellungen ausgewählt oder keine Änderungen erforderlich.', 'pqw-order-management' ) . '</p></div>';
		}
	}
	if ( isset( $_GET['pqw_queued'] ) ) {
		$cnt = absint( $_GET['pqw_queued'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Queue-Einträge angelegt.', 'pqw-order-management' ), $cnt ) ) . '</p></div>';
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-warning"><p><strong>WooCommerce nicht aktiv.</strong> PQW Order-Management benötigt WooCommerce.</p></div>';
		echo '</div>';
		return;
	}

	$customers = $order_management->get_processing_customers();
	if ( empty( $customers ) ) {
		echo '<p>Keine "in Bearbeitung" Bestellungen gefunden.</p>';
		echo '</div>';
		return;
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . Order_Management::PLUGIN_SLUG . '_' . $mode ) ) . '">';
	wp_nonce_field( $nonce_action );
	echo '<input type="hidden" name="om_subpage" value="' . esc_attr( $mode ) . '" />';
	echo '<p>';
	echo '<button type="submit" class="button button-primary" style="margin-right:10px;">' . esc_html( $button_label ) . '</button>';
	echo '<span class="description">Markierte Personen: alle Bestellungen/Artikel dieser Person werden getrennt/auf "wartend" gesetzt.</span>';
	echo '</p>';

	// Replace the simple table rendering with aggregated per-customer view:
	// Varianten (vid>0) werden unter 'v{vid}' aggregiert, Artikel ohne Variante unter 'p{pid}' aggregiert.
	$aggregated_by_customer = array();
	foreach ( $customers as $cust_key => $cust_data ) {
		$agg = array();
		foreach ( $cust_data['rows'] as $r ) {
			$pid = isset( $r['product_id'] ) ? intval( $r['product_id'] ) : 0;
			$vid = isset( $r['variation_id'] ) ? intval( $r['variation_id'] ) : 0;
			if ( ! $pid ) {
				continue;
			}
			if ( $vid > 0 ) {
				$key = 'v' . $vid;
				if ( ! isset( $agg[ $key ] ) ) {
					// Always store parent product id and use parent product for name/descriptions.
					$parent_obj   = $pid > 0 ? wc_get_product( $pid ) : null;
					$parent_name  = $parent_obj && method_exists( $parent_obj, 'get_name' ) ? (string) $parent_obj->get_name() : ( isset( $r['product_name'] ) ? $r['product_name'] : '' );
					$parent_short = $parent_obj && method_exists( $parent_obj, 'get_short_description' ) ? (string) $parent_obj->get_short_description() : ( isset( $r['short_desc'] ) ? $r['short_desc'] : '' );
					$parent_full  = $parent_obj && method_exists( $parent_obj, 'get_description' ) ? (string) $parent_obj->get_description() : ( isset( $r['full_desc'] ) ? $r['full_desc'] : '' );
					$agg[ $key ] = array(
						// store parent product id (ARTICLE), not the variation id
						'product_id'    => $pid,
						'product_name'  => $parent_name,
						'variation_id'  => $vid,
						'variant_label' => isset( $r['variant_label'] ) ? $r['variant_label'] : '',
						'short_desc'    => $parent_short,
						'full_desc'     => $parent_full,
						'quantity'      => 0,
					);
 				}
				$agg[ $key ]['quantity'] += isset( $r['quantity'] ) ? intval( $r['quantity'] ) : 0;
 			} else {
				// keine Variante -> nach Produkt zusammenfassen (p{pid})
				$key = 'p' . $pid;
				if ( ! isset( $agg[ $key ] ) ) {
					$agg[ $key ] = array(
						'product_id'    => $pid,
						'product_name'  => isset( $r['product_name'] ) ? $r['product_name'] : '',
						'variation_id'  => 0,
						'variant_label' => '',
						'short_desc'    => isset( $r['short_desc'] ) ? $r['short_desc'] : '',
						'full_desc'     => isset( $r['full_desc'] ) ? $r['full_desc'] : '',
						'quantity'      => 0,
					);
				}
				$agg[ $key ]['quantity'] += isset( $r['quantity'] ) ? intval( $r['quantity'] ) : 0;
			}
		}
		$aggregated_by_customer[ $cust_key ] = array(
			'customer' => $cust_data,
			'items'    => $agg,
		);
	}

	// NEW: sort items per customer by product_name (case-insensitive)
	foreach ( $aggregated_by_customer as $ck => $cd ) {
		if ( ! empty( $aggregated_by_customer[ $ck ]['items'] ) ) {
			uasort( $aggregated_by_customer[ $ck ]['items'], function( $a, $b ) {
				return strcasecmp( (string) $a['product_name'], (string) $b['product_name'] );
			} );
		}
	}

	// NEW: sort customers by last_name, then first_name (case-insensitive)
	if ( ! empty( $aggregated_by_customer ) ) {
		uasort( $aggregated_by_customer, function( $a, $b ) {
			$la = isset( $a['customer']['last_name'] ) ? $a['customer']['last_name'] : '';
			$fa = isset( $a['customer']['first_name'] ) ? $a['customer']['first_name'] : '';
			$lb = isset( $b['customer']['last_name'] ) ? $b['customer']['last_name'] : '';
			$fb = isset( $b['customer']['first_name'] ) ? $b['customer']['first_name'] : '';
			$cmp = strcasecmp( trim( $la ), trim( $lb ) );
			if ( 0 === $cmp ) {
				return strcasecmp( trim( $fa ), trim( $fb ) );
			}
			return $cmp;
		} );
	}

	// Render aggregated table: checkbox per customer, customer cell rowspan = count(aggregated items)
	echo '<div class="om-orders-table">';
	echo '<div class="table-responsive">';
	echo '<table class="table table-striped table-bordered">';
	echo '<thead class="table-dark"><tr>';
	echo '<th scope="col"><input type="checkbox" id="om_select_all" aria-label="Alle auswählen" /></th>';
	echo '<th scope="col">Person</th>';
	echo '<th scope="col">E-Mail</th>';
	echo '<th scope="col">Artikel</th>';
	echo '<th scope="col">Kurzbeschreibung</th>';
	echo '<th scope="col">Beschreibung</th>';
	echo '<th scope="col">Optionen</th>';
	echo '<th scope="col">Gesamtmenge</th>';
	echo '</tr></thead>';
	echo '<tbody>';

	foreach ( $aggregated_by_customer as $cust_key => $data ) {
		$c = $data['customer'];
		// NEW: show "Nachname, Vorname" serverseitig and fallback to email/guest
		$last = isset( $c['last_name'] ) ? trim( $c['last_name'] ) : '';
		$first_name = isset( $c['first_name'] ) ? trim( $c['first_name'] ) : '';
		if ( $last && $first_name ) {
			$display_name = $last . ', ' . $first_name;
		} elseif ( $last ) {
			$display_name = $last;
		} elseif ( $first_name ) {
			$display_name = $first_name;
		} else {
			$display_name = $c['email'] ? $c['email'] : __( 'Gast', 'pqw-order-management' );
		}
		$items = $data['items'];
		if ( empty( $items ) ) {
			continue;
		}

		// For each aggregated item, collect option-strings per original order-item
		$per_item_options = array();
		$per_item_counts = array();
		$total_rows_for_customer = 0;
		foreach ( $items as $ikey => $it ) {
			$option_rows = array();
			// iterate original rows for this customer and collect meta per order-item
			if ( isset( $c['rows'] ) && is_array( $c['rows'] ) ) {
				foreach ( $c['rows'] as $rrow ) {
					$r_vid = isset( $rrow['variation_id'] ) ? intval( $rrow['variation_id'] ) : 0;
					$r_pid = isset( $rrow['product_id'] ) ? intval( $rrow['product_id'] ) : 0;
					$r_oid = isset( $rrow['order_id'] ) ? intval( $rrow['order_id'] ) : 0;
					$r_iid = isset( $rrow['order_item_id'] ) ? intval( $rrow['order_item_id'] ) : 0;
					if ( $r_iid <= 0 || $r_oid <= 0 ) {
						continue;
					}
					// match this aggregated item: variant if variation_id >0 else product id
					if ( ( isset( $it['variation_id'] ) && $it['variation_id'] > 0 && $r_vid === intval( $it['variation_id'] ) ) || ( isset( $it['variation_id'] ) && intval( $it['variation_id'] ) == 0 && $r_pid === intval( $it['product_id'] ) ) ) {
						$order = wc_get_order( $r_oid );
						if ( ! $order ) {
							continue;
						}
						$item = $order->get_item( $r_iid );
						if ( ! $item ) {
							continue;
						}
						$meta_data = method_exists( $item, 'get_meta_data' ) ? $item->get_meta_data() : array();
						$meta_parts = array();
						if ( ! empty( $meta_data ) ) {
							foreach ( $meta_data as $md ) {
								if ( empty( $md->key ) ) {
									continue;
								}
								$mkey = $md->key;
								if ( strpos( $mkey, '_' ) === 0 ) {
									continue;
								}
								$mval = $item->get_meta( $mkey );
								if ( $mval === '' || $mval === null ) {
									continue;
								}
								if ( is_array( $mval ) ) {
									$mval = implode( ', ', array_map( 'strval', $mval ) );
								} else {
									$mval = (string) $mval;
								}
								// title from key
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
			// ensure at least one row per aggregated item (empty options show as single blank row)
			if ( empty( $option_rows ) ) {
				$option_rows[] = '';
			}
			$per_item_options[ $ikey ] = $option_rows;
			$per_item_counts[ $ikey ] = count( $option_rows );
			$total_rows_for_customer += $per_item_counts[ $ikey ];
		}

		// now render rows: customer rowspan = $total_rows_for_customer
		$first_customer_row = true;
		foreach ( $items as $ikey => $it ) {
			// NEW: ensure short/full desc exist; fallback to product/variation lookup if empty
			$short = isset( $it['short_desc'] ) ? trim( $it['short_desc'] ) : '';
			$full  = isset( $it['full_desc'] ) ? trim( $it['full_desc'] ) : '';
			$pid = isset( $it['product_id'] ) ? intval( $it['product_id'] ) : 0;
			// Use only parent product for descriptions. DO NOT fall back to variation.
			if ( ( $short === '' || $full === '' ) && $pid > 0 ) {
				$prod = wc_get_product( $pid );
				if ( $prod ) {
					if ( $short === '' && method_exists( $prod, 'get_short_description' ) ) {
						$short = (string) $prod->get_short_description();
					}
					if ( $full === '' && method_exists( $prod, 'get_description' ) ) {
						$full = (string) $prod->get_description();
					}
				}
				// wenn parent keine Beschreibung hat, bleibt das Feld leer
			}

			echo '<tr>';
			// checkbox only on first row of customer
			if ( $first_customer_row ) {
				echo '<td data-label="Select"><input type="checkbox" name="customers[]" value="' . esc_attr( $cust_key ) . '" class="om-customer-checkbox" /></td>';
			} else {
				echo '<td data-label="Select"></td>';
			}

			if ( $first_customer_row ) {
				$person_html = '<div class="pqw-customer-name">' . esc_html( $display_name ) . '</div>';
				$email_val = isset( $c['email'] ) ? $c['email'] : '';
				$email_html = '<div class="pqw-customer-email">' . esc_html( $email_val ) . '</div>';
				echo '<td rowspan="' . esc_attr( $total_rows_for_customer ) . '" data-label="Person">' . $person_html . '</td>';
				echo '<td rowspan="' . esc_attr( $total_rows_for_customer ) . '" data-label="E-Mail">' . $email_html . '</td>';
				$first_customer_row = false;
			}

			// ensure product name + descriptions exist (fallback to variation/product)
			$prod_raw  = isset( $it['product_name'] ) ? trim( $it['product_name'] ) : '';
			$short_raw = isset( $it['short_desc'] ) ? trim( $it['short_desc'] ) : '';
			$full_raw  = isset( $it['full_desc'] ) ? trim( $it['full_desc'] ) : '';
			$pid = isset( $it['product_id'] ) ? intval( $it['product_id'] ) : 0;
			// Only use parent product for name/description fallbacks. NO variation fallback.
			if ( ( $prod_raw === '' || $short_raw === '' || $full_raw === '' ) && $pid > 0 ) {
				$prod_obj = wc_get_product( $pid );
				if ( $prod_obj ) {
					if ( $prod_raw === '' && method_exists( $prod_obj, 'get_name' ) ) $prod_raw = (string) $prod_obj->get_name();
					if ( $short_raw === '' && method_exists( $prod_obj, 'get_short_description' ) ) $short_raw = (string) $prod_obj->get_short_description();
					if ( $full_raw === '' && method_exists( $prod_obj, 'get_description' ) ) $full_raw = (string) $prod_obj->get_description();
				}
				// wenn parent keine Beschreibung hat, bleibt die Variable leer -> nichts anzeigen
			}
			$prod = esc_html( $prod_raw );
			$variant_label = ! empty( $it['variant_label'] ) ? esc_html( $it['variant_label'] ) : '';
			$short = esc_html( wp_trim_words( wp_strip_all_tags( $short_raw ), 20, '…' ) );
			$full  = esc_html( wp_trim_words( wp_strip_all_tags( $full_raw ), 30, '…' ) );

			// NEW: append variant label to product display so variants are visible in the list
			$prod_display = $prod;
			if ( $variant_label !== '' ) {
				$prod_display = $prod_display . ' — ' . $variant_label;
			}

			$article_rowspan = isset( $per_item_counts[ $ikey ] ) && intval( $per_item_counts[ $ikey ] ) > 0 ? intval( $per_item_counts[ $ikey ] ) : 1;
			echo '<td rowspan="' . esc_attr( $article_rowspan ) . '" data-label="Artikel">' . esc_html( $prod_display ) . '</td>';
			echo '<td rowspan="' . esc_attr( $article_rowspan ) . '" data-label="Kurzbeschreibung">' . esc_html( wp_trim_words( wp_strip_all_tags( $short ), 20, '…' ) ) . '</td>';
			echo '<td rowspan="' . esc_attr( $article_rowspan ) . '" data-label="Beschreibung">' . esc_html( wp_trim_words( wp_strip_all_tags( $full ), 30, '…' ) ) . '</td>';

			$option_rows = isset( $per_item_options[ $ikey ] ) && is_array( $per_item_options[ $ikey ] ) ? $per_item_options[ $ikey ] : array( '' );
			$first_opt = true;
			foreach ( $option_rows as $opt_text ) {
				// first option stays in the current row, subsequent options open new rows
				if ( ! $first_opt ) {
					// maintain table column alignment: add empty Select cell before option cell
					echo '<tr><td data-label="Select"></td>';
				}
				echo '<td data-label="Optionen">' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $opt_text ), 20, '…' ) ) . '</td>';
				if ( $first_opt ) {
					echo '<td rowspan="' . esc_attr( $article_rowspan ) . '" data-label="Gesamtmenge">' . intval( $it['quantity'] ) . '</td>';
					echo '</tr>';
					$first_opt = false;
				} else {
					echo '</tr>';
				}
			}
		}
	}

	echo '</tbody>';
	echo '</table>';
	echo '</div>'; // .table-responsive
	echo '</div>'; // .om-orders-table

	echo '</form>';

	// select all JS reused -> erweitert um Export-Funktion
	?>
	<script type="text/javascript">
		(function(){
			var selectAll = document.getElementById('om_select_all');
			if (selectAll) {
				selectAll.addEventListener('change', function(){
					var checkboxes = document.querySelectorAll('input.om-customer-checkbox');
					for (var i=0;i<checkboxes.length;i++){
						checkboxes[i].checked = selectAll.checked;
					}
				});
			}

			// Hilfsfunktion: SheetJS nur bei Bedarf laden
			function loadSheetJS(cb){
				if (window.XLSX) { cb(); return; }
				var s = document.createElement('script');
				// load local copy from plugin assets folder
				s.src = '<?php echo esc_url( plugin_dir_url( __FILE__ ) . "../assets/xlsx.full.min.js" ); ?>';
 				s.onload = cb;
 				document.head.appendChild(s);
 			}

			// Export-Funktion: klont Tabelle, ersetzt Inputs durch Text und erzeugt Download
			function exportTableToXlsx(filename){
				var table = document.querySelector('.om-orders-table table');
				if (!table) { alert('Keine Tabelle gefunden'); return; }
				var clone = table.cloneNode(true);
				// Replace inputs with textual representation
				var inputs = clone.querySelectorAll('input,select,textarea');
				for (var i=0;i<inputs.length;i++){
					var el = inputs[i];
					var text = '';
					if (el.type === 'checkbox' || el.type === 'radio') {
						text = el.checked ? '1' : '';
					} else {
						text = el.value || el.getAttribute('aria-label') || '';
					}
					var txtNode = document.createTextNode(text);
					el.parentNode.replaceChild(txtNode, el);
				}
				// Use SheetJS to convert table -> workbook -> file
				try {
					var wb = XLSX.utils.table_to_book(clone, {sheet: 'Sheet1'});
					XLSX.writeFile(wb, filename);
				} catch ( e ) {
					alert( 'Export fehlgeschlagen' );
				}
			}

			// Klick-Handler für Export-Button
			var expBtn = document.getElementById('pqw_export_xlsx');
			if (expBtn){
				expBtn.addEventListener('click', function(){
					loadSheetJS(function(){
						var d = new Date();
						// use local timestamp with timezone
						function localDateStamp(date){
							var pad = function(n){ return (n<10 ? '0' + n : n); };
							var y = date.getFullYear();
							var m = pad(date.getMonth() + 1);
							var d2 = pad(date.getDate());
							var hh = pad(date.getHours());
							var mm = pad(date.getMinutes());
							var ss = pad(date.getSeconds());
							var tz = -date.getTimezoneOffset();
							var sign = tz >= 0 ? '+' : '-';
							var tzH = pad(Math.floor(Math.abs(tz) / 60));
							var tzM = pad(Math.abs(tz) % 60);
							return y + '-' + m + '-' + d2 + '_' + hh + '-' + mm + '-' + ss + '_' + sign + tzH + tzM;
						}
						var fname = 'pqw-split-name-' + localDateStamp(d) + '.xlsx';
						exportTableToXlsx(fname);
					});
				});
			}

			// Anpassung: formatiere Namensspalte in der Tabelle als "Nachname, Vorname"
			function formatNameColumnToLastCommaFirst(){
				var rows = document.querySelectorAll('.om-orders-table table tbody tr');
				if (!rows || rows.length === 0) return;
				rows.forEach(function(tr){
					var tds = tr.querySelectorAll('td');
					if (!tds || tds.length === 0) return;

					// Versuche zuerst das data-label-Attribut "Name"
					var nameCell = tr.querySelector('td[data-label="Name"]') || tr.querySelector('td[data-label="name"]');
					// Falls nicht vorhanden: wenn erste Zelle Checkbox enthält, ist Name vermutlich zweite Zelle
					if (!nameCell) {
						if (tds[0].querySelector('input[type="checkbox"], input[type="radio"]')) {
							nameCell = tds[1] || tds[0];
						} else {
							// sonst nehme die erste textliche Zelle, die kein Input enthält
							for (var i=0;i<tds.length;i++){
								if (!tds[i].querySelector('input,select,textarea')) { nameCell = tds[i]; break; }
							}
						}
					}
					if (!nameCell) return;

					var txt = (nameCell.textContent || '').trim();
					if (!txt) return;
					// wenn bereits "Nachname, Vorname" (Komma) -> überspringen
					if (txt.indexOf(',') !== -1) return;

					// Zerlege in Teile, letztes Wort als Nachname, Rest als Vorname(s)
					var parts = txt.split(/\s+/);
					if (parts.length < 2) return;
					var last = parts.pop();
					var first = parts.join(' ');
					var newTxt = last + ', ' + first;
					nameCell.textContent = newTxt;
				});
			}

			// Formatierung sofort anwenden und auch kurz bevor Exportiert wird (Export klont die DOM-Tabelle)
			document.addEventListener('DOMContentLoaded', function(){ setTimeout(formatNameColumnToLastCommaFirst, 30); });
			// Falls Export per Button aufgerufen wird, stelle sicher, dass vor dem Klonen formatiert ist
			var origExportTableToXlsx = exportTableToXlsx;
			exportTableToXlsx = function(filename){
				formatNameColumnToLastCommaFirst();
				origExportTableToXlsx(filename);
			};
		})();
	</script>

	<!-- NEW: starte Queue-Verarbeitung beim Laden der Seite, falls noch Einträge vorhanden -->
	<script type="text/javascript">
		(function(){
			function omCreateQueueOverlay(initial){
				if (document.getElementById('pqw_queue_overlay')) return;
				var style = document.createElement('style');
				style.type = 'text/css';
				style.appendChild(document.createTextNode(
					'@keyframes pqw-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
					'#pqw_queue_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
					'#pqw_queue_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
					'.pqw-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:pqw-spin 1s linear infinite;margin-bottom:12px;}'
				));
				document.head.appendChild(style);

				var overlay = document.createElement('div');
				overlay.id = 'pqw_queue_overlay';

				var box = document.createElement('div');
				box.id = 'pqw_queue_box';

				var spinner = document.createElement('div');
				spinner.className = 'pqw-spinner';

				var msg = document.createElement('div');
				msg.id = 'pqw_queue_msg';
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

			function omCheckQueueStatus(cb){
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
				xhr.send('action=om_queue_status');
			}

			function omTriggerQueueProcessing(){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.send('action=pqw_process_queue_async');
			}

			function omStartQueuePolling(){
				(function poll(){
					omCheckQueueStatus(function(res){
						try {
							if (res && res.success && res.data) {
								var pending = parseInt(res.data.pending,10) || 0;
								var msgEl = document.getElementById('pqw_queue_msg');
								if (msgEl) msgEl.textContent = 'Verbleibend: ' + pending;
								if (pending > 0) {
									setTimeout(poll, 1500);
								} else {
									if (msgEl) msgEl.textContent = 'Abarbeitung abgeschlossen.';
									setTimeout(function(){ window.location.reload(); }, 1000);
								}
							} else {
								// auf Fehler warten und erneut versuchen
								setTimeout(poll, 2500);
							}
						} catch(e){
							setTimeout(poll, 2500);
						}
					});
				})();
			}

			// Beim Laden prüfen, ob Queue Einträge hat -> Overlay + Trigger + Polling starten
			document.addEventListener('DOMContentLoaded', function(){
				// nicht doppelt starten, falls bereits Overlay vorhanden (z.B. durch ?pqw_queued)
				if (document.getElementById('pqw_queue_overlay')) return;
				omCheckQueueStatus(function(res){
					if (res && res.success && res.data) {
						var pending = parseInt(res.data.pending,10) || 0;
						if (pending > 0) {
							omCreateQueueOverlay(pending);
							omRemoveQueryParam('om_queued');
							// einmalig Verarbeitung auslösen
							omTriggerQueueProcessing();
							// kleine Verzögerung, damit Server starten kann
							setTimeout(omStartQueuePolling, 700);
						}
					}
				});
			});
		})();
	</script>
	<?php

	// After the form output add centered overlay + spinner and polling JS when pqw_queued is present:
	if ( isset( $_GET['om_queued'] ) && intval( $_GET['om_queued'] ) > 0 ) :
		$queued = intval( $_GET['om_queued'] );
		?>
		<script type="text/javascript">
		(function(){
			// inject spinner keyframes + minimal styles
			var style = document.createElement('style');
			style.type = 'text/css';
			style.appendChild(document.createTextNode(
				'@keyframes pqw-spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}' +
				'#pqw_queue_overlay{position:fixed;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:99999;background:rgba(0,0,0,0.45);}' +
				'#pqw_queue_box{background:#fff;padding:20px 26px;border-radius:8px;display:flex;flex-direction:column;align-items:center;min-width:260px;box-shadow:0 8px 30px rgba(0,0,0,0.25);}' +
				'.pqw-spinner{width:48px;height:48px;border:4px solid #e6e6e6;border-top-color:#007cba;border-radius:50%;animation:pqw-spin 1s linear infinite;margin-bottom:12px;}'
			));
			document.head.appendChild(style);

			// create overlay element
			var overlay = document.createElement('div');
			overlay.id = 'pqw_queue_overlay';

			var box = document.createElement('div');
			box.id = 'pqw_queue_box';

			var spinner = document.createElement('div');
			spinner.className = 'pqw-spinner';

			var msg = document.createElement('div');
			msg.id = 'pqw_queue_msg';
			msg.style.textAlign = 'center';
			msg.style.fontSize = '14px';
			msg.style.color = '#222';
			msg.textContent = 'Verarbeite ' + <?php echo $queued; ?> + ' Einträge...';

			box.appendChild(spinner);
			box.appendChild(msg);
			overlay.appendChild(box);
			document.body.appendChild(overlay);

			// remove pqw_queued from URL so reload doesn't retrigger the overlay
			try {
				var u = new URL(window.location.href);
				u.searchParams.delete('om_queued');
				history.replaceState && history.replaceState(null, '', u.toString());
			} catch (e) { /* ignore */ }

			// poll status
			function checkStatus(){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.onload = function(){
					try {
						var res = JSON.parse(xhr.responseText);
						if (res && res.success && res.data) {
							var pending = parseInt(res.data.pending,10);
							var msgEl = document.getElementById('pqw_queue_msg');
							msgEl.textContent = 'Verbleibend: ' + pending;
							if (pending > 0) {
								setTimeout(checkStatus, 1500);
							} else {
								msgEl.textContent = 'Abarbeitung abgeschlossen.';
								// nach kurzer Verzögerung die Seite neu laden (ohne pqw_queued)
								setTimeout(function(){
									window.location.reload();
								}, 1000);
							}
						}
					} catch(e){}
				};
				xhr.send('action=om_queue_status');
			}
			// start polling shortly
			setTimeout(checkStatus, 800);
		})();
		</script>
	<?php
	endif;

	echo '</div>';
}
