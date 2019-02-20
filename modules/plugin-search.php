<?php
/**
 * Module Name: Plugin Search Hints
 * Module Description: Make suggestions when people search the plugin directory for things that Jetpack already does for them.
 * Sort Order: 50
 * Recommendation Order: 1
 * First Introduced: 7.1
 * Requires Connection: Yes
 * Auto Activate: Yes
 * Module Tags: Recommended
 * Feature: Jumpstart
 */

if (
	is_admin() &&
	Jetpack::is_active() &&
	/** This filter is documented in _inc/lib/admin-pages/class.jetpack-react-page.php */
	apply_filters( 'jetpack_show_promotions', true )
) {
	add_action( 'jetpack_modules_loaded', array( 'Jetpack_Plugin_Search', 'init' ) );
}

// Register endpoints when WP REST API is initialized.
add_action( 'rest_api_init', array( 'Jetpack_Plugin_Search', 'register_endpoints' ) );

/**
 * Class that includes cards in the plugin search results when users enter terms that match some Jetpack feature.
 * Card can be dismissed and includes a title, description, button to enable the feature and a link for more information.
 *
 * @since 7.1.0
 */
class Jetpack_Plugin_Search {

	static $slug = 'jetpack-plugin-search';

	public static function init() {
		static $instance = null;

		if ( ! $instance ) {
			jetpack_require_lib( 'tracks/client' );
			$instance = new Jetpack_Plugin_Search();
		}

		return $instance;
	}

	public function __construct() {
		add_action( 'current_screen', array( $this, 'start' ) );
	}

	/**
	 * Add actions and filters only if this is the plugin installation screen and it's the first page.
	 *
	 * @param object $screen
	 *
	 * @since 7.1.0
	 */
	public function start( $screen ) {
		if ( 'plugin-install' === $screen->base && ( ! isset( $_GET['paged'] ) || 1 == $_GET['paged'] ) ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'load_plugins_search_script' ) );
			add_filter( 'plugins_api_result', array( $this, 'inject_jetpack_module_suggestion' ), 10, 3 );
			add_filter( 'self_admin_url', array( $this, 'plugin_details' ) );
			add_filter( 'plugin_install_action_links', array( $this, 'insert_module_related_links' ), 10, 2 );
		}
	}

	/**
	 * Modify URL used to fetch to plugin information so it pulls Jetpack plugin page.
	 *
	 * @param string $url URL to load in dialog pulling the plugin page from wporg.
	 *
	 * @since 7.1.0
	 *
	 * @return string The URL with 'jetpack' instead of 'jetpack-plugin-search'.
	 */
	public function plugin_details( $url ) {
		return false !== stripos( $url, 'tab=plugin-information&amp;plugin=' . self::$slug )
			? 'plugin-install.php?tab=plugin-information&amp;plugin=jetpack&amp;TB_iframe=true&amp;width=600&amp;height=550'
			: $url;
	}

	/**
	 * Register REST API endpoints.
	 *
	 * @since 7.1.0
	 */
	public static function register_endpoints() {
		register_rest_route( 'jetpack/v4', '/hints', array(
			'methods' => WP_REST_Server::EDITABLE,
			'callback' => __CLASS__ . '::dismiss',
			'permission_callback' => __CLASS__ . '::can_request',
			'args' => array(
				'hint' => array(
					'default'           => '',
					'type'              => 'string',
					'required'          => true,
					'validate_callback' => __CLASS__ . '::is_hint_id',
				),
			)
		) );
	}

	/**
	 * A WordPress REST API permission callback method that accepts a request object and
	 * decides if the current user has enough privileges to act.
	 *
	 * @since 7.1.0
	 *
	 * @return bool does a current user have enough privileges.
	 */
	public static function can_request() {
		return current_user_can( 'jetpack_admin_page' );
	}

	/**
	 * Validates that the ID of the hint to dismiss is a string.
	 *
	 * @since 7.1.0
	 *
	 * @param string|bool $value Value to check.
	 * @param WP_REST_Request $request The request sent to the WP REST API.
	 * @param string $param Name of the parameter passed to endpoint holding $value.
	 *
	 * @return bool|WP_Error
	 */
	public static function is_hint_id( $value, $request, $param ) {
		return in_array( $value, Jetpack::get_available_modules(), true )
			? true
			: new WP_Error( 'invalid_param', sprintf( esc_html__( '%s must be an alphanumeric string.', 'jetpack' ), $param ) );
	}

	/**
	 * A WordPress REST API callback method that accepts a request object and decides what to do with it.
	 *
	 * @param WP_REST_Request $request {
	 *     Array of parameters received by request.
	 *
	 *     @type string $hint Slug of card to dismiss.
	 * }
	 *
	 * @since 7.1.0
	 *
	 * @return bool|array|WP_Error a resulting value or object, or an error.
	 */
	public static function dismiss( WP_REST_Request $request ) {
		return self::add_to_dismissed_hints( $request['hint'] )
			? rest_ensure_response( array( 'code' => 'success' ) )
			: new WP_Error( 'not_dismissed', esc_html__( 'The card could not be dismissed', 'jetpack' ), array( 'status' => 400 ) );
	}

	/**
	 * Returns a list of previously dismissed hints.
	 *
	 * @since 7.1.0
	 *
	 * @return array List of dismissed hints.
	 */
	protected static function get_dismissed_hints() {
		$dismissed_hints = Jetpack_Options::get_option( 'dismissed_hints' );
		return isset( $dismissed_hints ) && is_array( $dismissed_hints )
			? $dismissed_hints
			: array();
	}

	/**
	 * Save the hint in the list of dismissed hints.
	 *
	 * @since 7.1.0
	 *
	 * @param string $hint The hint id, which is a Jetpack module slug.
	 *
	 * @return bool Whether the card was added to the list and hence dismissed.
	 */
	protected static function add_to_dismissed_hints( $hint ) {
		return Jetpack_Options::update_option( 'dismissed_hints', array_merge( self::get_dismissed_hints(), array( $hint ) ) );
	}

	/**
	 * Checks that the module slug passed, the hint, is not in the list of previously dismissed hints.
	 *
	 * @since 7.1.0
	 *
	 * @param string $hint The hint id, which is a Jetpack module slug.
	 *
	 * @return bool True if $hint wasn't already dismissed.
	 */
	protected function is_not_dismissed( $hint ) {
		return ! in_array( $hint, $this->get_dismissed_hints(), true );
	}

	public function load_plugins_search_script() {
		wp_enqueue_script( self::$slug, plugins_url( 'modules/plugin-search/plugin-search.js', JETPACK__PLUGIN_FILE ), array( 'jquery' ), JETPACK__VERSION, true );
		wp_localize_script(
			self::$slug,
			'jetpackPluginSearch',
			array(
				'nonce'                => wp_create_nonce( 'wp_rest' ),
				'base_rest_url'        => rest_url( '/jetpack/v4' ),
				'manageSettingsString' => esc_html__( 'Module Settings', 'jetpack' ),
				'activateModuleString' => esc_html__( 'Activate Module', 'jetpack' ),
				'activatedString'      => esc_html__( 'Activated', 'jetpack' ),
				'activatingString'     => esc_html__( 'Activating', 'jetpack' ),
				'logo' => 'https://ps.w.org/jetpack/assets/icon.svg?rev=1791404',
				'legend' => esc_html__(
					'Jetpack is trusted by millions to help secure and speed up their WordPress site. Make the most of it today.',
					'jetpack'
				),
				'hideText' => esc_html__( 'Hide this suggestion', 'jetpack' ),
			)
		);

		wp_enqueue_style( self::$slug, plugins_url( 'modules/plugin-search/plugin-search.css', JETPACK__PLUGIN_FILE ) );
	}

	/**
	 * Get the plugin repo's data for Jetpack to populate the fields with.
	 *
	 * @return array|mixed|object|WP_Error
	 */
	public static function get_jetpack_plugin_data() {
		$data = get_transient( 'jetpack_plugin_data' );

		if ( false === $data || is_wp_error( $data ) ) {
			include_once( ABSPATH . 'wp-admin/includes/plugin-install.php' );
			$data = plugins_api( 'plugin_information', array(
				'slug' => 'jetpack',
				'is_ssl' => is_ssl(),
				'fields' => array(
					'banners' => true,
					'reviews' => true,
					'active_installs' => true,
					'versions' => false,
					'sections' => false,
				),
			) );
			set_transient( 'jetpack_plugin_data', $data, DAY_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Intercept the plugins API response and add in an appropriate card for Jetpack
	 */
	public function inject_jetpack_module_suggestion( $result, $action, $args ) {
		require_once JETPACK__PLUGIN_DIR . 'class.jetpack-admin.php';
		$jetpack_modules_list = Jetpack_Admin::init()->get_modules();

		// Never suggest this module.
		unset( $jetpack_modules_list['plugin-search'] );

		// Looks like a search query; it's matching time
		if ( ! empty( $args->search ) ) {
			$matching_module = null;

			// Lowercase, trim, remove punctuation/special chars, decode url, remove 'jetpack'
			$this->track_search_term( $args->search );
			$normalized_term = $this->sanitize_search_term( $args->search );

			usort( $jetpack_modules_list, array( $this, 'by_sorting_option' ) );

			// Try to match a passed search term with module's search terms
			foreach ( $jetpack_modules_list as $module_slug => $module_opts ) {
				$search_terms = strtolower( $module_opts['search_terms'] . ', ' . $module_opts['name'] );
				$terms_array  = explode( ', ', $search_terms );
				if ( in_array( $normalized_term, $terms_array ) ) {
					$matching_module = $module_slug;
					break;
				}
			}

			if ( isset( $matching_module ) && $this->is_not_dismissed( $jetpack_modules_list[ $matching_module ]['module'] ) ) {
				$inject = (array) self::get_jetpack_plugin_data();

				$overrides = array(
					'plugin-search' => true, // Helps to determine if that an injected card.
					'name' => sprintf(       // Supplement name/description so that they clearly indicate this was added.
						_x( 'Jetpack: %s', 'Jetpack: Module Name', 'jetpack' ),
						$jetpack_modules_list[ $matching_module ]['name']
					),
					'short_description' => $jetpack_modules_list[ $matching_module ]['short_description'],
					'requires_connection' => (bool) $jetpack_modules_list[ $matching_module ]['requires_connection'],
					'slug'    => self::$slug,
					'version' => JETPACK__VERSION,
					'icons' => array(
						'1x'  => 'https://ps.w.org/jetpack/assets/icon.svg?rev=1791404',
						'2x'  => 'https://ps.w.org/jetpack/assets/icon-256x256.png?rev=1791404',
						'svg' => 'https://ps.w.org/jetpack/assets/icon.svg?rev=1791404',
					),
				);

				// Splice in the base module data
				$inject = array_merge( $inject, $jetpack_modules_list[ $matching_module ], $overrides );

				// Add it to the top of the list
				array_unshift( $result->plugins, $inject );
			}
		}
		return $result;
	}

	/**
	 * Take a raw search query and return something a bit more standardized and
	 * easy to work with.
	 *
	 * @param  String $term The raw search term
	 * @return String A simplified/sanitized version.
	 */
	private function sanitize_search_term( $term ) {
		$term = strtolower( urldecode( $term ) );

		// remove non-alpha/space chars.
		$term = preg_replace( '/[^a-z ]/', '', $term );

		// remove strings that don't help matches.
		$term = trim( str_replace( array( 'jetpack', 'jp', 'free', 'wordpress' ), '', $term ) );

		return $term;
	}

	/**
	 * Tracks every search term used in plugins search as 'jetpack_wpa_plugin_search_term'
	 *
	 * @param String $term The raw search term.
	 * @return true|WP_Error true for success, WP_Error if error occurred.
	 */
	private function track_search_term( $term ) {
		return JetpackTracking::record_user_event( 'wpa_plugin_search_term', array( 'search_term' => $term ) );
	}

	/**
	 * Callback function to sort the array of modules by the sort option.
	 */
	private function by_sorting_option( $m1, $m2 ) {
		return $m1['sort'] - $m2['sort'];
	}

	/**
	 * Put some more appropriate links on our custom result cards.
	 */
	public function insert_module_related_links( $links, $plugin ) {
		if ( self::$slug !== $plugin['slug'] ) {
			return $links;
		}

		// By the time this filter is added, self_admin_url was already applied and we don't need it anymore.
		remove_filter( 'self_admin_url', array( $this, 'plugin_details' ) );

		$links = array();

		// Jetpack installed, active, feature not enabled; prompt to enable.
		if (
			(
				Jetpack::is_active() ||
				(
					Jetpack::is_development_mode() &&
					! $plugin[ 'requires_connection' ]
				)
			) &&
			current_user_can( 'jetpack_activate_modules' ) &&
			! Jetpack::is_module_active( $plugin['module'] )
		) {
			$links = array(
				'<button id="plugin-select-activate" class="button activate-module-now" data-module="' . esc_attr( $plugin['module'] ) . '" data-configure-url="' . esc_url( Jetpack::module_configuration_url( $plugin['module'] ) ) . '"> ' . esc_html__( 'Enable', 'jetpack' ) . '</button>',
			);
			// Jetpack installed, active, feature enabled; link to settings.
		} elseif (
			! empty( $plugin['configure_url'] ) &&
			current_user_can( 'jetpack_configure_modules' ) &&
			Jetpack::is_module_active( $plugin['module'] ) &&
			/** This filter is documented in class.jetpack-admin.php */
			apply_filters( 'jetpack_module_configurable_' . $plugin['module'], false )
		) {
			$links = array(
				'<a id="plugin-select-settings" href="' . esc_url( $plugin['configure_url'] ) . '">' . esc_html__( 'Module Settings', 'jetpack' ) . '</a>',
			);
		}

		// Adds link pointing to a relevant doc page in jetpack.com
		if ( ! empty( $plugin['learn_more_button'] ) ) {
			$links[] = '<a href="' . esc_url( $plugin['learn_more_button'] ) . '" target="_blank">' . esc_html__( 'Learn more', 'jetpack' ) . '</a>';
		}

		// Dismiss link
		$links[] = '<a class="jetpack-plugin-search__dismiss" data-module="' . esc_attr( $plugin['module'] ) . '">' .
		           esc_html__( 'Hide this suggestion', 'jetpack' ) .
		           '</a>';

		return $links;
	}


}
