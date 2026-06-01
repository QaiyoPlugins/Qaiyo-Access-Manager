<?php
/**
 * Plugin Name: Qaiyo Access Manager
 * Plugin URI: https://qaiyo-plugins.com/
 * Description: Extend WordPress permission management: control which plugins and custom post types each role or individual user can see and manage.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Qaiyo by PixelDesigns
 * Author URI: https://qaiyo-plugins.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: qaiyo-access-manager
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAM_VERSION', '1.0.0' );
define( 'WPAM_PLUGIN_FILE', __FILE__ );
define( 'WPAM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPAM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WPAM_PLUGIN_DIR . 'includes/class-wpam-brand-menu.php';

if ( class_exists( 'Wpam_Brand_Menu' ) ) {
	Wpam_Brand_Menu::init();
	Wpam_Brand_Menu::register_plugin_slug( 'qaiyo-access-manager' );
}

final class Wpam_Access_Manager {

	private static $instance = null;
	private $option_cache = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		add_action( 'wp_ajax_wpam_save_access', array( $this, 'ajax_save_access' ) );
		add_action( 'wp_ajax_wpam_search_users', array( $this, 'ajax_search_users' ) );
		add_action( 'wp_ajax_wpam_export', array( $this, 'ajax_export' ) );
		add_action( 'wp_ajax_wpam_import', array( $this, 'ajax_import' ) );
		add_action( 'wp_ajax_wpam_set_delete_data', array( $this, 'ajax_set_delete_data' ) );

		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );

		add_filter( 'all_plugins', array( $this, 'filter_plugins_list' ) );
		add_filter( 'plugin_action_links', array( $this, 'filter_plugin_action_links' ), 10, 4 );

		add_action( 'admin_menu', array( $this, 'filter_cpt_admin_menus' ), 999 );
		add_filter( 'map_meta_cap', array( $this, 'filter_cpt_meta_caps' ), 10, 4 );
		add_filter( 'rest_pre_dispatch', array( $this, 'filter_cpt_rest_access' ), 10, 3 );
		add_action( 'pre_get_posts', array( $this, 'filter_cpt_queries' ) );
		add_action( 'template_redirect', array( $this, 'filter_cpt_frontend_access' ) );

		add_action( 'admin_notices', array( $this, 'restricted_notice' ) );
	}

	// =========================================================================
	// CORE HELPERS
	// =========================================================================

	private function get_option_cached( $key, $default = array() ) {
		if ( ! isset( $this->option_cache[ $key ] ) ) {
			$this->option_cache[ $key ] = get_option( $key, $default );
		}
		return $this->option_cache[ $key ];
	}

	private function clear_option_cache() {
		$this->option_cache = array();
	}

	private function get_plugin_rules() {
		return $this->get_option_cached( 'wpam_plugin_role_rules', array() );
	}

	private function get_cpt_rules() {
		return $this->get_option_cached( 'wpam_cpt_role_rules', array() );
	}

	private function get_user_plugin_rules() {
		return $this->get_option_cached( 'wpam_plugin_user_rules', array() );
	}

	private function get_user_cpt_rules() {
		return $this->get_option_cached( 'wpam_cpt_user_rules', array() );
	}

	private function get_editable_roles() {
		$roles    = wp_roles()->roles;
		$editable = array();
		foreach ( $roles as $slug => $role ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			$editable[ $slug ] = $role;
		}
		return $editable;
	}

	private function get_manageable_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		unset( $all[ WPAM_PLUGIN_BASENAME ] );
		return $all;
	}

	private function get_custom_post_types() {
		$builtin = array(
			'post', 'page', 'attachment', 'revision', 'nav_menu_item',
			'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
			'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles',
			'wp_navigation', 'wp_font_family', 'wp_font_face',
			// WPML
			'wp_translation', 'wpml_translation_job',
			// Polylang
			'polylang_mo',
			// TranslatePress
			'trp_translation', 'trp_language',
		);
		$all    = get_post_types( array(), 'objects' );
		$custom = array();
		foreach ( $all as $slug => $obj ) {
			if ( in_array( $slug, $builtin, true ) ) {
				continue;
			}
			if ( ! $obj->public && ! $obj->show_ui ) {
				continue;
			}
			$custom[ $slug ] = $obj;
		}
		return $custom;
	}

	// =========================================================================
	// ACCESS CHECK LOGIC
	// priority: admin → user deny → user allow → role rules → default open
	// =========================================================================

	private function can_user_access_plugin( $plugin_file, $user_id = 0 ) {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return true;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}

		$user_rules = $this->get_user_plugin_rules();
		if ( isset( $user_rules[ $plugin_file ] ) ) {
			if ( in_array( $user->ID, $user_rules[ $plugin_file ]['denied'] ?? array(), true ) ) {
				return false;
			}
			if ( in_array( $user->ID, $user_rules[ $plugin_file ]['allowed'] ?? array(), true ) ) {
				return true;
			}
		}

		/**
		 * Pro hook: allow group-based rules to override before role-level check.
		 *
		 * @param bool|null $result  null = no override, true/false = final answer
		 * @param string    $plugin_file
		 * @param WP_User   $user
		 */
		$pro_override = apply_filters( 'wpam_group_access_plugin', null, $plugin_file, $user );
		if ( null !== $pro_override ) {
			return (bool) $pro_override;
		}

		$role_rules = $this->get_plugin_rules();
		if ( ! isset( $role_rules[ $plugin_file ] ) || empty( $role_rules[ $plugin_file ] ) ) {
			return true;
		}
		return (bool) array_intersect( (array) $user->roles, $role_rules[ $plugin_file ] );
	}

	private function can_user_access_cpt( $post_type, $user_id = 0 ) {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return true;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}

		$user_rules = $this->get_user_cpt_rules();
		if ( isset( $user_rules[ $post_type ] ) ) {
			if ( in_array( $user->ID, $user_rules[ $post_type ]['denied'] ?? array(), true ) ) {
				return false;
			}
			if ( in_array( $user->ID, $user_rules[ $post_type ]['allowed'] ?? array(), true ) ) {
				return true;
			}
		}

		/** @see wpam_group_access_plugin */
		$pro_override = apply_filters( 'wpam_group_access_cpt', null, $post_type, $user );
		if ( null !== $pro_override ) {
			return (bool) $pro_override;
		}

		$role_rules = $this->get_cpt_rules();
		if ( ! isset( $role_rules[ $post_type ] ) || empty( $role_rules[ $post_type ] ) ) {
			return true;
		}
		return (bool) array_intersect( (array) $user->roles, $role_rules[ $post_type ] );
	}

	// =========================================================================
	// NATIVE CAPABILITY INFO
	// =========================================================================

	private function get_roles_with_cap( $cap ) {
		$roles  = wp_roles();
		$result = array();
		foreach ( $roles->roles as $slug => $role_data ) {
			if ( ! empty( $role_data['capabilities'][ $cap ] ) ) {
				$result[ $slug ] = translate_user_role( $role_data['name'] );
			}
		}
		return $result;
	}

	private function get_plugin_native_caps_info() {
		$plugin_caps = array( 'activate_plugins', 'edit_plugins', 'install_plugins', 'update_plugins', 'delete_plugins' );
		$cap_labels  = array(
			'activate_plugins' => __( 'Activate/Deactivate', 'qaiyo-access-manager' ),
			'edit_plugins'     => __( 'Edit', 'qaiyo-access-manager' ),
			'install_plugins'  => __( 'Install', 'qaiyo-access-manager' ),
			'update_plugins'   => __( 'Update', 'qaiyo-access-manager' ),
			'delete_plugins'   => __( 'Delete', 'qaiyo-access-manager' ),
		);
		$info = array();
		foreach ( $plugin_caps as $cap ) {
			$roles_with = $this->get_roles_with_cap( $cap );
			if ( ! empty( $roles_with ) ) {
				$info[ $cap ] = array(
					'label' => $cap_labels[ $cap ],
					'roles' => $roles_with,
				);
			}
		}
		return $info;
	}

	private function get_cpt_native_caps_info( $post_type_obj ) {
		$caps       = (array) $post_type_obj->cap;
		$important  = array( 'edit_posts', 'publish_posts', 'delete_posts', 'edit_others_posts', 'read_private_posts', 'create_posts' );
		$cap_labels = array(
			'edit_posts'         => __( 'Edit', 'qaiyo-access-manager' ),
			'publish_posts'      => __( 'Publish', 'qaiyo-access-manager' ),
			'delete_posts'       => __( 'Delete', 'qaiyo-access-manager' ),
			'edit_others_posts'  => __( 'Edit others', 'qaiyo-access-manager' ),
			'read_private_posts' => __( 'Read private', 'qaiyo-access-manager' ),
			'create_posts'       => __( 'Create', 'qaiyo-access-manager' ),
		);
		$info = array();
		foreach ( $important as $generic_cap ) {
			if ( ! isset( $caps[ $generic_cap ] ) ) {
				continue;
			}
			$actual_cap = $caps[ $generic_cap ];
			$roles_with = $this->get_roles_with_cap( $actual_cap );
			if ( ! empty( $roles_with ) ) {
				$info[ $generic_cap ] = array(
					'label'  => $cap_labels[ $generic_cap ] ?? $generic_cap,
					'wp_cap' => $actual_cap,
					'roles'  => $roles_with,
				);
			}
		}
		return $info;
	}

	// =========================================================================
	// ADMIN MENU & SETTINGS
	// =========================================================================

	public function add_admin_menu() {
		add_menu_page(
			__( 'Qaiyo Access Manager', 'qaiyo-access-manager' ),
			__( 'Access Manager', 'qaiyo-access-manager' ),
			'manage_options',
			'qaiyo-access-manager',
			array( $this, 'render_settings_page' ),
			'dashicons-shield-alt',
			class_exists( 'Wpam_Brand_Menu' ) ? Wpam_Brand_Menu::plugin_position() : 25
		);
	}

	public function register_settings() {
		register_setting( 'wpam_settings_group', 'wpam_plugin_role_rules', array( 'sanitize_callback' => array( $this, 'sanitize_role_rules' ) ) );
		register_setting( 'wpam_settings_group', 'wpam_cpt_role_rules', array( 'sanitize_callback' => array( $this, 'sanitize_cpt_role_rules' ) ) );
		register_setting( 'wpam_settings_group', 'wpam_plugin_user_rules', array( 'sanitize_callback' => array( $this, 'sanitize_user_rules' ) ) );
		register_setting( 'wpam_settings_group', 'wpam_cpt_user_rules', array( 'sanitize_callback' => array( $this, 'sanitize_user_rules' ) ) );
	}

	public function sanitize_role_rules( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$sanitized   = array();
		$all_plugins = array_keys( get_plugins() );
		$all_roles   = array_keys( wp_roles()->roles );
		foreach ( $input as $key => $roles ) {
			if ( ! in_array( $key, $all_plugins, true ) ) {
				continue;
			}
			$sanitized[ sanitize_text_field( $key ) ] = array();
			if ( is_array( $roles ) ) {
				foreach ( $roles as $r ) {
					if ( in_array( $r, $all_roles, true ) ) {
						$sanitized[ sanitize_text_field( $key ) ][] = sanitize_key( $r );
					}
				}
			}
		}
		return $sanitized;
	}

	public function sanitize_cpt_role_rules( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$sanitized = array();
		$all_roles = array_keys( wp_roles()->roles );
		foreach ( $input as $pt => $roles ) {
			$clean_pt                = sanitize_key( $pt );
			$sanitized[ $clean_pt ] = array();
			if ( is_array( $roles ) ) {
				foreach ( $roles as $r ) {
					if ( in_array( $r, $all_roles, true ) ) {
						$sanitized[ $clean_pt ][] = sanitize_key( $r );
					}
				}
			}
		}
		return $sanitized;
	}

	public function sanitize_user_rules( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$sanitized = array();
		foreach ( $input as $key => $data ) {
			$clean_key                = sanitize_text_field( $key );
			$sanitized[ $clean_key ] = array(
				'allowed' => array(),
				'denied'  => array(),
			);
			if ( isset( $data['allowed'] ) && is_array( $data['allowed'] ) ) {
				$sanitized[ $clean_key ]['allowed'] = array_map( 'absint', $data['allowed'] );
			}
			if ( isset( $data['denied'] ) && is_array( $data['denied'] ) ) {
				$sanitized[ $clean_key ]['denied'] = array_map( 'absint', $data['denied'] );
			}
		}
		return $sanitized;
	}

	// =========================================================================
	// ADMIN ASSETS
	// =========================================================================

	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_qaiyo-access-manager' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wpam-admin', WPAM_PLUGIN_URL . 'assets/css/admin.css', array(), WPAM_VERSION );
		wp_enqueue_script( 'wpam-admin', WPAM_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), WPAM_VERSION, true );

		wp_localize_script( 'wpam-admin', 'wpam_data', array(
			'ajax_url'    => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'wpam_save_access_nonce' ),
			'user_nonce'  => wp_create_nonce( 'wpam_search_users_nonce' ),
			'tools_nonce' => wp_create_nonce( 'wpam_tools_nonce' ),
			'i18n'        => array(
				'saving'          => __( 'Saving...', 'qaiyo-access-manager' ),
				'saved'           => __( 'Settings saved!', 'qaiyo-access-manager' ),
				'error'           => __( 'An error occurred while saving.', 'qaiyo-access-manager' ),
				'search_user'     => __( 'Search user...', 'qaiyo-access-manager' ),
				'allow'           => __( 'Allowed', 'qaiyo-access-manager' ),
				'deny'            => __( 'Denied', 'qaiyo-access-manager' ),
				'remove'          => __( 'Remove', 'qaiyo-access-manager' ),
				'no_results'      => __( 'No results', 'qaiyo-access-manager' ),
				'save_btn'        => __( 'Save settings', 'qaiyo-access-manager' ),
				'exporting'       => __( 'Exporting...', 'qaiyo-access-manager' ),
				'export_done'     => __( 'Export complete!', 'qaiyo-access-manager' ),
				'importing'       => __( 'Importing...', 'qaiyo-access-manager' ),
				'import_done'     => __( 'Settings imported successfully! Reloading...', 'qaiyo-access-manager' ),
				'import_error'    => __( 'Import failed.', 'qaiyo-access-manager' ),
				'invalid_file'    => __( 'Invalid file. Please select a .json file exported from Qaiyo Access Manager.', 'qaiyo-access-manager' ),
				'confirm_import'  => __( 'This will overwrite all current access rules. Continue?', 'qaiyo-access-manager' ),
			),
		) );
	}

	// =========================================================================
	// AJAX HANDLERS
	// =========================================================================

	public function ajax_save_access() {
		check_ajax_referer( 'wpam_save_access_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'qaiyo-access-manager' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- JSON strings are decoded and sanitized below via dedicated sanitize_*_rules() methods.
		$plugin_role_raw = isset( $_POST['plugin_role_rules'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_role_rules'] ) ) : '{}';
		$cpt_role_raw    = isset( $_POST['cpt_role_rules'] ) ? sanitize_text_field( wp_unslash( $_POST['cpt_role_rules'] ) ) : '{}';
		$plugin_user_raw = isset( $_POST['plugin_user_rules'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_user_rules'] ) ) : '{}';
		$cpt_user_raw    = isset( $_POST['cpt_user_rules'] ) ? sanitize_text_field( wp_unslash( $_POST['cpt_user_rules'] ) ) : '{}';
		// phpcs:enable

		$plugin_role = json_decode( $plugin_role_raw, true );
		$cpt_role    = json_decode( $cpt_role_raw, true );
		$plugin_user = json_decode( $plugin_user_raw, true );
		$cpt_user    = json_decode( $cpt_user_raw, true );

		update_option( 'wpam_plugin_role_rules', $this->sanitize_role_rules( is_array( $plugin_role ) ? $plugin_role : array() ) );
		update_option( 'wpam_cpt_role_rules', $this->sanitize_cpt_role_rules( is_array( $cpt_role ) ? $cpt_role : array() ) );
		update_option( 'wpam_plugin_user_rules', $this->sanitize_user_rules( is_array( $plugin_user ) ? $plugin_user : array() ) );
		update_option( 'wpam_cpt_user_rules', $this->sanitize_user_rules( is_array( $cpt_user ) ? $cpt_user : array() ) );

		$this->clear_option_cache();

		/**
		 * Pro hook: allow Pro modules to save additional data alongside access rules.
		 */
		do_action( 'wpam_after_save_access' );

		wp_send_json_success( array( 'message' => __( 'Settings saved!', 'qaiyo-access-manager' ) ) );
	}

	public function ajax_search_users() {
		check_ajax_referer( 'wpam_search_users_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified above via check_ajax_referer.
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( strlen( $search ) < 2 ) {
			wp_send_json_success( array() );
		}

		$users = get_users( array(
			'search'         => '*' . $search . '*',
			'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			'number'         => 10,
			'role__not_in'   => array( 'administrator' ),
			'fields'         => array( 'ID', 'user_login', 'display_name', 'user_email' ),
		) );

		$results = array();
		foreach ( $users as $u ) {
			$user_obj  = get_userdata( $u->ID );
			$results[] = array(
				'id'    => (int) $u->ID,
				'login' => $u->user_login,
				'name'  => $u->display_name,
				'email' => $u->user_email,
				'role'  => $user_obj ? implode( ', ', $user_obj->roles ) : '',
			);
		}

		wp_send_json_success( $results );
	}

	// =========================================================================
	// EXPORT / IMPORT
	// =========================================================================

	public function ajax_export() {
		check_ajax_referer( 'wpam_tools_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'qaiyo-access-manager' ) ), 403 );
		}

		$data = array(
			'plugin'             => 'qaiyo-access-manager',
			'version'            => WPAM_VERSION,
			'exported'           => gmdate( 'Y-m-d H:i:s' ),
			'plugin_role_rules'  => get_option( 'wpam_plugin_role_rules', array() ),
			'cpt_role_rules'     => get_option( 'wpam_cpt_role_rules', array() ),
			'plugin_user_rules'  => get_option( 'wpam_plugin_user_rules', array() ),
			'cpt_user_rules'     => get_option( 'wpam_cpt_user_rules', array() ),
		);

		wp_send_json_success( $data );
	}

	public function ajax_import() {
		check_ajax_referer( 'wpam_tools_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'qaiyo-access-manager' ) ), 403 );
		}

		$raw_json = isset( $_POST['import_data'] ) ? sanitize_text_field( wp_unslash( $_POST['import_data'] ) ) : '';
		if ( empty( $raw_json ) ) {
			wp_send_json_error( array( 'message' => __( 'No import data received.', 'qaiyo-access-manager' ) ) );
		}

		$data = json_decode( $raw_json, true );
		if ( ! is_array( $data ) || empty( $data['plugin'] ) || 'qaiyo-access-manager' !== $data['plugin'] ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import file. Please use a file exported from Qaiyo Access Manager.', 'qaiyo-access-manager' ) ) );
		}

		if ( isset( $data['plugin_role_rules'] ) && is_array( $data['plugin_role_rules'] ) ) {
			update_option( 'wpam_plugin_role_rules', $this->sanitize_role_rules( $data['plugin_role_rules'] ) );
		}
		if ( isset( $data['cpt_role_rules'] ) && is_array( $data['cpt_role_rules'] ) ) {
			update_option( 'wpam_cpt_role_rules', $this->sanitize_cpt_role_rules( $data['cpt_role_rules'] ) );
		}
		if ( isset( $data['plugin_user_rules'] ) && is_array( $data['plugin_user_rules'] ) ) {
			update_option( 'wpam_plugin_user_rules', $this->sanitize_user_rules( $data['plugin_user_rules'] ) );
		}
		if ( isset( $data['cpt_user_rules'] ) && is_array( $data['cpt_user_rules'] ) ) {
			update_option( 'wpam_cpt_user_rules', $this->sanitize_user_rules( $data['cpt_user_rules'] ) );
		}

		$this->clear_option_cache();

		wp_send_json_success( array( 'message' => __( 'Settings imported successfully!', 'qaiyo-access-manager' ) ) );
	}

	public function ajax_set_delete_data() {
		check_ajax_referer( 'wpam_tools_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$enabled = isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '0';
		update_option( 'wpam_delete_data_on_uninstall', '1' === $enabled, true );

		wp_send_json_success();
	}

	// =========================================================================
	// DASHBOARD WIDGET
	// =========================================================================

	public function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'wpam_dashboard_widget',
			__( 'Qaiyo Access Manager', 'qaiyo-access-manager' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget() {
		$plugin_rules = $this->get_plugin_rules();
		$cpt_rules    = $this->get_cpt_rules();
		$user_p_rules = $this->get_user_plugin_rules();
		$user_c_rules = $this->get_user_cpt_rules();

		$restricted_plugins = count( $plugin_rules );
		$restricted_cpts    = count( $cpt_rules );

		$user_override_count = 0;
		foreach ( $user_p_rules as $item_rules ) {
			$user_override_count += count( $item_rules['allowed'] ?? array() );
			$user_override_count += count( $item_rules['denied'] ?? array() );
		}
		foreach ( $user_c_rules as $item_rules ) {
			$user_override_count += count( $item_rules['allowed'] ?? array() );
			$user_override_count += count( $item_rules['denied'] ?? array() );
		}

		$total_rules = $restricted_plugins + $restricted_cpts;
		?>
		<div class="wpam-dashboard-widget">
			<div class="wpam-dw-stats">
				<div class="wpam-dw-stat">
					<span class="wpam-dw-stat-number"><?php echo esc_html( $restricted_plugins ); ?></span>
					<span class="wpam-dw-stat-label"><?php esc_html_e( 'Restricted plugins', 'qaiyo-access-manager' ); ?></span>
				</div>
				<div class="wpam-dw-stat">
					<span class="wpam-dw-stat-number"><?php echo esc_html( $restricted_cpts ); ?></span>
					<span class="wpam-dw-stat-label"><?php esc_html_e( 'Restricted CPTs', 'qaiyo-access-manager' ); ?></span>
				</div>
				<div class="wpam-dw-stat">
					<span class="wpam-dw-stat-number"><?php echo esc_html( $user_override_count ); ?></span>
					<span class="wpam-dw-stat-label"><?php esc_html_e( 'User overrides', 'qaiyo-access-manager' ); ?></span>
				</div>
			</div>

			<?php if ( 0 === $total_rules ) : ?>
				<p class="wpam-dw-empty"><?php esc_html_e( 'No access rules configured yet.', 'qaiyo-access-manager' ); ?></p>
			<?php endif; ?>

			<p class="wpam-dw-link">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=qaiyo-access-manager' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Manage access rules', 'qaiyo-access-manager' ); ?>
				</a>
			</p>
		</div>
		<style>
			.wpam-dw-stats { display: flex; gap: 12px; margin-bottom: 12px; }
			.wpam-dw-stat { flex: 1; text-align: center; background: #f8f6ff; border: 1px solid #e2dcf7; border-radius: 4px; padding: 12px 8px; }
			.wpam-dw-stat-number { display: block; font-size: 24px; font-weight: 700; color: #6c5ce7; line-height: 1.2; }
			.wpam-dw-stat-label { display: block; font-size: 11px; color: #787c82; margin-top: 4px; }
			.wpam-dw-empty { color: #787c82; text-align: center; font-style: italic; }
			.wpam-dw-link { text-align: center; margin: 0; }
			.wpam-dw-link .button-primary { background: #6c5ce7; border-color: #5a4bd1; }
			.wpam-dw-link .button-primary:hover { background: #5a4bd1; }
		</style>
		<?php
	}

	// =========================================================================
	// PLUGIN FILTERS
	// =========================================================================

	public function filter_plugins_list( $plugins ) {
		if ( current_user_can( 'manage_options' ) ) {
			return $plugins;
		}
		$filtered = array();
		foreach ( $plugins as $file => $data ) {
			if ( $file === WPAM_PLUGIN_BASENAME ) {
				continue;
			}
			if ( $this->can_user_access_plugin( $file ) ) {
				$filtered[ $file ] = $data;
			}
		}
		return $filtered;
	}

	public function filter_plugin_action_links( $actions, $plugin_file, $plugin_data, $context ) {
		if ( current_user_can( 'manage_options' ) ) {
			return $actions;
		}
		if ( ! $this->can_user_access_plugin( $plugin_file ) ) {
			unset( $actions['activate'], $actions['deactivate'], $actions['delete'], $actions['edit'] );
		}
		return $actions;
	}

	// =========================================================================
	// CPT FILTERS
	// =========================================================================

	public function filter_cpt_admin_menus() {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		$cpt_role_rules = $this->get_cpt_rules();
		$cpt_user_rules = $this->get_user_cpt_rules();
		$all_cpts       = array_unique( array_merge( array_keys( $cpt_role_rules ), array_keys( $cpt_user_rules ) ) );

		foreach ( $all_cpts as $post_type ) {
			if ( $this->can_user_access_cpt( $post_type ) ) {
				continue;
			}
			$type_obj = get_post_type_object( $post_type );
			if ( ! $type_obj ) {
				continue;
			}
			if ( true === $type_obj->show_in_menu ) {
				remove_menu_page( 'edit.php?post_type=' . $post_type );
			} elseif ( is_string( $type_obj->show_in_menu ) ) {
				remove_submenu_page( $type_obj->show_in_menu, 'edit.php?post_type=' . $post_type );
			}
		}
	}

	private $cpt_caps_map = null;

	private function build_cpt_caps_map() {
		if ( null !== $this->cpt_caps_map ) {
			return $this->cpt_caps_map;
		}
		$this->cpt_caps_map = array();
		$role_rules         = $this->get_cpt_rules();
		$user_rules         = $this->get_user_cpt_rules();
		$all_cpts           = array_unique( array_merge( array_keys( $role_rules ), array_keys( $user_rules ) ) );

		foreach ( $all_cpts as $post_type ) {
			$type_obj = get_post_type_object( $post_type );
			if ( ! $type_obj ) {
				continue;
			}
			foreach ( (array) $type_obj->cap as $wp_cap ) {
				if ( 'do_not_allow' === $wp_cap || 'exist' === $wp_cap ) {
					continue;
				}
				$this->cpt_caps_map[ $wp_cap ] = $post_type;
			}
		}
		return $this->cpt_caps_map;
	}

	public function filter_cpt_meta_caps( $caps, $cap, $user_id, $args ) {
		$map = $this->build_cpt_caps_map();
		if ( ! isset( $map[ $cap ] ) ) {
			return $caps;
		}
		$post_type = $map[ $cap ];
		if ( ! $this->can_user_access_cpt( $post_type, $user_id ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	public function filter_cpt_rest_access( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return $result;
		}
		$route      = $request->get_route();
		$role_rules = $this->get_cpt_rules();
		$user_rules = $this->get_user_cpt_rules();
		$all_cpts   = array_unique( array_merge( array_keys( $role_rules ), array_keys( $user_rules ) ) );

		foreach ( $all_cpts as $post_type ) {
			$type_obj = get_post_type_object( $post_type );
			if ( ! $type_obj || ! $type_obj->show_in_rest ) {
				continue;
			}
			$rest_base = ! empty( $type_obj->rest_base ) ? $type_obj->rest_base : $post_type;
			if ( preg_match( '#^/wp/v2/' . preg_quote( $rest_base, '#' ) . '(/|$)#', $route ) ) {
				if ( ! $this->can_user_access_cpt( $post_type ) ) {
					return new WP_Error(
						'wpam_rest_forbidden',
						__( 'You do not have permission to access this content type.', 'qaiyo-access-manager' ),
						array( 'status' => 403 )
					);
				}
			}
		}
		return $result;
	}

	public function filter_cpt_queries( $query ) {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) ) {
			return;
		}
		$types = is_array( $post_type ) ? $post_type : array( $post_type );
		foreach ( $types as $type ) {
			if ( ! $this->can_user_access_cpt( $type ) ) {
				$query->set( 'post_type', '' );
				$query->set( 'post__in', array( 0 ) );
				return;
			}
		}
	}

	public function filter_cpt_frontend_access() {
		if ( current_user_can( 'manage_options' ) || ! is_singular() ) {
			return;
		}
		$post_type = get_post_type();
		if ( ! $post_type ) {
			return;
		}
		if ( $this->can_user_access_cpt( $post_type ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			return;
		}
		wp_safe_redirect( home_url() );
		exit;
	}

	// =========================================================================
	// ADMIN NOTICE
	// =========================================================================

	public function restricted_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id || current_user_can( 'manage_options' ) ) {
			return;
		}
		$has_any = ! empty( $this->get_plugin_rules() ) || ! empty( $this->get_user_plugin_rules() );
		if ( $has_any ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__( 'Some plugins are hidden because your access is restricted. Contact your administrator for more access.', 'qaiyo-access-manager' )
			);
		}
	}

	// =========================================================================
	// SETTINGS PAGE RENDER
	// =========================================================================

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'qaiyo-access-manager' ) );
		}

		$plugins            = $this->get_manageable_plugins();
		$cpts               = $this->get_custom_post_types();
		$roles              = $this->get_editable_roles();
		$plugin_role_rules  = $this->get_plugin_rules();
		$cpt_role_rules     = $this->get_cpt_rules();
		$plugin_user_rules  = $this->get_user_plugin_rules();
		$cpt_user_rules     = $this->get_user_cpt_rules();
		$native_plugin_info = $this->get_plugin_native_caps_info();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation, no data is processed.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'plugins';
		/**
		 * Pro hook: allow additional valid tab slugs.
		 */
		$valid_tabs = apply_filters( 'wpam_valid_tabs', array( 'plugins', 'cpt', 'matrix', 'tools' ) );
		if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
			$active_tab = 'plugins';
		}

		?>
		<div class="wrap wpam-wrap">
			<h1>
				<span class="wpam-brand">Qaiyo</span>
				<?php esc_html_e( 'Access Manager', 'qaiyo-access-manager' ); ?>
				<span class="wpam-version">v<?php echo esc_html( WPAM_VERSION ); ?></span>
			</h1>
			<p class="wpam-description">
				<?php esc_html_e( 'Control plugin and content type access at role and user level. Administrators always have full access.', 'qaiyo-access-manager' ); ?>
			</p>

			<nav class="nav-tab-wrapper wpam-tabs">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'plugins', admin_url( 'admin.php?page=qaiyo-access-manager' ) ) ); ?>"
				   class="nav-tab <?php echo 'plugins' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Plugins', 'qaiyo-access-manager' ); ?>
					<span class="wpam-tab-count"><?php echo count( $plugins ); ?></span>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'cpt', admin_url( 'admin.php?page=qaiyo-access-manager' ) ) ); ?>"
				   class="nav-tab <?php echo 'cpt' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Content Types (CPT)', 'qaiyo-access-manager' ); ?>
					<span class="wpam-tab-count"><?php echo count( $cpts ); ?></span>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'matrix', admin_url( 'admin.php?page=qaiyo-access-manager' ) ) ); ?>"
				   class="nav-tab <?php echo 'matrix' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Access Matrix', 'qaiyo-access-manager' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'tools', admin_url( 'admin.php?page=qaiyo-access-manager' ) ) ); ?>"
				   class="nav-tab <?php echo 'tools' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Tools', 'qaiyo-access-manager' ); ?>
				</a>
				<?php
				/**
				 * Pro hook: additional tabs in the nav.
				 *
				 * @param string $active_tab Current active tab slug.
				 */
				do_action( 'wpam_after_tabs', $active_tab );
				?>
			</nav>

			<?php
			$hide_toolbar_tabs = apply_filters( 'wpam_hide_toolbar_tabs', array( 'matrix', 'tools' ) );
			?>
			<div class="wpam-toolbar" <?php echo in_array( $active_tab, $hide_toolbar_tabs, true ) ? 'style="display:none;"' : ''; ?>>
				<div class="wpam-search-box">
					<input type="text" id="wpam-search" placeholder="<?php esc_attr_e( 'Search...', 'qaiyo-access-manager' ); ?>" />
				</div>
				<div class="wpam-actions">
					<?php do_action( 'wpam_toolbar_actions', $active_tab ); ?>
					<button type="button" class="button" id="wpam-expand-all"><?php esc_html_e( 'Expand all', 'qaiyo-access-manager' ); ?></button>
					<button type="button" class="button" id="wpam-collapse-all"><?php esc_html_e( 'Collapse all', 'qaiyo-access-manager' ); ?></button>
				</div>
			</div>

			<form id="wpam-access-form" method="post" data-active-tab="<?php echo esc_attr( $active_tab ); ?>">
				<?php wp_nonce_field( 'wpam_save_access_nonce', 'wpam_nonce' ); ?>

				<!-- PLUGINS TAB -->
				<div class="wpam-tab-content" id="wpam-tab-plugins" <?php echo 'plugins' !== $active_tab ? 'style="display:none;"' : ''; ?>>
					<div class="wpam-items-list">
						<?php if ( empty( $plugins ) ) : ?>
							<div class="wpam-empty"><?php esc_html_e( 'No plugins installed to manage.', 'qaiyo-access-manager' ); ?></div>
						<?php else : ?>
							<?php foreach ( $plugins as $plugin_file => $plugin_data ) :
								$p_roles   = $plugin_role_rules[ $plugin_file ] ?? array();
								$p_users   = $plugin_user_rules[ $plugin_file ] ?? array( 'allowed' => array(), 'denied' => array() );
								$is_active = is_plugin_active( $plugin_file );
								$has_rules = ! empty( $p_roles ) || ! empty( $p_users['allowed'] ) || ! empty( $p_users['denied'] );
							?>
								<div class="wpam-card <?php echo $is_active ? 'wpam-active' : 'wpam-inactive'; ?> <?php echo $has_rules ? 'wpam-has-rules' : ''; ?>"
									 data-item-type="plugin"
									 data-item-key="<?php echo esc_attr( $plugin_file ); ?>"
									 data-item-name="<?php echo esc_attr( strtolower( $plugin_data['Name'] ) ); ?>">

									<div class="wpam-card-header">
										<div class="wpam-card-info">
											<h3 class="wpam-card-title">
												<?php echo esc_html( $plugin_data['Name'] ); ?>
												<span class="wpam-card-version"><?php echo esc_html( $plugin_data['Version'] ); ?></span>
											</h3>
											<span class="wpam-badge <?php echo $is_active ? 'wpam-badge-active' : 'wpam-badge-inactive'; ?>">
												<?php echo $is_active ? esc_html__( 'Active', 'qaiyo-access-manager' ) : esc_html__( 'Inactive', 'qaiyo-access-manager' ); ?>
											</span>
											<?php if ( $has_rules ) : ?>
												<span class="wpam-badge wpam-badge-restricted"><?php esc_html_e( 'Restricted', 'qaiyo-access-manager' ); ?></span>
											<?php endif; ?>
										</div>
										<button type="button" class="wpam-toggle-btn" aria-expanded="false">
											<span class="dashicons dashicons-arrow-down-alt2"></span>
										</button>
									</div>

									<div class="wpam-card-body" style="display:none;">
										<?php if ( ! empty( $plugin_data['Description'] ) ) : ?>
											<p class="wpam-card-desc"><?php echo esc_html( $plugin_data['Description'] ); ?></p>
										<?php endif; ?>

										<div class="wpam-native-caps">
											<h4><?php esc_html_e( 'Default WordPress Capabilities', 'qaiyo-access-manager' ); ?></h4>
											<div class="wpam-native-caps-grid">
												<?php foreach ( $native_plugin_info as $cap_key => $cap_info ) : ?>
													<div class="wpam-native-cap-item">
														<span class="wpam-native-cap-label"><?php echo esc_html( $cap_info['label'] ); ?>:</span>
														<span class="wpam-native-cap-roles"><?php echo esc_html( implode( ', ', $cap_info['roles'] ) ); ?></span>
													</div>
												<?php endforeach; ?>
											</div>
										</div>

										<div class="wpam-section">
											<h4><?php esc_html_e( 'Role-based access', 'qaiyo-access-manager' ); ?></h4>
											<p class="wpam-hint"><?php esc_html_e( 'If no role is selected, the item remains accessible to everyone.', 'qaiyo-access-manager' ); ?></p>
											<div class="wpam-roles-grid">
												<?php foreach ( $roles as $role_slug => $role_data ) : ?>
													<label class="wpam-role-checkbox">
														<input type="checkbox" class="wpam-role-cb"
															   data-target="<?php echo esc_attr( $plugin_file ); ?>"
															   value="<?php echo esc_attr( $role_slug ); ?>"
															   <?php checked( in_array( $role_slug, $p_roles, true ) ); ?> />
														<span class="wpam-role-name"><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></span>
													</label>
												<?php endforeach; ?>
											</div>
											<div class="wpam-quick-actions">
												<button type="button" class="button button-small wpam-select-all-roles"><?php esc_html_e( 'Select all', 'qaiyo-access-manager' ); ?></button>
												<button type="button" class="button button-small wpam-deselect-all-roles"><?php esc_html_e( 'Deselect all', 'qaiyo-access-manager' ); ?></button>
											</div>
										</div>

										<div class="wpam-section wpam-user-section">
											<h4><?php esc_html_e( 'User-level override', 'qaiyo-access-manager' ); ?></h4>
											<p class="wpam-hint"><?php esc_html_e( 'Individual allow or deny that overrides role-based rules. Administrators cannot be listed.', 'qaiyo-access-manager' ); ?></p>
											<div class="wpam-user-search-wrap">
												<input type="text" class="wpam-user-search" placeholder="<?php esc_attr_e( 'Search user (name, email, login)...', 'qaiyo-access-manager' ); ?>" autocomplete="off" />
												<div class="wpam-user-search-results"></div>
											</div>
											<div class="wpam-user-rules-list"
												 data-allowed="<?php echo esc_attr( wp_json_encode( $p_users['allowed'] ?? array() ) ); ?>"
												 data-denied="<?php echo esc_attr( wp_json_encode( $p_users['denied'] ?? array() ) ); ?>">
												<?php
												$all_user_ids = array_merge( $p_users['allowed'] ?? array(), $p_users['denied'] ?? array() );
												foreach ( $all_user_ids as $uid ) :
													$u = get_userdata( $uid );
													if ( ! $u ) {
														continue;
													}
													$is_allowed = in_array( $uid, $p_users['allowed'] ?? array(), true );
												?>
													<div class="wpam-user-rule-row" data-user-id="<?php echo esc_attr( $uid ); ?>">
														<span class="wpam-user-rule-info">
															<strong><?php echo esc_html( $u->display_name ); ?></strong>
															<span class="wpam-user-rule-meta"><?php echo esc_html( $u->user_email . ' — ' . implode( ', ', $u->roles ) ); ?></span>
														</span>
														<select class="wpam-user-rule-type">
															<option value="allowed" <?php selected( $is_allowed ); ?>><?php esc_html_e( 'Allowed', 'qaiyo-access-manager' ); ?></option>
															<option value="denied" <?php selected( ! $is_allowed ); ?>><?php esc_html_e( 'Denied', 'qaiyo-access-manager' ); ?></option>
														</select>
														<button type="button" class="button button-small wpam-user-rule-remove" title="<?php esc_attr_e( 'Remove', 'qaiyo-access-manager' ); ?>">&times;</button>
													</div>
												<?php endforeach; ?>
											</div>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<!-- CPT TAB -->
				<div class="wpam-tab-content" id="wpam-tab-cpt" <?php echo 'cpt' !== $active_tab ? 'style="display:none;"' : ''; ?>>
					<div class="wpam-items-list">
						<?php if ( empty( $cpts ) ) : ?>
							<div class="wpam-empty"><?php esc_html_e( 'No custom post types registered.', 'qaiyo-access-manager' ); ?></div>
						<?php else : ?>
							<?php foreach ( $cpts as $cpt_slug => $cpt_obj ) :
								$c_roles     = $cpt_role_rules[ $cpt_slug ] ?? array();
								$c_users     = $cpt_user_rules[ $cpt_slug ] ?? array( 'allowed' => array(), 'denied' => array() );
								$has_rules   = ! empty( $c_roles ) || ! empty( $c_users['allowed'] ) || ! empty( $c_users['denied'] );
								$native_info = $this->get_cpt_native_caps_info( $cpt_obj );
							?>
								<div class="wpam-card wpam-cpt-card <?php echo $has_rules ? 'wpam-has-rules' : ''; ?>"
									 data-item-type="cpt"
									 data-item-key="<?php echo esc_attr( $cpt_slug ); ?>"
									 data-item-name="<?php echo esc_attr( strtolower( $cpt_obj->labels->name ) ); ?>">

									<div class="wpam-card-header">
										<div class="wpam-card-info">
											<h3 class="wpam-card-title">
												<?php echo esc_html( $cpt_obj->labels->name ); ?>
												<span class="wpam-card-version"><?php echo esc_html( $cpt_slug ); ?></span>
											</h3>
											<span class="wpam-badge wpam-badge-cpt">CPT</span>
											<?php if ( $cpt_obj->public ) : ?>
												<span class="wpam-badge wpam-badge-active"><?php esc_html_e( 'Public', 'qaiyo-access-manager' ); ?></span>
											<?php endif; ?>
											<?php if ( $cpt_obj->show_in_rest ) : ?>
												<span class="wpam-badge wpam-badge-rest">REST</span>
											<?php endif; ?>
											<?php if ( $has_rules ) : ?>
												<span class="wpam-badge wpam-badge-restricted"><?php esc_html_e( 'Restricted', 'qaiyo-access-manager' ); ?></span>
											<?php endif; ?>
										</div>
										<button type="button" class="wpam-toggle-btn" aria-expanded="false">
											<span class="dashicons dashicons-arrow-down-alt2"></span>
										</button>
									</div>

									<div class="wpam-card-body" style="display:none;">
										<?php if ( $cpt_obj->description ) : ?>
											<p class="wpam-card-desc"><?php echo esc_html( $cpt_obj->description ); ?></p>
										<?php endif; ?>

										<div class="wpam-cpt-meta">
											<span><strong>Slug:</strong> <code><?php echo esc_html( $cpt_slug ); ?></code></span>
											<span><strong><?php esc_html_e( 'Singular:', 'qaiyo-access-manager' ); ?></strong> <?php echo esc_html( $cpt_obj->labels->singular_name ); ?></span>
											<?php if ( $cpt_obj->has_archive ) : ?>
												<span><strong><?php esc_html_e( 'Archive:', 'qaiyo-access-manager' ); ?></strong> <?php esc_html_e( 'Yes', 'qaiyo-access-manager' ); ?></span>
											<?php endif; ?>
										</div>

										<?php if ( ! empty( $native_info ) ) : ?>
										<div class="wpam-native-caps">
											<h4><?php esc_html_e( 'Default WordPress Capabilities', 'qaiyo-access-manager' ); ?></h4>
											<div class="wpam-native-caps-grid">
												<?php foreach ( $native_info as $cap_info ) : ?>
													<div class="wpam-native-cap-item">
														<span class="wpam-native-cap-label"><?php echo esc_html( $cap_info['label'] ); ?>:</span>
														<span class="wpam-native-cap-roles"><?php echo esc_html( implode( ', ', $cap_info['roles'] ) ); ?></span>
														<code class="wpam-native-cap-code"><?php echo esc_html( $cap_info['wp_cap'] ); ?></code>
													</div>
												<?php endforeach; ?>
											</div>
										</div>
										<?php endif; ?>

										<div class="wpam-section">
											<h4><?php esc_html_e( 'Role-based access', 'qaiyo-access-manager' ); ?></h4>
											<p class="wpam-hint"><?php esc_html_e( 'If no role is selected, the item remains accessible to everyone.', 'qaiyo-access-manager' ); ?></p>
											<div class="wpam-roles-grid">
												<?php foreach ( $roles as $role_slug => $role_data ) : ?>
													<label class="wpam-role-checkbox">
														<input type="checkbox" class="wpam-role-cb"
															   data-target="<?php echo esc_attr( $cpt_slug ); ?>"
															   value="<?php echo esc_attr( $role_slug ); ?>"
															   <?php checked( in_array( $role_slug, $c_roles, true ) ); ?> />
														<span class="wpam-role-name"><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></span>
													</label>
												<?php endforeach; ?>
											</div>
											<div class="wpam-quick-actions">
												<button type="button" class="button button-small wpam-select-all-roles"><?php esc_html_e( 'Select all', 'qaiyo-access-manager' ); ?></button>
												<button type="button" class="button button-small wpam-deselect-all-roles"><?php esc_html_e( 'Deselect all', 'qaiyo-access-manager' ); ?></button>
											</div>
										</div>

										<div class="wpam-section wpam-user-section">
											<h4><?php esc_html_e( 'User-level override', 'qaiyo-access-manager' ); ?></h4>
											<p class="wpam-hint"><?php esc_html_e( 'Individual allow or deny that overrides role-based rules. Administrators cannot be listed.', 'qaiyo-access-manager' ); ?></p>
											<div class="wpam-user-search-wrap">
												<input type="text" class="wpam-user-search" placeholder="<?php esc_attr_e( 'Search user (name, email, login)...', 'qaiyo-access-manager' ); ?>" autocomplete="off" />
												<div class="wpam-user-search-results"></div>
											</div>
											<div class="wpam-user-rules-list"
												 data-allowed="<?php echo esc_attr( wp_json_encode( $c_users['allowed'] ?? array() ) ); ?>"
												 data-denied="<?php echo esc_attr( wp_json_encode( $c_users['denied'] ?? array() ) ); ?>">
												<?php
												$all_user_ids = array_merge( $c_users['allowed'] ?? array(), $c_users['denied'] ?? array() );
												foreach ( $all_user_ids as $uid ) :
													$u = get_userdata( $uid );
													if ( ! $u ) {
														continue;
													}
													$is_allowed = in_array( $uid, $c_users['allowed'] ?? array(), true );
												?>
													<div class="wpam-user-rule-row" data-user-id="<?php echo esc_attr( $uid ); ?>">
														<span class="wpam-user-rule-info">
															<strong><?php echo esc_html( $u->display_name ); ?></strong>
															<span class="wpam-user-rule-meta"><?php echo esc_html( $u->user_email . ' — ' . implode( ', ', $u->roles ) ); ?></span>
														</span>
														<select class="wpam-user-rule-type">
															<option value="allowed" <?php selected( $is_allowed ); ?>><?php esc_html_e( 'Allowed', 'qaiyo-access-manager' ); ?></option>
															<option value="denied" <?php selected( ! $is_allowed ); ?>><?php esc_html_e( 'Denied', 'qaiyo-access-manager' ); ?></option>
														</select>
														<button type="button" class="button button-small wpam-user-rule-remove" title="<?php esc_attr_e( 'Remove', 'qaiyo-access-manager' ); ?>">&times;</button>
													</div>
												<?php endforeach; ?>
											</div>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<div class="wpam-submit-bar" <?php echo in_array( $active_tab, $hide_toolbar_tabs, true ) ? 'style="display:none;"' : ''; ?>>
					<button type="submit" class="button button-primary button-hero" id="wpam-save-btn">
						<?php esc_html_e( 'Save settings', 'qaiyo-access-manager' ); ?>
					</button>
					<span class="wpam-save-status" id="wpam-save-status"></span>
				</div>
			</form>

			<?php if ( 'matrix' === $active_tab ) : ?>
				<?php
				/**
				 * Pro hook: replace the read-only matrix with an editable one.
				 *
				 * @param bool $handled If true, the default read-only matrix is skipped.
				 */
				$matrix_handled = apply_filters( 'wpam_render_matrix', false, $plugins, $cpts, $roles, $plugin_role_rules, $cpt_role_rules );
				if ( ! $matrix_handled ) :
				?>
				<?php $this->render_matrix_tab( $plugins, $cpts, $roles, $plugin_role_rules, $cpt_role_rules ); ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( 'tools' === $active_tab ) : ?>
				<?php $this->render_tools_tab(); ?>
			<?php endif; ?>

			<?php
			/**
			 * Pro hook: render content for Pro-added tabs (presets, groups, etc.)
			 */
			do_action( 'wpam_render_tab_content', $active_tab, $plugins, $cpts, $roles, $plugin_role_rules, $cpt_role_rules );
			?>

			<div class="wpam-footer">
				<p>
					<?php
					printf(
						/* translators: %s: version number */
						esc_html__( 'Qaiyo Access Manager v%s — Qaiyo by PixelDesigns', 'qaiyo-access-manager' ),
						esc_html( WPAM_VERSION )
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// MATRIX TAB
	// =========================================================================

	private function render_matrix_tab( $plugins, $cpts, $roles, $plugin_role_rules, $cpt_role_rules ) {
		$role_names = array();
		foreach ( $roles as $slug => $data ) {
			$role_names[ $slug ] = translate_user_role( $data['name'] );
		}
		?>
		<div class="wpam-matrix-wrap">

			<h3><?php esc_html_e( 'Plugin access by role', 'qaiyo-access-manager' ); ?></h3>
			<?php if ( empty( $plugins ) ) : ?>
				<p class="wpam-empty"><?php esc_html_e( 'No plugins installed to manage.', 'qaiyo-access-manager' ); ?></p>
			<?php else : ?>
				<div class="wpam-matrix-scroll">
					<table class="wpam-matrix-table">
						<thead>
							<tr>
								<th class="wpam-matrix-item-col"><?php esc_html_e( 'Plugin', 'qaiyo-access-manager' ); ?></th>
								<?php foreach ( $role_names as $rname ) : ?>
									<th class="wpam-matrix-role-col"><?php echo esc_html( $rname ); ?></th>
								<?php endforeach; ?>
								<th class="wpam-matrix-status-col"><?php esc_html_e( 'Status', 'qaiyo-access-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $plugins as $file => $pdata ) :
								$p_roles   = $plugin_role_rules[ $file ] ?? array();
								$has_rules = ! empty( $p_roles );
							?>
								<tr class="<?php echo $has_rules ? 'wpam-matrix-restricted' : ''; ?>">
									<td class="wpam-matrix-item-name">
										<?php echo esc_html( $pdata['Name'] ); ?>
									</td>
									<?php foreach ( array_keys( $role_names ) as $rslug ) : ?>
										<td class="wpam-matrix-cell">
											<?php if ( ! $has_rules ) : ?>
												<span class="wpam-matrix-open" title="<?php esc_attr_e( 'Open', 'qaiyo-access-manager' ); ?>">&#9679;</span>
											<?php elseif ( in_array( $rslug, $p_roles, true ) ) : ?>
												<span class="wpam-matrix-allowed" title="<?php esc_attr_e( 'Allowed', 'qaiyo-access-manager' ); ?>">&#10003;</span>
											<?php else : ?>
												<span class="wpam-matrix-denied" title="<?php esc_attr_e( 'Denied', 'qaiyo-access-manager' ); ?>">&#10005;</span>
											<?php endif; ?>
										</td>
									<?php endforeach; ?>
									<td class="wpam-matrix-cell">
										<?php if ( $has_rules ) : ?>
											<span class="wpam-badge wpam-badge-restricted"><?php esc_html_e( 'Restricted', 'qaiyo-access-manager' ); ?></span>
										<?php else : ?>
											<span class="wpam-badge wpam-badge-active"><?php esc_html_e( 'Open', 'qaiyo-access-manager' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<h3 style="margin-top: 24px;"><?php esc_html_e( 'Content type access by role', 'qaiyo-access-manager' ); ?></h3>
			<?php if ( empty( $cpts ) ) : ?>
				<p class="wpam-empty"><?php esc_html_e( 'No custom post types registered.', 'qaiyo-access-manager' ); ?></p>
			<?php else : ?>
				<div class="wpam-matrix-scroll">
					<table class="wpam-matrix-table">
						<thead>
							<tr>
								<th class="wpam-matrix-item-col"><?php esc_html_e( 'Content Type', 'qaiyo-access-manager' ); ?></th>
								<?php foreach ( $role_names as $rname ) : ?>
									<th class="wpam-matrix-role-col"><?php echo esc_html( $rname ); ?></th>
								<?php endforeach; ?>
								<th class="wpam-matrix-status-col"><?php esc_html_e( 'Status', 'qaiyo-access-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $cpts as $cpt_slug => $cpt_obj ) :
								$c_roles   = $cpt_role_rules[ $cpt_slug ] ?? array();
								$has_rules = ! empty( $c_roles );
							?>
								<tr class="<?php echo $has_rules ? 'wpam-matrix-restricted' : ''; ?>">
									<td class="wpam-matrix-item-name">
										<?php echo esc_html( $cpt_obj->labels->name ); ?>
										<span class="wpam-matrix-slug"><?php echo esc_html( $cpt_slug ); ?></span>
									</td>
									<?php foreach ( array_keys( $role_names ) as $rslug ) : ?>
										<td class="wpam-matrix-cell">
											<?php if ( ! $has_rules ) : ?>
												<span class="wpam-matrix-open" title="<?php esc_attr_e( 'Open', 'qaiyo-access-manager' ); ?>">&#9679;</span>
											<?php elseif ( in_array( $rslug, $c_roles, true ) ) : ?>
												<span class="wpam-matrix-allowed" title="<?php esc_attr_e( 'Allowed', 'qaiyo-access-manager' ); ?>">&#10003;</span>
											<?php else : ?>
												<span class="wpam-matrix-denied" title="<?php esc_attr_e( 'Denied', 'qaiyo-access-manager' ); ?>">&#10005;</span>
											<?php endif; ?>
										</td>
									<?php endforeach; ?>
									<td class="wpam-matrix-cell">
										<?php if ( $has_rules ) : ?>
											<span class="wpam-badge wpam-badge-restricted"><?php esc_html_e( 'Restricted', 'qaiyo-access-manager' ); ?></span>
										<?php else : ?>
											<span class="wpam-badge wpam-badge-active"><?php esc_html_e( 'Open', 'qaiyo-access-manager' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<p class="wpam-matrix-hint">
				<span class="wpam-matrix-allowed">&#10003;</span> = <?php esc_html_e( 'Allowed', 'qaiyo-access-manager' ); ?> &nbsp;
				<span class="wpam-matrix-denied">&#10005;</span> = <?php esc_html_e( 'Denied', 'qaiyo-access-manager' ); ?> &nbsp;
				<span class="wpam-matrix-open">&#9679;</span> = <?php esc_html_e( 'Open (no restriction)', 'qaiyo-access-manager' ); ?>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// TOOLS TAB
	// =========================================================================

	private function render_tools_tab() {
		?>
		<div class="wpam-tools-wrap">
			<div class="wpam-tools-grid">

				<div class="wpam-tool-card">
					<h3>
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Export Settings', 'qaiyo-access-manager' ); ?>
					</h3>
					<p><?php esc_html_e( 'Download all access rules as a JSON file. Use this to create a backup or transfer settings to another site.', 'qaiyo-access-manager' ); ?></p>
					<button type="button" class="button button-primary" id="wpam-export-btn">
						<span class="dashicons dashicons-download" style="margin-top: 4px;"></span>
						<?php esc_html_e( 'Export to JSON', 'qaiyo-access-manager' ); ?>
					</button>
					<span class="wpam-tool-status" id="wpam-export-status"></span>
				</div>

				<div class="wpam-tool-card">
					<h3>
						<span class="dashicons dashicons-upload"></span>
						<?php esc_html_e( 'Import Settings', 'qaiyo-access-manager' ); ?>
					</h3>
					<p><?php esc_html_e( 'Upload a previously exported JSON file to restore or apply access rules. This will overwrite current settings.', 'qaiyo-access-manager' ); ?></p>
					<div class="wpam-import-area">
						<input type="file" id="wpam-import-file" accept=".json" style="display:none;" />
						<label for="wpam-import-file" class="wpam-import-dropzone" id="wpam-import-dropzone">
							<span class="dashicons dashicons-upload"></span>
							<span><?php esc_html_e( 'Choose file or drag & drop', 'qaiyo-access-manager' ); ?></span>
							<span class="wpam-import-filetypes">.json</span>
						</label>
						<div class="wpam-import-file-info" id="wpam-import-file-info" style="display:none;">
							<span class="dashicons dashicons-media-text"></span>
							<span id="wpam-import-filename"></span>
							<button type="button" class="button button-small" id="wpam-import-clear">&times;</button>
						</div>
						<button type="button" class="button button-primary" id="wpam-import-btn" disabled>
							<span class="dashicons dashicons-upload" style="margin-top: 4px;"></span>
							<?php esc_html_e( 'Import', 'qaiyo-access-manager' ); ?>
						</button>
						<span class="wpam-tool-status" id="wpam-import-status"></span>
					</div>
				</div>

			</div>

			<div class="wpam-tool-card wpam-tool-card-full">
				<h3>
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Uninstall behavior', 'qaiyo-access-manager' ); ?>
				</h3>
				<p><?php esc_html_e( 'Choose what happens when you delete this plugin from the Plugins page.', 'qaiyo-access-manager' ); ?></p>
				<label class="wpam-uninstall-toggle">
					<input type="checkbox" id="wpam-delete-data" <?php checked( get_option( 'wpam_delete_data_on_uninstall', false ) ); ?> />
					<span><?php esc_html_e( 'Delete all access rules and settings from the database when the plugin is deleted', 'qaiyo-access-manager' ); ?></span>
				</label>
				<p class="wpam-hint" style="margin-top: 8px;">
					<?php esc_html_e( 'If unchecked (default), your rules are preserved so they are restored if you reinstall the plugin.', 'qaiyo-access-manager' ); ?>
				</p>
			</div>
		</div>
		<?php
	}
}

Wpam_Access_Manager::get_instance();
