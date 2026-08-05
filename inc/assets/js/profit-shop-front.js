/**
 * 分潤賣場前台互動層（vanilla JS，無 build step）
 *
 * 取代 Phase 10-12 的 React/antd bundle（`js/src/profit-shop-front/`，已整個刪除）。
 *
 * 架構前提：**畫面全部由 PHP 印出**（商品卡片 grid + 每商品一個 `<dialog class="pc-modal">`），
 * 本檔只負責五件互動：
 *
 *   1. 點卡片 → `dialog.showModal()`
 *   2. Gallery 縮圖切換主圖
 *   3. 商品描述過長時的「展開 / 收合全部內容」
 *   4. 數量 `-` / `+`
 *   5. 變體屬性選擇 → 更新 variation_id / attribute_* hidden input / 價格 / 庫存 /
 *      購買人數 / 加購按鈕 disabled
 *
 * 為什麼不再用 wp_localize_script 的全域物件：一個頁面可以放**多個** shortcode 賣場
 * （`[power_shop_profit_shop id="..."]`），全域單一物件會互相覆蓋。改為把每個賣場的資料
 * 印在自己的容器裡（`<script type="application/json" class="ps-variations-data">`），
 * 查找一律限定在最近的 `.ps-profit-shop-front` 祖先內，天然支援一頁多賣場，
 * 也讓「獨立頁面」與「shortcode」兩條路徑的 DOM 與行為完全一致。
 *
 * ⚠ DOM 契約（改動下列任一項都會讓互動靜默失效，且不會有錯誤訊息）：
 *   - `.ps-profit-shop-front[data-ps-shop]`  賣場容器（多賣場的查找邊界）
 *   - `[data-ps-product-id]`                 商品卡片
 *   - `[data-ps-modal-for]`                  對應商品的 `<dialog>`
 *   - `[data-ps-form]`                       Modal 內的加購表單（AJAX 攔截點）
 *   - `[data-ps-submit-spinner]`             加購按鈕內的 loading 圈
 *   - `[data-ps-submit-label]`               加購按鈕文字
 *   - `[data-ps-submit-error]` / `[data-ps-submit-error-label]`  Modal 內就地錯誤列
 *   - `[data-ps-toast]` / `[data-ps-toast-body]` / `[data-ps-toast-label]`  成功通知
 *   - `[data-ps-countdown][data-ps-countdown-target]`  倒數計時容器與目標毫秒數
 *   - `[data-ps-cd-unit="days|hours|minutes|seconds"]` / `[data-ps-cd-plain="…"]`  倒數的數字格
 *   - `[data-ps-*]`                          Modal 內各區塊的更新目標（見下方常數）
 *
 * ⚠ 加入購物車：PHP 直接印出 WooCommerce 原生表單（`<form method="post" data-ps-form>`），
 * 本檔在能力足夠時攔截它、改用 **WooCommerce 原生的 `?wc-ajax=add_to_cart`** 送出，
 * 讓使用者不換頁就完成加購（loading 圈 + 成功 toast）。
 *
 * 三條不可動搖的紀律：
 *
 *   1. **一定要送 `application/x-www-form-urlencoded`**。`AddToCartHook`（priority 5）
 *      只從 `$_GET` / `$_POST` 讀 `profit_shop_id`；改送 JSON body 會讓 PHP 完全不填
 *      `$_POST` → 三道閘門讀不到 shop_id → **靜默走商品原價、分潤失效且無任何錯誤訊息**。
 *   2. **variable 商品送的 `product_id` 是「選中變體自己的 ID」**。`WC_AJAX::add_to_cart()`
 *      偵測到傳入的是 variation 時，會自己換算成父商品 ID 並從變體物件反查
 *      `attribute_*`（class-wc-ajax.php），因此這條通道**不送也不需要** `attribute_*`。
 *      Modal 內既有的 `variation_id` / `attribute_*` hidden input 一律保留不動——
 *      它們是 JS 停用與降級路徑所必需。
 *   3. **任何失敗都不得自動重送表單**。`wc_add_notice()` 沒有去重，重送會讓同一則錯誤
 *      顯示兩次；更糟的是「伺服器已加購成功但回應異常」時重送會造成**重複加購**
 *      （顧客被重複收費）。詳見 `handleResponse()` 的三個分支。
 *
 * JS 停用 / `<dialog>` 不支援 / 能力不足 / payload 組不出來時，一律退回 PHP 印出的
 * 原生表單送出（漸進增強），因此不需要任何 `<noscript>` 樣式或第二顆按鈕。
 */

( function () {
	'use strict';

	/** 內容超過這個高度（px）才需要「展開全部內容」 */
	var READ_MORE_THRESHOLD = 299;

	/** 賣場容器（多賣場查找邊界） */
	var SHOP_SELECTOR = '.ps-profit-shop-front';

	/** 商品卡片 */
	var CARD_SELECTOR = '[data-ps-product-id]';

	/** i18n 文案（由 PHP wp_localize_script 注入；缺值時退回繁中預設，不讓畫面出現空字串） */
	var I18N = ( window.power_shop_front_i18n && typeof window.power_shop_front_i18n === 'object' )
		? window.power_shop_front_i18n
		: {};

	/** 站台層級設定（由 PHP `ProfitShopFrontView::js_config()` 注入） */
	var CONFIG = ( window.power_shop_front_config && typeof window.power_shop_front_config === 'object' )
		? window.power_shop_front_config
		: {};

	/** 成功 toast 停留毫秒數 */
	var TOAST_DURATION = 4000;

	/**
	 * 可以走 AJAX 加購的商品型別白名單
	 *
	 * 只放「一個 product_id + quantity 就能直接加購」的型別。grouped（要選子商品數量）、
	 * external（導向站外）、variable-subscription（Modal 目前不印變體選擇 UI）走 AJAX
	 * 必然失敗，直接讓它們維持原生表單送出，不浪費一次 round trip、也不會多一則錯誤。
	 */
	var AJAX_TYPES = { simple: true, variable: true, subscription: true };

	/**
	 * 執行環境是否具備 AJAX 加購所需的能力
	 *
	 * 任一為假就完全不註冊 submit 監聽器，表單維持 PHP 印出的原生行為。
	 * `CONFIG.addToCartUrl` 為空字串涵蓋「WooCommerce 未啟用 / `WC_AJAX` 不存在」。
	 *
	 * @return {boolean} 是否可用
	 */
	function canAjax() {
		return !! ( CONFIG.addToCartUrl && window.fetch && window.URLSearchParams && window.Promise );
	}

	/**
	 * 取 i18n 文案
	 *
	 * @param {string} key      文案鍵
	 * @param {string} fallback 找不到時的預設值
	 * @return {string} 文案
	 */
	function t( key, fallback ) {
		return ( typeof I18N[ key ] === 'string' && I18N[ key ] !== '' ) ? I18N[ key ] : fallback;
	}

	/**
	 * 安全 decode：非法百分號序列時原樣回傳，不 throw
	 *
	 * WooCommerce 的變體屬性值可能是 URL-encoded（中文屬性尤其常見）。
	 *
	 * @param {string} str 原始字串
	 * @return {string} decode 後的字串
	 */
	function safeDecode( str ) {
		try {
			return decodeURIComponent( str );
		} catch ( e ) {
			return str;
		}
	}

	/**
	 * 組出帶貨幣符號的價格字串
	 *
	 * 刻意與 PHP 端的 `$ps_currency` 完全一致（前綴符號 + 空格 + 原始數字、不加千分位），
	 * 卡片與 Modal 才不會出現「NT$ 8,000 / NT$ 8000」兩種寫法。
	 *
	 * @param {string|number|null} price 價格
	 * @return {string} 例：`NT$ 1200`
	 */
	function currency( price ) {
		if ( price === null || price === undefined || price === '' ) {
			return '';
		}

		return t( 'currencySymbol', 'NT$' ) + ' ' + String( price );
	}

	/**
	 * 讀取 dialog 內的變體資料
	 *
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @return {Array<Object>} 變體陣列；無資料或 JSON 損毀時回空陣列
	 */
	function readVariations( modal ) {
		var node = modal.querySelector( 'script.ps-variations-data' );
		if ( ! node ) {
			return [];
		}

		try {
			var parsed = JSON.parse( node.textContent || '[]' );
			return Array.isArray( parsed ) ? parsed : [];
		} catch ( e ) {
			return [];
		}
	}

	/* ------------------------------------------------------------------ *
	 * Gallery：縮圖切換主圖
	 * ------------------------------------------------------------------ */

	/**
	 * 綁定圖片畫廊
	 *
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @return {void}
	 */
	function bindGallery( modal ) {
		var main = modal.querySelector( '[data-ps-gallery-main]' );
		var thumbs = modal.querySelectorAll( '[data-ps-gallery-thumb]' );
		if ( ! main || thumbs.length === 0 ) {
			return;
		}

		/**
		 * 切換主圖並同步縮圖選取樣式
		 *
		 * @param {string} src 圖片網址
		 * @return {void}
		 */
		function select( src ) {
			if ( ! src ) {
				return;
			}
			main.setAttribute( 'src', src );

			Array.prototype.forEach.call( thumbs, function ( thumb ) {
				var img = thumb.querySelector( 'img' );
				var isActive = thumb.getAttribute( 'data-ps-gallery-thumb' ) === src;
				if ( img ) {
					img.classList.toggle( 'border-2', isActive );
					img.classList.toggle( 'border-primary', isActive );
					img.classList.toggle( 'border-solid', isActive );
				}
			} );
		}

		// 供變體切換時從外部呼叫（選了變體要換成該變體的圖）。
		modal.psSelectImage = select;

		Array.prototype.forEach.call( thumbs, function ( thumb ) {
			var src = thumb.getAttribute( 'data-ps-gallery-thumb' );

			thumb.addEventListener( 'click', function () {
				select( src );
			} );

			// `<div role="button">` 需自行補鍵盤操作（刻意不用原生 <button>：本專案
			// Tailwind preflight 關閉，佈景主題對 button 的 padding / min-height 會蓋進來，
			// 把 grid-cols-4 的 aspect-square 正方形排列撐歪）。
			thumb.addEventListener( 'keydown', function ( event ) {
				if ( event.key !== 'Enter' && event.key !== ' ' ) {
					return;
				}
				event.preventDefault();
				select( src );
			} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * ToggleContent：商品描述展開 / 收合
	 * ------------------------------------------------------------------ */

	/**
	 * 綁定描述展開 / 收合
	 *
	 * 內容高度超過門檻時才顯示右側直排把手與底部漸層遮罩。
	 *
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @return {void}
	 */
	function bindToggleContent( modal ) {
		var wrap = modal.querySelector( '[data-ps-content]' );
		var inner = modal.querySelector( '[data-ps-content-inner]' );
		var fade = modal.querySelector( '[data-ps-content-fade]' );
		var handle = modal.querySelector( '[data-ps-content-handle]' );
		if ( ! wrap || ! inner ) {
			return;
		}

		var isExpand = false;

		/**
		 * 套用展開 / 收合狀態
		 *
		 * @return {void}
		 */
		function apply() {
			inner.classList.toggle( 'max-h-[300px]', ! isExpand );
			inner.classList.toggle( 'h-full', isExpand );

			if ( fade ) {
				fade.classList.toggle( 'tw-hidden', isExpand );
			}
			if ( handle ) {
				handle.setAttribute( 'data-ps-expanded', isExpand ? '1' : '0' );
				var label = handle.querySelector( '[data-ps-handle-label]' );
				var iconExpand = handle.querySelector( '[data-ps-icon-expand]' );
				var iconShrink = handle.querySelector( '[data-ps-icon-shrink]' );
				if ( label ) {
					label.textContent = isExpand
						? t( 'collapseAll', '收合全部內容' )
						: t( 'expandAll', '展開全部內容' );
				}
				if ( iconExpand ) {
					iconExpand.classList.toggle( 'tw-hidden', isExpand );
				}
				if ( iconShrink ) {
					iconShrink.classList.toggle( 'tw-hidden', ! isExpand );
				}
			}
		}

		/**
		 * 量測內容高度，決定是否顯示展開機制
		 *
		 * 只能在 dialog 已開啟後量測——`<dialog>` 未開啟時 `display:none`，
		 * `clientHeight` 一律為 0，會誤判成「內容很短、不需要展開」。
		 *
		 * @return {void}
		 */
		function measure() {
			isExpand = false;
			apply();

			var tooLong = inner.scrollHeight > READ_MORE_THRESHOLD;
			if ( fade ) {
				fade.classList.toggle( 'tw-hidden', ! tooLong );
			}
			if ( handle ) {
				handle.classList.toggle( 'tw-hidden', ! tooLong );
			}
		}

		modal.psMeasureContent = measure;

		/**
		 * 切換展開狀態
		 *
		 * @param {boolean} next 目標狀態
		 * @return {void}
		 */
		function setExpand( next ) {
			isExpand = next;
			apply();
		}

		if ( fade ) {
			fade.addEventListener( 'click', function () {
				setExpand( true );
			} );
			fade.addEventListener( 'keydown', function ( event ) {
				if ( event.key !== 'Enter' && event.key !== ' ' ) {
					return;
				}
				event.preventDefault();
				setExpand( true );
			} );
		}

		if ( handle ) {
			handle.addEventListener( 'click', function () {
				setExpand( ! isExpand );
			} );
			handle.addEventListener( 'keydown', function ( event ) {
				if ( event.key !== 'Enter' && event.key !== ' ' ) {
					return;
				}
				event.preventDefault();
				setExpand( ! isExpand );
			} );
		}
	}

	/* ------------------------------------------------------------------ *
	 * 數量選擇器
	 * ------------------------------------------------------------------ */

	/**
	 * 綁定數量 `-` / `+`
	 *
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @return {void}
	 */
	function bindQuantity( modal ) {
		var input = modal.querySelector( '[data-ps-qty]' );
		var minus = modal.querySelector( '[data-ps-qty-minus]' );
		var plus = modal.querySelector( '[data-ps-qty-plus]' );
		if ( ! input ) {
			return;
		}

		/**
		 * 讀取目前上限（`max` 為空字串代表無上限）
		 *
		 * @return {number} 上限值
		 */
		function maxOf() {
			var raw = input.getAttribute( 'max' );
			if ( raw === null || raw === '' ) {
				return Infinity;
			}
			var parsed = parseInt( raw, 10 );
			return isNaN( parsed ) ? Infinity : parsed;
		}

		/**
		 * 夾住數量到 [1, max] 區間
		 *
		 * @return {void}
		 */
		function clamp() {
			var value = parseInt( input.value, 10 );
			if ( isNaN( value ) || value < 1 ) {
				value = 1;
			}
			var max = maxOf();
			if ( value > max ) {
				value = max;
			}
			input.value = String( value );
		}

		modal.psClampQuantity = clamp;

		if ( minus ) {
			minus.addEventListener( 'click', function () {
				input.value = String( Math.max( 1, ( parseInt( input.value, 10 ) || 1 ) - 1 ) );
				clamp();
			} );
		}

		if ( plus ) {
			plus.addEventListener( 'click', function () {
				input.value = String( ( parseInt( input.value, 10 ) || 1 ) + 1 );
				clamp();
			} );
		}

		input.addEventListener( 'change', clamp );
	}

	/* ------------------------------------------------------------------ *
	 * 變體選擇
	 * ------------------------------------------------------------------ */

	/**
	 * 依庫存資訊組出文案與圖示類型
	 *
	 * 判斷順序與舊版 `StockInfo` 完全一致：instock 時再看 manageStock / stockQuantity。
	 *
	 * @param {Object|null} stock 庫存物件 `{manageStock, stockQuantity, stockStatus}`
	 * @return {{tone: string, text: string}} tone = success | error | warning
	 */
	function stockPresentation( stock ) {
		var info = stock || { manageStock: false, stockQuantity: null, stockStatus: 'instock' };

		switch ( info.stockStatus ) {
			case 'outofstock':
				return { tone: 'error', text: t( 'soldOut', '此商品已售完' ) };

			case 'onbackorder':
				return { tone: 'warning', text: t( 'backorder', '此商品為延期交貨商品' ) };

			case 'instock':
				if ( ! info.manageStock ) {
					return { tone: 'success', text: t( 'inStock', '此商品還有庫存' ) };
				}
				if ( info.stockQuantity ) {
					var unit = t( 'unitSuffix', '件' );
					if ( info.stockQuantity <= 10 ) {
						return {
							tone: 'success',
							text: t( 'lastFew', '此商品只剩最後' ) + ' ' + info.stockQuantity + ' ' + unit,
						};
					}
					return {
						tone: 'success',
						text: t( 'remaining', '此商品剩餘' ) + ' ' + info.stockQuantity + ' ' + unit,
					};
				}
				return { tone: 'error', text: t( 'soldOut', '此商品已售完' ) };

			default:
				return { tone: 'success', text: t( 'inStock', '此商品還有庫存' ) };
		}
	}

	/**
	 * 依 tone 切換 `[data-ps-icon]` 的顯示（PHP 已印出三顆 SVG，這裡只切 tw-hidden）
	 *
	 * @param {HTMLElement} host 圖示容器
	 * @param {string}      tone success | error | warning
	 * @return {void}
	 */
	function applyIconTone( host, tone ) {
		if ( ! host ) {
			return;
		}
		[ 'success', 'error', 'warning' ].forEach( function ( name ) {
			var icon = host.querySelector( '[data-ps-icon="' + name + '"]' );
			if ( icon ) {
				icon.classList.toggle( 'tw-hidden', name !== tone );
			}
		} );
	}

	/**
	 * 取得可購買的庫存上限
	 *
	 * 判斷順序對齊後端 `WC_Product::get_max_purchase_quantity()`：
	 * 「單獨銷售」優先於一切；允許延期交貨時視為無上限。
	 *
	 * @param {HTMLElement} modal     `<dialog>` 元素
	 * @param {Object|null} variation 已選變體（null = 未選 / 非變體商品）
	 * @return {number} 上限；無上限時為 Infinity
	 */
	function resolveMaxQty( modal, variation ) {
		if ( modal.getAttribute( 'data-ps-sold-individually' ) === '1' ) {
			return 1;
		}

		if ( modal.getAttribute( 'data-ps-backorders' ) !== 'no' ) {
			return Infinity;
		}

		var stock = variation
			? variation.stock
			: JSON.parse( modal.getAttribute( 'data-ps-stock' ) || 'null' );

		if ( ! stock || stock.stockQuantity === null || stock.stockQuantity === undefined ) {
			return Infinity;
		}

		return stock.stockQuantity;
	}

	/**
	 * 綁定變體屬性選擇
	 *
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @return {void}
	 */
	function bindVariations( modal ) {
		var isVariable = modal.getAttribute( 'data-ps-type' ) === 'variable';
		var submit = modal.querySelector( '[data-ps-submit]' );

		/**
		 * 更新加購按鈕的可用性
		 *
		 * 賣場非 published（尚未開賣 / 已結束）一律不可加購——這個判斷 PHP 端已經先做過
		 * （按鈕印出來就是 disabled），此處只在變體切換時維持狀態，不會把它解鎖。
		 *
		 * @param {boolean} enabled 是否可加購
		 * @return {void}
		 */
		function setSubmitEnabled( enabled ) {
			if ( ! submit || modal.getAttribute( 'data-ps-shop-open' ) !== '1' ) {
				return;
			}

			// ⚠ AJAX 飛行中（或已凍結）一律不得解鎖。使用者在請求還沒回來時改選另一個
			// 變體會觸發 refresh() → 走到這裡；少了這道守門就會把按鈕解鎖，讓他能再按
			// 一次 → 兩筆都成功 → **重複加購、顧客被重複收費**，而且兩次回應都正常，
			// 畫面上不會有任何異常。
			if ( modal.psSubmitting ) {
				return;
			}

			submit.disabled = ! enabled;
		}

		if ( ! isVariable ) {
			return;
		}

		var variations = readVariations( modal );
		var attrFields = modal.querySelector( '[data-ps-attr-fields]' );
		var variationIdInput = modal.querySelector( '[data-ps-variation-id]' );
		var statusHost = modal.querySelector( '[data-ps-attr-status]' );
		var priceHost = modal.querySelector( '[data-ps-price]' );
		var stockHost = modal.querySelector( '[data-ps-stock]' );
		var buyerHost = modal.querySelector( '[data-ps-buyer]' );
		var qtyInput = modal.querySelector( '[data-ps-qty]' );
		var options = modal.querySelectorAll( '[data-ps-attr-option]' );

		/** 使用者已選的屬性：{ attributeName: value } */
		var selected = {};

		/**
		 * 依已選屬性比對出對應變體
		 *
		 * 兩邊都做 `decodeURIComponent` + `toLowerCase`——WooCommerce 的 `attributes`
		 * 鍵是 `attribute_pa_color` 形式，值可能 URL-encoded。
		 *
		 * @return {Object|null} 命中的變體；未選齊或無對應時回 null
		 */
		function matchVariation() {
			for ( var i = 0; i < variations.length; i++ ) {
				var candidate = variations[ i ];
				var attributes = candidate.attributes || {};
				var keys = Object.keys( attributes );
				var matched = true;

				for ( var j = 0; j < keys.length; j++ ) {
					var key = keys[ j ];
					var bare = safeDecode( key.toLowerCase() ).replace( /^attribute_/, '' );
					var picked = null;

					Object.keys( selected ).forEach( function ( name ) { // eslint-disable-line no-loop-func
						if ( safeDecode( name.toLowerCase() ) === bare ) {
							picked = selected[ name ];
						}
					} );

					if ( picked === null || picked !== attributes[ key ] ) {
						matched = false;
						break;
					}
				}

				if ( matched && keys.length > 0 ) {
					return candidate;
				}
			}

			return null;
		}

		/**
		 * 重建要送給 WooCommerce 的 `attribute_*` hidden input
		 *
		 * ⚠ 鍵一律取自**選中變體自己的 `attributes`**（WC `get_available_variations()` 回的
		 * 原始鍵，形如 `attribute_pa_color`），不是把使用者選的屬性名自行加 `attribute_` 前綴
		 * 組出來。自訂（非 taxonomy）屬性的名稱經 WC `sanitize_title()` 後可能與原始名稱不同
		 * （中文屬性會變成 percent-encoded），自行組鍵會在那些商品上安靜地對不上、被 WC 判為
		 * 「缺少屬性」。變體某個屬性值為空字串（"任意" 變體）時才退回使用者實際選的值。
		 *
		 * @param {Object|null} variation 已選變體
		 * @return {void}
		 */
		function syncAttributeFields( variation ) {
			if ( ! attrFields ) {
				return;
			}

			attrFields.innerHTML = '';
			if ( ! variation ) {
				return;
			}

			var attributes = variation.attributes || {};
			Object.keys( attributes ).forEach( function ( key ) {
				var value = attributes[ key ];
				if ( value === '' ) {
					var bare = safeDecode( key.toLowerCase() ).replace( /^attribute_/, '' );
					Object.keys( selected ).forEach( function ( name ) {
						if ( safeDecode( name.toLowerCase() ) === bare ) {
							value = selected[ name ];
						}
					} );
				}

				var input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = key;
				input.value = value;
				attrFields.appendChild( input );
			} );
		}

		/**
		 * 依已選變體重繪價格 / 庫存 / 購買人數 / 數量上限 / 按鈕狀態
		 *
		 * @return {void}
		 */
		function refresh() {
			var variation = matchVariation();

			// 供 bindAddToCart 組 AJAX payload 用（沿用檔內 modal.psXxx 的跨函式通訊慣例）。
			// wc-ajax 通道送的 product_id 就是這個變體的 id，不是父商品 id。
			modal.psCurrentVariation = variation;

			if ( variationIdInput ) {
				variationIdInput.value = variation ? String( variation.id ) : '';
			}
			syncAttributeFields( variation );

			// 已選 / 未選提示
			if ( statusHost ) {
				var okIcon = statusHost.querySelector( '[data-ps-icon="success"]' );
				var noIcon = statusHost.querySelector( '[data-ps-icon="error"]' );
				var statusLabel = statusHost.querySelector( '[data-ps-attr-status-label]' );
				if ( okIcon ) {
					okIcon.classList.toggle( 'tw-hidden', ! variation );
				}
				if ( noIcon ) {
					noIcon.classList.toggle( 'tw-hidden', !! variation );
				}
				if ( statusLabel ) {
					statusLabel.textContent = variation
						? t( 'attrSelected', '已選擇商品屬性' )
						: t( 'attrUnselected', '未選擇商品屬性' );
				}
			}

			// 價格：有特價 → 上排劃線原價、下排紅字特價；無特價 → 單行原價
			if ( priceHost ) {
				var regular = variation ? variation.regularPrice : '';
				var sale = variation ? variation.salesPrice : '';
				if ( ! variation ) {
					priceHost.innerHTML = '';
				} else if ( sale ) {
					priceHost.innerHTML =
						'<p class="mb-0"><del></del></p><p class="mb-0 text-error"></p>';
					priceHost.querySelector( 'del' ).textContent = currency( regular );
					priceHost.querySelector( '.text-error' ).textContent = currency( sale );
				} else {
					priceHost.innerHTML = '<p class="mb-0"></p>';
					priceHost.querySelector( 'p' ).textContent = currency( regular );
				}
			}

			// 庫存：**永遠可見**。未選變體時顯示商品層庫存（與舊版 StockInfo 的
			// `if (!selectedVariationId) stock = product.stock` 完全一致），選了才換成變體庫存。
			// 早期版本在未選變體時把整段藏起來，使用者一開 Modal 會看不到任何庫存資訊。
			if ( stockHost ) {
				var stockSource = variation ? variation.stock : null;
				if ( ! stockSource ) {
					try {
						stockSource = JSON.parse( modal.getAttribute( 'data-ps-stock' ) || 'null' );
					} catch ( e ) {
						stockSource = null;
					}
				}

				var presentation = stockPresentation( stockSource );
				var stockLabel = stockHost.querySelector( '[data-ps-stock-label]' );
				if ( stockLabel ) {
					stockLabel.textContent = presentation.text;
				}
				applyIconTone( stockHost, presentation.tone );
			}

			// 購買人數：variable 商品只有選定變體後才顯示（沿用舊版行為）
			if ( buyerHost ) {
				var buyerLabel = buyerHost.querySelector( '[data-ps-buyer-label]' );
				if ( variation && buyerLabel ) {
					var base = parseInt( modal.getAttribute( 'data-ps-total-sales' ) || '0', 10 ) || 0;
					var extra = parseInt( variation.extraBuyerCount || 0, 10 ) || 0;
					buyerLabel.textContent = t( 'buyerPrefix', '已有' ) + ' ' + ( base + extra ) + ' ' +
						t( 'buyerSuffix', '人購買' );
				}
				buyerHost.classList.toggle( 'tw-hidden', ! variation );
			}

			// 圖片：變體有自己的圖時切過去
			if ( variation && variation.image && modal.psSelectImage ) {
				modal.psSelectImage( variation.image );
			}

			// 數量上限
			if ( qtyInput ) {
				var max = resolveMaxQty( modal, variation );
				if ( max === Infinity ) {
					qtyInput.removeAttribute( 'max' );
				} else {
					qtyInput.setAttribute( 'max', String( max ) );
				}
				if ( modal.psClampQuantity ) {
					modal.psClampQuantity();
				}
			}

			var inStock = !! variation && ( ! variation.stock || variation.stock.stockStatus !== 'outofstock' );
			setSubmitEnabled( inStock );
		}

		// 解除忙碌後補跑用：飛行期間使用者可能改過變體，那次 refresh() 被 psSubmitting
		// 擋住沒有更新按鈕狀態，解除後要再跑一次才會與目前選取一致。
		modal.psRefreshVariations = refresh;

		modal.psResetVariations = function () {
			selected = {};
			Array.prototype.forEach.call( options, function ( option ) {
				option.classList.remove( 'pc-btn-primary' );
				option.setAttribute( 'aria-pressed', 'false' );
			} );
			refresh();
		};

		Array.prototype.forEach.call( options, function ( option ) {
			option.addEventListener( 'click', function () {
				var name = option.getAttribute( 'data-ps-attr-name' );
				var value = option.getAttribute( 'data-ps-attr-value' );
				selected[ name ] = value;

				// 同一組屬性內互斥
				Array.prototype.forEach.call( options, function ( sibling ) {
					if ( sibling.getAttribute( 'data-ps-attr-name' ) !== name ) {
						return;
					}
					var isSelf = sibling === option;
					sibling.classList.toggle( 'pc-btn-primary', isSelf );
					sibling.setAttribute( 'aria-pressed', isSelf ? 'true' : 'false' );
				} );

				refresh();
			} );
		} );

		refresh();
	}

	/* ------------------------------------------------------------------ *
	 * AJAX 加入購物車
	 * ------------------------------------------------------------------ */

	/**
	 * 變體是否含「任意」屬性（值為空字串）
	 *
	 * 這種變體走 wc-ajax 一定失敗：`WC_AJAX::add_to_cart()` 是從變體物件反查
	 * `get_variation_attributes()`，「任意」屬性回空字串，而 `WC_Cart::add_to_cart()`
	 * 會把空值濾掉後判定「缺少必填屬性」而拒絕。原生表單路徑不受影響（表單送的是
	 * 使用者實際選的值），因此這種商品直接降級走原生送出。
	 *
	 * @param {Object|null} variation 變體物件
	 * @return {boolean} 是否含「任意」屬性
	 */
	function hasAnyAttribute( variation ) {
		var attributes = ( variation && variation.attributes ) || {};
		var keys = Object.keys( attributes );

		for ( var i = 0; i < keys.length; i++ ) {
			if ( attributes[ keys[ i ] ] === '' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 組出 `?wc-ajax=add_to_cart` 的 POST body
	 *
	 * 只送三個欄位——`WC_AJAX::add_to_cart()` 全文也只讀 `product_id` 與 `quantity`
	 * 兩個（外加我們自己要給 `AddToCartHook` 的 `profit_shop_id`）。刻意**不送**
	 * `add-to-cart` / `variation_id` / `attribute_*`：那三個是原生表單處理器
	 * （`WC_Form_Handler`）的契約，wc-ajax 完全不看。
	 *
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @param {HTMLElement} form  加購表單
	 * @return {URLSearchParams|null} payload；回 null 代表「交給原生表單送出」
	 */
	function buildAddToCartPayload( modal, form ) {
		var type = modal.getAttribute( 'data-ps-type' ) || '';
		if ( ! AJAX_TYPES[ type ] ) {
			return null;
		}

		var shopIdInput = form.querySelector( 'input[name="profit_shop_id"]' );
		var parentInput = form.querySelector( 'input[name="add-to-cart"]' );
		var qtyInput = form.querySelector( '[data-ps-qty]' );

		// 少了 profit_shop_id 就整個不要走 AJAX——寧可換頁，也不要送出一筆沒有分潤
		// 標記的加購（那會靜默走商品原價，站長要到對帳時才會發現）。
		if ( ! shopIdInput || ! shopIdInput.value || ! parentInput || ! parentInput.value ) {
			return null;
		}

		var productId = parentInput.value;

		if ( type === 'variable' ) {
			var variation = modal.psCurrentVariation;
			if ( ! variation || ! variation.id || hasAnyAttribute( variation ) ) {
				return null;
			}

			// ⚠ 送變體 ID，不是父商品 ID。WC 偵測到傳入的是 variation 時會自己換算回
			// 父商品並反查 attribute_*，換算**之後**才觸發 woocommerce_add_cart_item_data，
			// 所以 AddToCartHook 第三道閘門拿到的仍是父商品 ID，與賣場商品清單對得上。
			productId = String( variation.id );
		}

		var params = new window.URLSearchParams();
		params.append( 'product_id', productId );
		params.append( 'quantity', ( qtyInput && qtyInput.value ) ? qtyInput.value : '1' );
		params.append( 'profit_shop_id', shopIdInput.value );

		return params;
	}

	/**
	 * 切換加購按鈕的忙碌狀態
	 *
	 * `psWasDisabled` 記錄／還原是必要的，不可簡化成 `submit.disabled = false`：
	 * simple 商品的 disabled 狀態 JS 從來不碰（`bindVariations` 在非 variable 時就 return），
	 * 直接寫 false 會把「本來就該停用」的按鈕解鎖。
	 *
	 * 數量輸入框**刻意不一併 disabled**——disabled 的 input 不會被表單送出，
	 * 會破壞降級時的原生表單路徑。
	 *
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @param {boolean}     busy  是否忙碌中
	 * @return {void}
	 */
	function setSubmitBusy( modal, busy ) {
		var submit = modal.querySelector( '[data-ps-submit]' );
		var spinner = modal.querySelector( '[data-ps-submit-spinner]' );

		modal.psSubmitting = !! busy;

		if ( submit ) {
			if ( busy ) {
				submit.psWasDisabled = submit.disabled;
				submit.disabled = true;
				submit.setAttribute( 'aria-busy', 'true' );
			} else {
				submit.disabled = !! submit.psWasDisabled;
				submit.removeAttribute( 'aria-busy' );
			}
		}

		if ( spinner ) {
			spinner.classList.toggle( 'tw-hidden', ! busy );
		}

		// psSubmitting 已在上面設回 false，此時 refresh() 才不會被自己的守門擋住。
		// 飛行期間使用者可能改過變體，那次 refresh() 沒能更新按鈕狀態，這裡補跑一次。
		if ( ! busy && typeof modal.psRefreshVariations === 'function' ) {
			modal.psRefreshVariations();
		}
	}

	/**
	 * 凍結加購按鈕（狀態未知時用）
	 *
	 * 只收掉 spinner，**保持 disabled 且維持 `psSubmitting = true`**，讓使用者無法
	 * 盲目重按。用在「伺服器已收到請求、但我們無法確認結果」的情況——此時商品很可能
	 * 已經進購物車，慫恿重試就是慫恿重複付款。
	 *
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @return {void}
	 */
	function freezeSubmit( modal ) {
		var spinner = modal.querySelector( '[data-ps-submit-spinner]' );
		if ( spinner ) {
			spinner.classList.add( 'tw-hidden' );
		}
	}

	/**
	 * 在 Modal 內就地顯示錯誤
	 *
	 * 刻意不用外面的 toast：失敗時 Modal 仍開著，而原生 `<dialog>` 開啟時進入
	 * top layer，body 層的 toast 不論 z-index 多大都在它之下，根本看不到。
	 *
	 * @param {HTMLElement} modal   `<dialog>` 元素
	 * @param {string}      message 訊息
	 * @return {void}
	 */
	function showSubmitError( modal, message ) {
		var box = modal.querySelector( '[data-ps-submit-error]' );
		if ( ! box ) {
			return;
		}

		var label = box.querySelector( '[data-ps-submit-error-label]' );
		if ( label ) {
			label.textContent = message;
		}
		box.classList.remove( 'tw-hidden' );
	}

	/**
	 * 收掉 Modal 內的錯誤列
	 *
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @return {void}
	 */
	function clearSubmitError( modal ) {
		var box = modal.querySelector( '[data-ps-submit-error]' );
		if ( box ) {
			box.classList.add( 'tw-hidden' );
		}
	}

	/**
	 * 顯示賣場層級的浮出通知
	 *
	 * toast 屬於賣場容器（一頁多個 shortcode 賣場時各有各的），因此第一個參數是 shop
	 * 而不是 modal。圖示切換複用 `applyIconTone()`，與 Modal 內庫存 / 屬性狀態同一套。
	 *
	 * @param {HTMLElement} shop    `.ps-profit-shop-front` 容器
	 * @param {string}      message 訊息
	 * @param {string}      tone    success | error | warning
	 * @return {void}
	 */
	function showToast( shop, message, tone ) {
		var toast = shop.querySelector( '[data-ps-toast]' );
		if ( ! toast ) {
			return;
		}

		var label = toast.querySelector( '[data-ps-toast-label]' );
		if ( label ) {
			label.textContent = message;
		}
		applyIconTone( toast.querySelector( '[data-ps-toast-body]' ), tone );
		toast.classList.remove( 'tw-hidden' );

		// 連續加購時先清掉上一個 timer，否則最後一則會被前一個提早關掉。
		if ( shop.psToastTimer ) {
			window.clearTimeout( shop.psToastTimer );
		}
		shop.psToastTimer = window.setTimeout( function () {
			toast.classList.add( 'tw-hidden' );
			shop.psToastTimer = null;
		}, TOAST_DURATION );
	}

	/**
	 * 套用 WooCommerce 回傳的 fragments
	 *
	 * `fragments` 是 `{ CSS 選擇器: 完整 outerHTML }`。賣場前台本身沒有 mini-cart，
	 * 多半 0 命中——這是正常的，佈景主題在 header 放了購物車 widget 時才會用到。
	 *
	 * @param {Object|undefined} fragments 片段
	 * @return {void}
	 */
	function applyFragments( fragments ) {
		if ( ! fragments || typeof fragments !== 'object' ) {
			return;
		}

		Object.keys( fragments ).forEach( function ( selector ) {
			var nodes;
			try {
				// 鍵是 jQuery 選擇器，理論上可能不是合法的 CSS 選擇器。
				nodes = document.querySelectorAll( selector );
			} catch ( e ) {
				return;
			}

			Array.prototype.forEach.call( nodes, function ( node ) {
				node.outerHTML = fragments[ selector ];
			} );
		} );
	}

	/**
	 * 通知站台其他元件購物車已更新
	 *
	 * ⚠ 有 jQuery 時**只發事件、不自己套 fragments**：WooCommerce 自己的
	 * `add-to-cart.js` 綁在 `added_to_cart` 上並會套用 fragments，我們再套一次
	 * 只會多一次閃爍。
	 *
	 * ⚠ **刻意不傳第三個參數（按鈕元素）**：傳了之後 WooCommerce 的 `updateButton()`
	 * 會在我們的表單裡插入一條佈景主題樣式的「檢視購物車」連結，與 toast 內的按鈕
	 * 重複，而且每加購一次就永久多一條。WooCommerce 對「不傳按鈕」有明文守門
	 * （add-to-cart.js 內註解說明有些外掛不傳按鈕），省略不會出錯。
	 *
	 * @param {Object} data wc-ajax 回應
	 * @return {void}
	 */
	function notifyCartUpdated( data ) {
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'added_to_cart', [ data.fragments, data.cart_hash ] );
			return;
		}

		applyFragments( data.fragments );
	}

	/**
	 * 處理 wc-ajax 回應
	 *
	 * 三個分支的分類刻意做得很細，因為**分錯類會讓顧客被重複收費**：
	 *
	 *   1. 狀態未知（HTTP 非 2xx / 不是合法 JSON）→ 凍結按鈕，提示重新整理。
	 *      `WC_AJAX::add_to_cart()` 是先 `add_to_cart()` 成功、才輸出 fragments，
	 *      而它開頭 `ob_start()` 之後全程沒有 clean，任何第三方外掛在這個 request
	 *      內 echo 出來的東西都會混進回應讓 JSON 解析失敗——**此時商品其實已經在
	 *      購物車裡了**。顯示「請再試一次」並解鎖按鈕，等於請使用者重複加購。
	 *   2. 業務失敗（`{error:true}`）→ 依 WooCommerce 官方前端契約導向 `product_url`。
	 *      **絕不可改成重送原生表單**：`wc_add_notice()` 沒有去重，同一則錯誤會被寫
	 *      兩次、在畫面上重複顯示；而在邊界情況（第一次其實成功了）更會變成重複加購。
	 *   3. 成功 → 先做我們自己可控的事（關 Modal、toast），最後才呼叫可能執行到
	 *      第三方程式碼的 fragments / `added_to_cart`。
	 *
	 * @param {HTMLElement} shop  賣場容器
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @param {Object}      raw   `{ ok: boolean, text: string }`
	 * @return {void}
	 */
	function handleResponse( shop, modal, raw ) {
		var data = null;

		if ( raw.ok ) {
			try {
				data = JSON.parse( raw.text );
			} catch ( e ) {
				data = null;
			}
		}

		if ( ! raw.ok || ! data || typeof data !== 'object' ) {
			freezeSubmit( modal );
			showSubmitError( modal, t( 'addToCartUnknown', '無法確認加購結果，請重新整理頁面並查看購物車' ) );
			return;
		}

		if ( data.error ) {
			setSubmitBusy( modal, false );

			if ( data.product_url ) {
				window.location.href = data.product_url;
				return;
			}

			showSubmitError( modal, t( 'addToCartFailed', '加入購物車失敗，請稍後再試' ) );
			return;
		}

		setSubmitBusy( modal, false );

		// 站台選項 `woocommerce_cart_redirect_after_add = yes` 時 WooCommerce 已經寫了
		// 一則 session notice；停在原頁的話那則通知會在使用者下次載入任何頁面時遲到
		// 跳出來，因此這裡與原生行為一致直接導向購物車。
		if ( CONFIG.redirectAfterAdd && CONFIG.cartUrl ) {
			window.location.href = CONFIG.cartUrl;
			return;
		}

		// 先關 Modal 再顯示 toast：`<dialog>` 開啟時在 top layer，body 層的 toast
		// 不論 z-index 多大都會被它與 ::backdrop 蓋住。
		if ( typeof modal.close === 'function' ) {
			modal.close();
		}
		showToast( shop, t( 'addToCartSuccess', '已加入購物車' ), 'success' );

		notifyCartUpdated( data );
	}

	/**
	 * 綁定 Modal 內的 AJAX 加購
	 *
	 * 監聽 `form` 的 submit 而不是按鈕的 click，有兩個理由：涵蓋「在數量欄按 Enter」，
	 * 以及「不呼叫 preventDefault 就自動降級為原生送出」這個模式只在 submit 事件上成立。
	 *
	 * @param {HTMLElement} shop  賣場容器
	 * @param {HTMLElement} modal `<dialog>` 元素
	 * @return {void}
	 */
	function bindAddToCart( shop, modal ) {
		var form = modal.querySelector( '[data-ps-form]' );
		if ( ! form || ! canAjax() ) {
			return;
		}

		modal.psSubmitting = false;

		form.addEventListener( 'submit', function ( event ) {
			// 飛行中一律吞掉。少了這道守門，使用者在請求還沒回來時改選變體會讓按鈕
			// 被 refresh() 解鎖 → 再按一次 → 兩筆都成功 → 重複加購。
			if ( modal.psSubmitting ) {
				event.preventDefault();
				return;
			}

			var payload = buildAddToCartPayload( modal, form );
			if ( ! payload ) {
				// 組不出 payload（型別不支援 / 未選變體 /「任意」屬性變體 / 缺欄位）→
				// 不 preventDefault，交給瀏覽器執行 PHP 印出的原生表單送出。
				return;
			}

			event.preventDefault();
			clearSubmitError( modal );
			setSubmitBusy( modal, true );

			window.fetch( CONFIG.addToCartUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
					'X-Requested-With': 'XMLHttpRequest'
				},
				body: payload.toString()
			} ).then( function ( response ) {
				// 先取 text 再自行 JSON.parse，才能區分「HTTP 失敗 / 回應不是 JSON」與
				// 「fetch 根本沒送出去」——直接用 response.json() 會把兩者併進同一個
				// catch，而這兩種情況該給的處置完全相反。
				return response.text().then( function ( text ) {
					return { ok: response.ok, text: text };
				} );
			} ).then( function ( raw ) {
				try {
					handleResponse( shop, modal, raw );
				} catch ( e ) {
					// handleResponse 內的副作用會呼叫到第三方程式碼（佈景主題綁在
					// added_to_cart 上的 handler 等）。那些拋錯**不代表加購失敗**，
					// 絕不能讓它落進下面的 .catch 被誤報成連線問題。
					if ( window.console && window.console.error ) {
						window.console.error( e );
					}
				}
			} ).catch( function () {
				// 只有 fetch 本身 reject（請求沒送出去 / 連線中斷）才會到這裡。
				setSubmitBusy( modal, false );
				showSubmitError( modal, t( 'addToCartNetwork', '連線發生問題，請確認購物車內容後再試一次' ) );
			} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * 倒數計時（尚未開賣 / 已結束）
	 * ------------------------------------------------------------------ */

	/** daisyUI countdown 的 `--value` 上限；超過只會捲出字串範圍、顯示空白 */
	var COUNTDOWN_MAX_VALUE = 99;

	/** 倒數計時的四個單位（順序須與 PHP `ProfitShopFrontView::countdown()` 一致） */
	var COUNTDOWN_UNITS = [ 'days', 'hours', 'minutes', 'seconds' ];

	/**
	 * 更新單一時間單位的顯示
	 *
	 * daisyUI 的捲動數字只在 0–99 有效，超過就改用純文字那一格（PHP 已把兩者都印好，
	 * 這裡只切換 `tw-hidden`）。**不要**改用 `tw-inline` 之類的 display utility 來做
	 * 切換——它與 `tw-hidden` 同權重但排在後面，會讓該藏的永遠藏不住（Pitfall #27）。
	 *
	 * @param {HTMLElement} root  倒數容器
	 * @param {string}      key   單位鍵
	 * @param {number}      value 數值
	 * @return {void}
	 */
	function renderCountdownUnit( root, key, value ) {
		var unit = root.querySelector( '[data-ps-cd-unit="' + key + '"]' );
		var plain = root.querySelector( '[data-ps-cd-plain="' + key + '"]' );
		if ( ! unit || ! plain ) {
			return;
		}

		var overflow = value > COUNTDOWN_MAX_VALUE;

		unit.classList.toggle( 'tw-hidden', overflow );
		plain.classList.toggle( 'tw-hidden', ! overflow );

		// 數字由 CSS ::before 產生，螢幕閱讀器讀不到，靠 aria-label 補。
		unit.setAttribute( 'aria-label', String( value ) );
		plain.textContent = String( value );

		var digit = unit.firstElementChild;
		if ( digit ) {
			digit.style.setProperty( '--value', String( Math.min( value, COUNTDOWN_MAX_VALUE ) ) );
		}
	}

	/**
	 * 綁定賣場內的倒數計時
	 *
	 * PHP 已用**伺服器時間**印好首屏數字（避免瀏覽器時鐘偏移造成「後台已開賣、
	 * 前台仍在倒數」），這裡只負責之後每秒遞減。JS 停用時畫面是一個靜止但正確的快照。
	 *
	 * ⚠ 歸零後重新載入頁面的守門：**只有初始 remaining > 0 才排程 reload**。
	 * 少了這個條件，「伺服器判定 coming、但剩餘時間已經是 0」的臨界秒數會變成
	 * 載入 → 立刻歸零 → reload → 又載入的迴圈。有了它，最壞情況是使用者多等一秒。
	 *
	 * @param {HTMLElement} shop `.ps-profit-shop-front` 容器
	 * @return {void}
	 */
	function bindCountdown( shop ) {
		var root = shop.querySelector( '[data-ps-countdown]' );
		if ( ! root ) {
			return;
		}

		var target = parseInt( root.getAttribute( 'data-ps-countdown-target' ), 10 );
		if ( ! isFinite( target ) || target <= 0 ) {
			// 已結束的賣場（target = 0）是刻意的全零盤面，不需要每秒重繪。
			return;
		}

		var startedPositive = ( target - Date.now() ) > 0;
		var reloaded = false;

		function tick() {
			var remaining = Math.max( 0, Math.floor( ( target - Date.now() ) / 1000 ) );

			renderCountdownUnit( root, 'days', Math.floor( remaining / 86400 ) );
			renderCountdownUnit( root, 'hours', Math.floor( ( remaining % 86400 ) / 3600 ) );
			renderCountdownUnit( root, 'minutes', Math.floor( ( remaining % 3600 ) / 60 ) );
			renderCountdownUnit( root, 'seconds', remaining % 60 );

			if ( remaining > 0 ) {
				return;
			}

			window.clearInterval( timer );

			// 倒數結束＝賣場應該開賣了，重新載入讓伺服器重新判定並印出商品。
			if ( startedPositive && ! reloaded ) {
				reloaded = true;
				window.setTimeout( function () {
					window.location.reload();
				}, 1000 );
			}
		}

		var timer = window.setInterval( tick, 1000 );
		tick();
	}

	/* ------------------------------------------------------------------ *
	 * 初始化
	 * ------------------------------------------------------------------ */

	/**
	 * 初始化單一賣場容器
	 *
	 * @param {HTMLElement} shop `.ps-profit-shop-front` 容器
	 * @return {void}
	 */
	function initShop( shop ) {
		bindCountdown( shop );

		var modals = shop.querySelectorAll( '[data-ps-modal-for]' );
		Array.prototype.forEach.call( modals, function ( modal ) {
			bindGallery( modal );
			bindToggleContent( modal );
			bindQuantity( modal );
			bindVariations( modal );
			bindAddToCart( shop, modal );
		} );

		var cards = shop.querySelectorAll( CARD_SELECTOR );
		Array.prototype.forEach.call( cards, function ( card ) {
			if ( card.getAttribute( 'data-ps-disabled' ) === '1' ) {
				return;
			}

			var productId = card.getAttribute( 'data-ps-product-id' );
			// 查找限定在同一個賣場容器內，一頁多個 shortcode 賣場才不會互相開錯 Modal。
			var modal = shop.querySelector( '[data-ps-modal-for="' + productId + '"]' );
			if ( ! modal || typeof modal.showModal !== 'function' ) {
				// `<dialog>` 不被支援時什麼都不做——卡片內印出的 form / 連結本身就是
				// 完整可用的加購路徑（漸進增強），不攔截才是正確的降級行為。
				return;
			}

			// 卡片的動作元素在無 JS 時是「加入購物車（直接送出）」或「前往選購」；
			// JS 接手後它變成「開啟商品 Modal」，文案要跟著換。
			var actionLabel = card.querySelector( '[data-ps-js-label]' );
			if ( actionLabel ) {
				var jsLabel = actionLabel.getAttribute( 'data-ps-js-label' );
				var labelSpan = actionLabel.querySelector( 'span' ) || actionLabel;
				if ( jsLabel ) {
					labelSpan.textContent = jsLabel;
				}
			}

			card.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();

				if ( modal.psResetVariations ) {
					modal.psResetVariations();
				}

				var qty = modal.querySelector( '[data-ps-qty]' );
				if ( qty ) {
					qty.value = '1';
				}

				// 上一次的錯誤列不要殘留。已凍結（狀態未知）時刻意保留，
				// 否則使用者關掉再開就會看到一顆停用但沒有任何說明的按鈕。
				if ( ! modal.psSubmitting ) {
					clearSubmitError( modal );
				}

				modal.showModal();

				// 描述高度只能在 dialog 開啟後量測：未開啟時 display:none，clientHeight 恆為 0。
				if ( modal.psMeasureContent ) {
					modal.psMeasureContent();
				}
			} );
		} );
	}

	/** 掃描頁面上所有賣場容器並初始化 */
	function init() {
		var shops = document.querySelectorAll( SHOP_SELECTOR );
		Array.prototype.forEach.call( shops, initShop );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
