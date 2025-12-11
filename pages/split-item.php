<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bestellungen weiterverarbeiten - Artikel
 *
 * Rendert eine Artikel-basierte Ansicht (je unique Produkt eine Zeile).
 * Checkbox per Produkt: name="items[]" value="{product_id}"
 */
function pqw_page_split_item() {
	global $pqw_order_management;

	$mode = 'split_item';
	$button_label = __( 'Bestellungen weiterverarbeiten (Artikel)', 'pqw-order-management' );
	$nonce_action  = 'pqw_action_' . $mode;

	// Handle POST
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pqw_subpage'] ) && $_POST['pqw_subpage'] === $mode ) {
		if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) ) {
			wp_die( __( 'Nicht autorisiert', 'pqw-order-management' ) );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( __( 'Nonce ungültig', 'pqw-order-management' ) );
		}

		$items = isset( $_POST['items'] ) ? (array) wp_unslash( $_POST['items'] ) : array();
		$items = array_filter( array_unique( array_map( 'strval', $items ) ) );
		if ( empty( $items ) ) {
			$redirect = add_query_arg( array( 'page' => PQW_Order_Management::PLUGIN_SLUG . '_' . $mode, 'pqw_updated' => 0 ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// parse items into map: order_id => array(order_item_id,...)
		$by_order = array();
		$customers = $pqw_order_management->get_processing_customers();

		// Items may be keys like 'v123' (variant 123) or 'p123' (product 123)
		foreach ( $customers as $cust_data ) {
			foreach ( $cust_data['rows'] as $r ) {
				$order_id = isset( $r['order_id'] ) ? intval( $r['order_id'] ) : 0;
				$item_id  = isset( $r['order_item_id'] ) ? intval( $r['order_item_id'] ) : 0;
				$prod_id  = isset( $r['product_id'] ) ? intval( $r['product_id'] ) : 0;
				$var_id   = isset( $r['variation_id'] ) ? intval( $r['variation_id'] ) : 0;

				if ( ! $order_id || ! $item_id ) {
					continue;
				}

				$match = false;
				// prefer explicit variant key if present
				if ( $var_id > 0 && in_array( 'v' . $var_id, $items, true ) ) {
					$match = true;
				}
				// product key 'p{id}'
				elseif ( in_array( 'p' . $prod_id, $items, true ) ) {
					$match = true;
				}
				// backward-compatibility: numeric product ids (old behavior)
				elseif ( in_array( (string) $prod_id, $items, true ) ) {
					$match = true;
				}

				if ( $match ) {
					if ( ! isset( $by_order[ $order_id ] ) ) {
						$by_order[ $order_id ] = array();
					}
					$by_order[ $order_id ][] = $item_id;
				}
			}
		}

		if ( empty( $by_order ) ) {
			$redirect = add_query_arg( array( 'page' => PQW_Order_Management::PLUGIN_SLUG . '_' . $mode, 'pqw_updated' => 0 ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// ALWAYS queue: convert to simple rows and push
		$rows = array();
		foreach ( $by_order as $oid => $iids ) {
			foreach ( $iids as $iid ) {
				$rows[] = array( 'order_id' => $oid, 'order_item_id' => $iid );
			}
		}
		$inserted = $pqw_order_management->queue_rows( $rows );
		$redirect = add_query_arg( array( 'page' => PQW_Order_Management::PLUGIN_SLUG . '_' . $mode, 'pqw_queued' => $inserted ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	// Render page header
	echo '<div class="wrap">';
	echo '<h1>' . esc_html( $button_label ) . '</h1>';

	// Notices
	if ( isset( $_GET['pqw_updated'] ) ) {
		$cnt = absint( $_GET['pqw_updated'] );
		if ( $cnt > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Artikel erfolgreich aufgesplittet.', 'pqw-order-management' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Keine Artikel verarbeitet oder Fehler.', 'pqw-order-management' ) . '</p></div>';
		}
	}
	if ( isset( $_GET['pqw_queued'] ) ) {
		$cnt = absint( $_GET['pqw_queued'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d Queue-Einträge angelegt.', 'pqw-order-management' ), $cnt ) ) . '</p></div>';
	}

	// Check WooCommerce
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-warning"><p><strong>WooCommerce nicht aktiv.</strong> PQW Order-Management benötigt WooCommerce.</p></div>';
		echo '</div>';
		return;
	}

	// Fetch data (customers -> rows) and aggregate by product_id
	$customers = $pqw_order_management->get_processing_customers();
	$aggregated = array(); // aggregated keyed: variant => combined, non-variants => unique entry per order-item
	foreach ( $customers as $cust_data ) {
		foreach ( $cust_data['rows'] as $r ) {
			$pid = isset( $r['product_id'] ) ? intval( $r['product_id'] ) : 0;
			$vid = isset( $r['variation_id'] ) ? intval( $r['variation_id'] ) : 0;
			if ( ! $pid ) {
				continue;
			}

			// Wenn Variante vorhanden => nach Variation zusammenfassen (gleiches Variation-ID = gleiche Variante)
			if ( $vid > 0 ) {
				$key = 'v' . $vid; // variant-key: aggregiert über alle Bestellungen
				if ( ! isset( $aggregated[ $key ] ) ) {
					// Always store parent product id and use parent product for name/descriptions.
					$parent_obj = $pid > 0 ? wc_get_product( $pid ) : null;
					$parent_name  = $parent_obj && method_exists( $parent_obj, 'get_name' ) ? (string) $parent_obj->get_name() : ( isset( $r['product_name'] ) ? $r['product_name'] : '' );
					$parent_short = $parent_obj && method_exists( $parent_obj, 'get_short_description' ) ? (string) $parent_obj->get_short_description() : ( isset( $r['short_desc'] ) ? $r['short_desc'] : '' );
					$parent_full  = $parent_obj && method_exists( $parent_obj, 'get_description' ) ? (string) $parent_obj->get_description() : ( isset( $r['full_desc'] ) ? $r['full_desc'] : '' );
					$aggregated[ $key ] = array(
						// store parent product id (ARTICLE), not the variation id
						'product_id'    => $pid,
						'product_name'  => $parent_name,
						'variation_id'  => $vid,
						'variant_label' => isset( $r['variant_label'] ) ? $r['variant_label'] : '',
						'short_desc'    => $parent_short,
						'full_desc'     => $parent_full,
						'quantity'      => 0,
						'is_variant'    => true,
					);
} else {
	// also ensure existing entry has a name (covers earlier rows without name)
	// If product_name empty, try parent product only (NO variant fallback)
	if ( empty( $aggregated[ $key ]['product_name'] ) && $pid > 0 ) {
		$parent_obj = wc_get_product( $pid );
		if ( $parent_obj && method_exists( $parent_obj, 'get_name' ) ) {
			$aggregated[ $key ]['product_name'] = (string) $parent_obj->get_name();
		}
	}
}
 				$aggregated[ $key ]['quantity'] += isset( $r['quantity'] ) ? intval( $r['quantity'] ) : 0;
 			} else {
				// Keine Variante -> nach Produkt zusammenfassen (p{pid})
				$key = 'p' . $pid;
				if ( ! isset( $aggregated[ $key ] ) ) {
					// use parent product for name/descriptions (no variant fallback)
					$parent_obj  = $pid > 0 ? wc_get_product( $pid ) : null;
					$parent_name = $parent_obj && method_exists( $parent_obj, 'get_name' ) ? (string) $parent_obj->get_name() : ( isset( $r['product_name'] ) ? $r['product_name'] : '' );
					$parent_short = $parent_obj && method_exists( $parent_obj, 'get_short_description' ) ? (string) $parent_obj->get_short_description() : ( isset( $r['short_desc'] ) ? $r['short_desc'] : '' );
					$parent_full  = $parent_obj && method_exists( $parent_obj, 'get_description' ) ? (string) $parent_obj->get_description() : ( isset( $r['full_desc'] ) ? $r['full_desc'] : '' );
					$aggregated[ $key ] = array(
						'product_id'    => $pid,
						'product_name'  => $parent_name,
						'variation_id'  => 0,
						'variant_label' => '',
						'short_desc'    => $parent_short,
						'full_desc'     => $parent_full,
						'quantity'      => 0,
						'is_variant'    => false,
					);
				}
				// Menge beim Aggregat erhöhen (wie bei Varianten)
				$aggregated[ $key ]['quantity'] += isset( $r['quantity'] ) ? intval( $r['quantity'] ) : 0;
 			}
		}
	}

	// NEW: sort aggregated items by product_name (case-insensitive) for alphabetical display
	if ( ! empty( $aggregated ) ) {
		uasort( $aggregated, function( $a, $b ) {
			return strcasecmp( (string) $a['product_name'], (string) $b['product_name'] );
		} );
	}

	if ( empty( $aggregated ) ) {
		echo '<p>Keine "in Bearbeitung" Bestellungen / Artikel gefunden.</p>';
		echo '</div>';
		return;
	}

	// Form
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . PQW_Order_Management::PLUGIN_SLUG . '_' . $mode ) ) . '">';
	wp_nonce_field( $nonce_action );
	echo '<input type="hidden" name="pqw_subpage" value="' . esc_attr( $mode ) . '" />';

	echo '<p>';
	echo '<button type="submit" class="button button-primary" style="margin-right:10px;">' . esc_html( $button_label ) . '</button>';
	echo '<span class="descr//iption">' . esc_html__( 'Markierte Artikel werden aus ihren Bestellungen ausgegliedert und in neue Bestellungen verschoben (pro Original-Bestellung).', 'pqw-order-management' ) . '</span>';
	echo '</p>';

	// Table header (aggregated view) — no order column
	echo '<div class="pqw-orders-table"><div class="table-responsive">';
	echo '<table class="table table-striped table-bordered">';
	echo '<thead class="table-dark"><tr>';
	echo '<th scope="col"><input type="checkbox" id="pqw_select_all_items" aria-label="Alle auswählen" /></th>';
	echo '<th scope="col">Artikel</th>';
	echo '<th scope="col">Beschreibung</th>';
	echo '<th scope="col">Kurzbeschreibung</th>';
	echo '<th scope="col">Optionen</th>';
	echo '<th scope="col">Gesamtmenge</th>';
	echo '</tr></thead><tbody>';

	foreach ( $aggregated as $key => $agg ) {
		// Produktname + Beschreibungen: verwende vorhandene Werte, sonst lade Produkt/Variation nach
		$prod_raw   = isset( $agg['product_name'] ) ? trim( $agg['product_name'] ) : '';
		$short_raw  = isset( $agg['short_desc'] ) ? trim( $agg['short_desc'] ) : '';
		$full_raw   = isset( $agg['full_desc'] ) ? trim( $agg['full_desc'] ) : '';
		$vid = isset( $agg['variation_id'] ) ? intval( $agg['variation_id'] ) : 0;
		$pid = isset( $agg['product_id'] ) ? intval( $agg['product_id'] ) : 0;
		if ( $prod_raw === '' || $short_raw === '' || $full_raw === '' ) {
			// Use ONLY parent product (pid) for name/description. NO fallback to variation.
			$prod_obj = null;
			if ( $pid > 0 ) {
				$prod_obj = wc_get_product( $pid );
			}
			if ( $prod_obj ) {
				if ( $prod_raw === '' && method_exists( $prod_obj, 'get_name' ) ) {
					$prod_raw = (string) $prod_obj->get_name();
				}
				if ( $short_raw === '' && method_exists( $prod_obj, 'get_short_description' ) ) {
					$short_raw = (string) $prod_obj->get_short_description();
				}
				if ( $full_raw === '' && method_exists( $prod_obj, 'get_description' ) ) {
					$full_raw = (string) $prod_obj->get_description();
				}
			}
			// wenn parent keine Beschreibung hat, bleibt das Feld leer (kein Variant-Fallback)
 		}
		$prod          = esc_html( $prod_raw );
		$variant_label = ! empty( $agg['variant_label'] ) ? esc_html( $agg['variant_label'] ) : '';
		$short         = esc_html( wp_trim_words( wp_strip_all_tags( $short_raw ), 20, '…' ) );
		$full          = esc_html( wp_trim_words( wp_strip_all_tags( $full_raw ), 30, '…' ) );
		$qty   = intval( $agg['quantity'] );
		$labelVal = esc_attr( $key ); // 'v{vid}' or 'p{pid}'

		// NEW: append variant label to product display so variants are clearly visible
		$prod_display = $prod;
		if ( $variant_label !== '' ) {
			$prod_display = $prod_display . ' — ' . $variant_label;
		}

		// Collect option strings per original order-item so each original item can be printed on its own option-line
		$option_rows = array();
		if ( ! empty( $customers ) && is_array( $customers ) ) {
			foreach ( $customers as $cust_data ) {
				if ( empty( $cust_data['rows'] ) || ! is_array( $cust_data['rows'] ) ) {
					continue;
				}
				foreach ( $cust_data['rows'] as $rrow ) {
					$r_vid = isset( $rrow['variation_id'] ) ? intval( $rrow['variation_id'] ) : 0;
					$r_pid = isset( $rrow['product_id'] ) ? intval( $rrow['product_id'] ) : 0;
					$r_oid = isset( $rrow['order_id'] ) ? intval( $rrow['order_id'] ) : 0;
					$r_iid = isset( $rrow['order_item_id'] ) ? intval( $rrow['order_item_id'] ) : 0;
					if ( $r_iid <= 0 || $r_oid <= 0 ) {
						continue;
					}
					if ( ( $vid > 0 && $r_vid === $vid ) || ( $vid == 0 && $r_pid === $pid ) ) {
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
								$title = $mkey;
								if ( false !== strpos( $mkey, '_' ) ) {
									$parts_k = explode( '_', $mkey, 2 );
									if ( isset( $parts_k[1] ) && $parts_k[1] !== '' ) {
										$title = $parts_k[1];
									}
								}
								$title = str_replace( array( '-', '_' ), ' ', $title );
								$title = trim( $title );
								$title = ucwords( $title );
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
		}
		if ( empty( $option_rows ) ) {
			$option_rows[] = '';
		}

		$article_rowspan = count( $option_rows ) ? count( $option_rows ) : 1;
		echo '<tr>';
		echo '<td data-label="Auswählen"><input type="checkbox" name="items[]" value="' . $labelVal . '" class="pqw-item-checkbox" /></td>';
		echo '<td rowspan="' . esc_attr( $article_rowspan ) . '" data-label="Artikel">' . $prod_display . '</td>';
		echo '<td rowspan="' . esc_attr( $article_rowspan ) . '" data-label="Beschreibung">' . $full . '</td>';
		echo '<td rowspan="' . esc_attr( $article_rowspan ) . '" data-label="Kurzbeschreibung">' . $short . '</td>';

		$first_opt = true;
		foreach ( $option_rows as $opt_text ) {
			if ( ! $first_opt ) {
				echo '<tr><td data-label="Auswählen"></td>';
			}
			echo '<td data-label="optionen">' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $opt_text ), 12, '…' ) ) . '</td>';
			if ( $first_opt ) {
				echo '<td rowspan="' . esc_attr( $article_rowspan ) . '" data-label="Gesamtmenge">' . $qty . '</td>';
				echo '</tr>';
				$first_opt = false;
			} else {
				echo '</tr>';
			}
		}
	}

	echo '</tbody></table></div></div>';

	echo '</form>';

	// JS: select all items + Export
	?>
	<script type="text/javascript">
		(function(){
			var selectAll = document.getElementById('pqw_select_all_items');
			if (selectAll) {
				selectAll.addEventListener('change', function(){
					var checkboxes = document.querySelectorAll('input.pqw-item-checkbox');
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

			function exportTableToXlsx(filename){
				var table = document.querySelector('.pqw-orders-table table');
				if (!table) { alert('Keine Tabelle gefunden'); return; }
				var clone = table.cloneNode(true);
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
				try {
					var wb = XLSX.utils.table_to_book(clone, {sheet: 'Sheet1'});
					XLSX.writeFile(wb, filename);
				} catch (e){
					alert('Export fehlgeschlagen');
				}
			}

			var expBtn = document.getElementById('pqw_export_xlsx');
			if (expBtn){
				expBtn.addEventListener('click', function(){
					loadSheetJS(function(){
						var d = new Date();
						function localDateStamp(date){
							var pad = function(n){ return (n<10 ? '0' + n : n); };
							var y = date.getFullYear();
							var m = pad(date.getMonth() + 1);
							var day = pad(date.getDate());
							var hh = pad(date.getHours());
							var mm = pad(date.getMinutes());
							var ss = pad(date.getSeconds());
							var tz = -date.getTimezoneOffset();
							var sign = tz >= 0 ? '+' : '-';
							var tzH = pad(Math.floor(Math.abs(tz) / 60));
							var tzM = pad(Math.abs(tz) % 60);
							return y + '-' + m + '-' + day + '_' + hh + '-' + mm + '-' + ss + '_' + sign + tzH + tzM;
						}
						var fname = 'pqw-split-item-' + localDateStamp(d) + '.xlsx';
						exportTableToXlsx(fname);
					});
				});
			}
		})();
	</script>

<!-- NEW: starte Queue-Verarbeitung beim Laden der Seite, falls noch Einträge vorhanden -->
	<script type="text/javascript">
		(function(){
			function pqwCreateQueueOverlay(initial){
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

			function pqwRemoveQueryParam(param){
				try {
					var u = new URL(window.location.href);
					u.searchParams.delete(param);
					history.replaceState && history.replaceState(null, '', u.toString());
				} catch (e) { /* ignore */ }
			}

			function pqwCheckQueueStatus(cb){
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
				xhr.send('action=pqw_queue_status');
			}

			function pqwTriggerQueueProcessing(){
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.send('action=pqw_process_queue_async');
			}

			function pqwStartQueuePolling(){
				(function poll(){
					pqwCheckQueueStatus(function(res){
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
				pqwCheckQueueStatus(function(res){
					if (res && res.success && res.data) {
						var pending = parseInt(res.data.pending,10) || 0;
						if (pending > 0) {
							pqwCreateQueueOverlay(pending);
							pqwRemoveQueryParam('pqw_queued');
							// einmalig Verarbeitung auslösen
							pqwTriggerQueueProcessing();
							// kleine Verzögerung, damit Server starten kann
							setTimeout(pqwStartQueuePolling, 700);
						}
					}
				});
			});
		})();
	</script>
	<?php


	// After the form output add centered overlay + spinner and polling JS when pqw_queued is present:
	if ( isset( $_GET['pqw_queued'] ) && intval( $_GET['pqw_queued'] ) > 0 ) :
		$queued = intval( $_GET['pqw_queued'] );
		?>
		<script type="text/javascript">
		(function(){
			// inject spinner keyframes + minimal styles (same look as other pages)
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
				u.searchParams.delete('pqw_queued');
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
				xhr.send('action=pqw_queue_status');
			}
			// start polling shortly
			setTimeout(checkStatus, 800);
		})();
		</script>
	<?php
	endif;

	echo '</div>';
}
