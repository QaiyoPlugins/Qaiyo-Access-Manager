<?php
/**
 * Qaiyo brand menu — wraps the Qaiyo plugin group with a Crocoblock-style chip
 * separator on TOP and a thin line separator on the BOTTOM, in the WP admin sidebar.
 *
 * Robust positioning strategy:
 * - Each Qaiyo plugin calls Wpam_Brand_Menu::register_plugin_slug( $top_level_menu_slug )
 *   when it registers its menu. The slug is stored in $GLOBALS so it survives
 *   class-prefix differences across plugins (Qt_/Qpdf_/Wpam_ all share one list).
 * - At admin_menu priority 999 (after every plugin/theme has registered its menus)
 *   we scan the live $menu global, find the actual positions of every registered
 *   Qaiyo menu slug, and inject the separators immediately before/after that group.
 *   This avoids fragility around float->string casting, WP collision_avoider, locales
 *   or other plugins re-ordering the menu.
 *
 * Idempotent across plugins: the separators are added only if not already present,
 * detected by their stable SLUGs in $menu.
 *
 * @package wp-plugin-access-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wpam_Brand_Menu' ) ) {

	class Wpam_Brand_Menu {

		const TOP_SLUG    = 'qaiyo-brand-separator-top';
		const BOTTOM_SLUG = 'qaiyo-brand-separator-bottom';

		/** Hint position used if the Qaiyo plugin doesn't have a strong opinion. */
		const HINT_POSITION = 25.99;

		private static $plugin_count = 0;

		/**
		 * Each Qaiyo plugin registers its top-level menu slug here, so the brand
		 * separator can locate the group at admin_menu time regardless of where
		 * WordPress ultimately placed it. Shared across plugins via $GLOBALS.
		 *
		 * @param string $slug e.g. "qaiyo-access-manager"
		 */
		public static function register_plugin_slug( $slug ) {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Intentional cross-plugin shared global. The Qaiyo plugin family uses the "qaiyo_" namespace prefix so that any Qaiyo-branded plugin (Wpam_, Qt_, Qpdf_, etc.) can register into the same coordination list. Prefixing with a per-plugin slug here would break that shared mechanism.
			if ( ! isset( $GLOBALS['qaiyo_brand_menu_slugs'] ) || ! is_array( $GLOBALS['qaiyo_brand_menu_slugs'] ) ) {
				$GLOBALS['qaiyo_brand_menu_slugs'] = array();
			}
			if ( ! in_array( $slug, $GLOBALS['qaiyo_brand_menu_slugs'], true ) ) {
				$GLOBALS['qaiyo_brand_menu_slugs'][] = $slug;
			}
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		}

		/**
		 * Optional hint for menu_position. Most plugins should call this — but the
		 * actual placement is computed dynamically by inject_separators().
		 */
		public static function plugin_position() {
			self::$plugin_count++;
			return self::HINT_POSITION + ( 0.0001 * self::$plugin_count );
		}

		public static function init() {
			// Priority 999 ensures every plugin / theme has registered its menus already.
			add_action( 'admin_menu', array( __CLASS__, 'inject_separators' ), 999 );
			add_action( 'admin_head', array( __CLASS__, 'inline_css' ), 999 );
		}

		public static function inject_separators() {
			global $menu;
			if ( ! is_array( $menu ) ) {
				return;
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Intentional cross-plugin shared global, see register_plugin_slug().
			$slugs = isset( $GLOBALS['qaiyo_brand_menu_slugs'] ) ? $GLOBALS['qaiyo_brand_menu_slugs'] : array();
			if ( empty( $slugs ) ) {
				return;
			}

			$has_top    = false;
			$has_bottom = false;
			$found_keys = array();

			foreach ( $menu as $key => $item ) {
				if ( ! isset( $item[2] ) ) {
					continue;
				}
				if ( self::TOP_SLUG === $item[2] ) {
					$has_top = true;
				}
				if ( self::BOTTOM_SLUG === $item[2] ) {
					$has_bottom = true;
				}
				if ( in_array( $item[2], $slugs, true ) ) {
					$found_keys[] = (float) $key;
				}
			}

			if ( empty( $found_keys ) ) {
				return;
			}

			sort( $found_keys, SORT_NUMERIC );
			$first = $found_keys[0];
			$last  = end( $found_keys );

			if ( ! $has_top ) {
				$pos   = $first - 0.0001;
				$guard = 0;
				while ( isset( $menu[ (string) $pos ] ) && $guard < 200 ) {
					$pos -= 0.00001;
					$guard++;
				}
				$menu[ (string) $pos ] = array(
					'',
					'read',
					self::TOP_SLUG,
					'',
					'wp-menu-separator qaiyo-brand-separator qaiyo-brand-separator-top',
				);
			}

			if ( ! $has_bottom ) {
				$pos   = $last + 0.0001;
				$guard = 0;
				while ( isset( $menu[ (string) $pos ] ) && $guard < 200 ) {
					$pos += 0.00001;
					$guard++;
				}
				$menu[ (string) $pos ] = array(
					'',
					'read',
					self::BOTTOM_SLUG,
					'',
					'wp-menu-separator qaiyo-brand-separator qaiyo-brand-separator-bottom',
				);
			}
		}

		/** Build the chip as a base64 SVG data URI (with localized label baked in). */
		private static function chip_data_uri() {
			$label       = (string) __( 'Qaiyo Plugins', 'qaiyo-access-manager' );
			$label_upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $label, 'UTF-8' ) : strtoupper( $label );
			$label_xml   = htmlspecialchars( $label_upper, ENT_XML1 | ENT_QUOTES, 'UTF-8' );

			$char_count = function_exists( 'mb_strlen' ) ? mb_strlen( $label_upper ) : strlen( $label_upper );
			$width      = max( 78, (int) ( $char_count * 6.4 ) + 18 );
			$height     = 18;

			$svg = sprintf(
				'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d">' .
					'<rect x="0.5" y="0.5" width="%3$d" height="%4$d" rx="9" ry="9" ' .
					'fill="#ffffff" fill-opacity="0.05" stroke="#ffffff" stroke-opacity="0.18" stroke-width="1"/>' .
					'<text x="%5$d" y="%6$d" text-anchor="middle" ' .
					'font-family="-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,sans-serif" ' .
					'font-size="9" font-weight="700" fill="#a7aaad" letter-spacing="0.6">%7$s</text>' .
				'</svg>',
				$width,
				$height,
				$width - 1,
				$height - 1,
				(int) ( $width / 2 ),
				(int) ( $height / 2 + 3 ),
				$label_xml
			);

			return 'data:image/svg+xml;base64,' . base64_encode( $svg );
		}

		public static function inline_css() {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Intentional cross-plugin shared global; ensures only ONE Qaiyo plugin prints the brand-menu CSS even when multiple are active.
			if ( ! empty( $GLOBALS['qaiyo_brand_menu_css_printed'] ) ) {
				return;
			}
			$GLOBALS['qaiyo_brand_menu_css_printed'] = true;
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

			// Base64 payload only contains [A-Za-z0-9+/=], intrinsically safe to inline.
			$uri = self::chip_data_uri();
			?>
			<style id="qaiyo-brand-menu-css">
				#adminmenu li.wp-menu-separator.qaiyo-brand-separator {
					height: 10px !important;
					min-height: 0;
					padding: 0 !important;
					margin: 8px 0 6px !important;
					background: transparent !important;
					border: 0;
					border-bottom: 1px solid rgba(255, 255, 255, 0.12) !important;
					position: relative;
					cursor: default;
					pointer-events: none;
				}
				#adminmenu li.wp-menu-separator.qaiyo-brand-separator > div,
				#adminmenu li.wp-menu-separator.qaiyo-brand-separator > div.separator {
					display: none !important;
				}
				#adminmenu li.wp-menu-separator.qaiyo-brand-separator-top::before {
					content: url("<?php echo $uri; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- data:image/svg+xml base64 URI, intrinsically safe; esc_url() strips data: protocol. ?>");
					display: inline-block;
					transform: translate(8px, 1px);
					line-height: 1;
					vertical-align: top;
				}
				#adminmenu li.wp-menu-separator.qaiyo-brand-separator-bottom {
					margin: 6px 0 8px !important;
				}
				.folded #adminmenu li.wp-menu-separator.qaiyo-brand-separator-top::before {
					display: none;
				}
				.folded #adminmenu li.wp-menu-separator.qaiyo-brand-separator {
					margin: 6px 0 !important;
				}
				body.admin-color-light #adminmenu li.wp-menu-separator.qaiyo-brand-separator {
					border-bottom-color: rgba(0, 0, 0, 0.12) !important;
				}
			</style>
			<?php
		}
	}
}
