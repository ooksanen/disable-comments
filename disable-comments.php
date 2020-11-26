<?php

/**
 * Plugin Name: Disable Comments
 * Plugin URI: https://wordpress.org/plugins/disable-comments/
 * Description: Allows administrators to globally disable comments on their site. Comments can be disabled according to post type. You could bulk delete comments using Tools.
 * Version: 2.0.0
 * Author: WPDeveloper
 * Author URI: https://wpdeveloper.net
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: disable-comments
 * Domain Path: /languages/
 *
 * @package Disable_Comments
 */

if (!defined('ABSPATH')) {
	exit;
}

class Disable_Comments
{
	const DB_VERSION         = 6;
	private static $instance = null;
	private $options;
	public  $networkactive;
	private $modified_types = array();

	public static function get_instance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	function __construct()
	{
		define('DC_VERSION', '2.0.0');
		define('DC_PLUGIN_SLUG', 'disable_comments_settings');
		define('DC_PLUGIN_ROOT_PATH', dirname(__FILE__));
		define('DC_PLUGIN_VIEWS_PATH', DC_PLUGIN_ROOT_PATH . '/views/');
		define('DC_PLUGIN_ROOT_URI', plugins_url("/", __FILE__));
		define('DC_ASSETS_URI', DC_PLUGIN_ROOT_URI . 'assets/');

		register_activation_hook(__FILE__, array($this, 'activate'));
		add_action('wp_loaded', array($this, 'plugin_redirect'));

		// save settings
		add_action('wp_ajax_disable_comments_save_settings', array($this, 'disable_comments_settings'));
		add_action('wp_ajax_disable_comments_delete_comments', array($this, 'delete_comments_settings'));

		add_action( 'wp_ajax_optin_wizard_action_disable_comments', array( $this, 'wizard_action' ) );

		// Including cli.php
		if (defined('WP_CLI') && WP_CLI) {
			add_action('init', array($this, 'enable_cli'), 9999);
		}

		// are we network activated?
		$this->networkactive = (is_multisite() && array_key_exists(plugin_basename(__FILE__), (array) get_site_option('active_sitewide_plugins')));
		$this->is_CLI = defined('WP_CLI') && WP_CLI;

		// Load options.
		if ($this->networkactive) {
			$this->options = get_site_option('disable_comments_options', array());
		} else {
			$this->options = get_option('disable_comments_options', array());
		}

		// If it looks like first run, check compat.
		if (empty($this->options)) {
			$this->check_compatibility();
		}

		// Upgrade DB if necessary.
		$this->check_db_upgrades();

		$this->init_filters();

		$this->start_plugin_usage_tracking();
	}
	/**
	 * Enable CLI
	 * @since 2.0.0
	 */
	public function enable_cli(){
		require_once DC_PLUGIN_ROOT_PATH . "/includes/cli.php";
		new Disable_Comment_Command($this);
	}
	/**
	 * Optin Added
	 *
	 * @since 2.0.0.
	 */
	public function wizard_action(){
		if( $this->tracker instanceof DisableComments_Plugin_Tracker ) {
			$allow_tracking = get_option( 'wpins_allow_tracking', [] );
			update_option('wpins_allow_tracking', array_merge( $allow_tracking, ['disable-comments' => 'disable-comments'] ));
			$this->tracker->force_tracking();
			$this->tracker->update_block_notice( 'disable-comments' );
		}
	}

	public function start_plugin_usage_tracking()
	{
		if (!class_exists('DisableComments_Plugin_Tracker')) {
			include_once(DC_PLUGIN_ROOT_PATH . '/includes/class-plugin-usage-tracker.php');
		}
		$tracker = $this->tracker = DisableComments_Plugin_Tracker::get_instance(__FILE__, [
			'opt_in'       => true,
			'goodbye_form' => true,
			'item_id'      => 'b0112c9030af6ba53de4'
		]);
		$tracker->set_notice_options(array(
			'notice' => __('Want to help make Disable Comments even better?', 'disable-comments-on-attachments'),
			'extra_notice' => __('We collect non-sensitive diagnostic data and plugin usage information. Your site URL, WordPress & PHP version, plugins & themes and email address to send you the discount coupon. This data lets us make sure this plugin always stays compatible with the most popular plugins and themes. No spam, I promise.', 'disable-comments-on-attachments'),
		));
		$tracker->init();
	}

	private function check_compatibility()
	{
		if (version_compare($GLOBALS['wp_version'], '4.7', '<')) {
			require_once(ABSPATH . 'wp-admin/includes/plugin.php');
			deactivate_plugins(__FILE__);
			if (isset($_GET['action']) && ($_GET['action'] == 'activate' || $_GET['action'] == 'error_scrape')) {
				exit(sprintf(__('Disable Comments requires WordPress version %s or greater.', 'disable-comments'), '4.7'));
			}
		}
	}

	private function check_db_upgrades()
	{
		$old_ver = isset($this->options['db_version']) ? $this->options['db_version'] : 0;
		if ($old_ver < self::DB_VERSION) {
			if ($old_ver < 2) {
				// upgrade options from version 0.2.1 or earlier to 0.3.
				$this->options['disabled_post_types'] = get_option('disable_comments_post_types', array());
				delete_option('disable_comments_post_types');
			}
			if ($old_ver < 5) {
				// simple is beautiful - remove multiple settings in favour of one.
				$this->options['remove_everywhere'] = isset($this->options['remove_admin_menu_comments']) ? $this->options['remove_admin_menu_comments'] : false;
				foreach (array('remove_admin_menu_comments', 'remove_admin_bar_comments', 'remove_recent_comments', 'remove_discussion', 'remove_rc_widget') as $v) {
					unset($this->options[$v]);
				}
			}

			foreach (array('remove_everywhere', 'extra_post_types') as $v) {
				if (!isset($this->options[$v])) {
					$this->options[$v] = false;
				}
			}

			$this->options['db_version'] = self::DB_VERSION;
			$this->update_options();
		}
	}

	private function update_options()
	{
		if ($this->networkactive) {
			update_site_option('disable_comments_options', $this->options);
		} else {
			update_option('disable_comments_options', $this->options);
		}
	}

	/**
	 * Get an array of disabled post type.
	 */
	public function get_disabled_post_types()
	{
		$types = $this->options['disabled_post_types'];
		// Not all extra_post_types might be registered on this particular site.
		if ($this->networkactive) {
			foreach ((array) $this->options['extra_post_types'] as $extra) {
				if (post_type_exists($extra)) {
					$types[] = $extra;
				}
			}
		}
		return $types;
	}

	/**
	 * Check whether comments have been disabled on a given post type.
	 */
	private function is_post_type_disabled($type)
	{
		return in_array($type, $this->get_disabled_post_types());
	}

	private function init_filters()
	{
		// These need to happen now.
		if ($this->options['remove_everywhere']) {
			add_action('widgets_init', array($this, 'disable_rc_widget'));
			add_filter('wp_headers', array($this, 'filter_wp_headers'));
			add_action('template_redirect', array($this, 'filter_query'), 9);   // before redirect_canonical.

			// Admin bar filtering has to happen here since WP 3.6.
			add_action('template_redirect', array($this, 'filter_admin_bar'));
			add_action('admin_init', array($this, 'filter_admin_bar'));

			// Disable Comments REST API Endpoint
			add_filter('rest_endpoints', array($this, 'filter_rest_endpoints'));
		}

		// remove create comment via xmlrpc
		if (isset($this->options['remove_xmlrpc_comments']) && intval($this->options['remove_xmlrpc_comments']) === 1) {
			add_filter('xmlrpc_methods', array($this, 'disable_xmlrc_comments'));
		}
		// rest API Comment Block
		if (isset($this->options['remove_rest_API_comments']) && intval($this->options['remove_rest_API_comments']) === 1) {
			add_filter('rest_pre_insert_comment', array($this, 'disable_rest_API_comments'));
		}

		// These can happen later.
		add_action('plugins_loaded', array($this, 'register_text_domain'));
		add_action('wp_loaded', array($this, 'init_wploaded_filters'));
		// Disable "Latest comments" block in Gutenberg.
		add_action('enqueue_block_editor_assets', array($this, 'filter_gutenberg_blocks'));
		// settings page assets
		add_action('admin_enqueue_scripts', array($this, 'settings_page_assets'));
	}

	/**
	 * Do stuff upon plugin activation
	 *
	 * @return void
	 */
	public function activate()
	{
		update_option('dc_do_activation_redirect', true);
	}

	public function register_text_domain()
	{
		load_plugin_textdomain('disable-comments', false, dirname(plugin_basename(__FILE__)) . '/languages');
	}

	public function init_wploaded_filters()
	{
		$disabled_post_types = $this->get_disabled_post_types();
		if (!empty($disabled_post_types)) {
			foreach ($disabled_post_types as $type) {
				// we need to know what native support was for later.
				if (post_type_supports($type, 'comments')) {
					$this->modified_types[] = $type;
					remove_post_type_support($type, 'comments');
					remove_post_type_support($type, 'trackbacks');
				}
			}
			add_filter('comments_array', array($this, 'filter_existing_comments'), 20, 2);
			add_filter('comments_open', array($this, 'filter_comment_status'), 20, 2);
			add_filter('pings_open', array($this, 'filter_comment_status'), 20, 2);
			add_filter('get_comments_number', array($this, 'filter_comments_number'), 20, 2);
		} elseif (is_admin() && !$this->options['remove_everywhere']) {
			/**
			 * It is possible that $disabled_post_types is empty if other
			 * plugins have disabled comments. Hence we also check for
			 * remove_everywhere. If you still get a warning you probably
			 * shouldn't be using this plugin.
			 */
			add_action('all_admin_notices', array($this, 'setup_notice'));
		}

		// Filters for the admin only.
		if (is_admin()) {
			if ($this->networkactive) {
				add_action('network_admin_menu', array($this, 'settings_menu'));
				add_action('network_admin_menu', array($this, 'tools_menu'));
				add_filter('network_admin_plugin_action_links', array($this, 'plugin_actions_links'), 10, 2);
			} else {
				add_action('admin_menu', array($this, 'settings_menu'));
				add_action('admin_menu', array($this, 'tools_menu'));
				add_filter('plugin_action_links', array($this, 'plugin_actions_links'), 10, 2);
				if (is_multisite()) {    // We're on a multisite setup, but the plugin isn't network activated.
					register_deactivation_hook(__FILE__, array($this, 'single_site_deactivate'));
				}
			}
			add_action('admin_notices', array($this, 'discussion_notice'));
			add_filter('plugin_row_meta', array($this, 'set_plugin_meta'), 10, 2);

			if ($this->options['remove_everywhere']) {
				add_action('admin_menu', array($this, 'filter_admin_menu'), 9999);  // do this as late as possible.
				add_action('admin_print_styles-index.php', array($this, 'admin_css'));
				add_action('admin_print_styles-profile.php', array($this, 'admin_css'));
				add_action('wp_dashboard_setup', array($this, 'filter_dashboard'));
				add_filter('pre_option_default_pingback_flag', '__return_zero');
			}
		}
		// Filters for front end only.
		else {
			add_action('template_redirect', array($this, 'check_comment_template'));

			if ($this->options['remove_everywhere']) {
				add_filter('feed_links_show_comments_feed', '__return_false');
			}
		}
	}

	public function plugin_redirect()
	{
		if (get_option('dc_do_activation_redirect', false)) {
			delete_option('dc_do_activation_redirect');

			if( get_option('dc_setup_screen_seen', false ) ) {
				wp_safe_redirect(admin_url('options-general.php?page=' . DC_PLUGIN_SLUG ));
			} else {
				wp_safe_redirect(admin_url('admin.php?page=' . DC_PLUGIN_SLUG . '_setup'));
			}
			exit;
		}
	}

	/**
	 * Replace the theme's comment template with a blank one.
	 * To prevent this, define DISABLE_COMMENTS_REMOVE_COMMENTS_TEMPLATE
	 * and set it to True
	 */
	public function check_comment_template()
	{
		if (is_singular() && ($this->options['remove_everywhere'] || $this->is_post_type_disabled(get_post_type()))) {
			if (!defined('DISABLE_COMMENTS_REMOVE_COMMENTS_TEMPLATE') || DISABLE_COMMENTS_REMOVE_COMMENTS_TEMPLATE == true) {
				// Kill the comments template.
				add_filter('comments_template', array($this, 'dummy_comments_template'), 20);
			}
			// Remove comment-reply script for themes that include it indiscriminately.
			wp_deregister_script('comment-reply');
			// feed_links_extra inserts a comments RSS link.
			remove_action('wp_head', 'feed_links_extra', 3);
		}
	}

	public function dummy_comments_template()
	{
		return dirname(__FILE__) . '/views/comments.php';
	}


	/**
	 * Remove the X-Pingback HTTP header
	 */
	public function filter_wp_headers($headers)
	{
		unset($headers['X-Pingback']);
		return $headers;
	}

	/**
	 * remove method wp.newComment
	 */
	public function disable_xmlrc_comments($methods)
	{
		unset($methods['wp.newComment']);
		return $methods;
	}

	public function disable_rest_API_comments($prepared_comment, $request)
	{
		return;
	}

	/**
	 * Issue a 403 for all comment feed requests.
	 */
	public function filter_query()
	{
		if (is_comment_feed()) {
			wp_die(__('Comments are closed.', 'disable-comments'), '', array('response' => 403));
		}
	}

	/**
	 * Remove comment links from the admin bar.
	 */
	public function filter_admin_bar()
	{
		if (is_admin_bar_showing()) {
			// Remove comments links from admin bar.
			remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
			if (is_multisite()) {
				add_action('admin_bar_menu', array($this, 'remove_network_comment_links'), 500);
			}
		}
	}

	/**
	 * Remove the comments endpoint for the REST API
	 */
	public function filter_rest_endpoints($endpoints)
	{
		unset($endpoints['comments']);
		return $endpoints;
	}

	/**
	 * Determines if scripts should be enqueued
	 */
	public function filter_gutenberg_blocks($hook)
	{
		global $post;

		if ($this->options['remove_everywhere'] || (isset($post->post_type) && in_array($post->post_type, $this->get_disabled_post_types(), true))) {
			return $this->disable_comments_script();
		}
	}

	/**
	 * Enqueues scripts
	 */
	public function disable_comments_script()
	{
		wp_enqueue_script('disable-comments-gutenberg', plugin_dir_url(__FILE__) . 'assets/js/disable-comments.js', array(), false, true);
	}

	/**
	 * Enqueues Scripts for Settings Page
	 */
	public function settings_page_assets($hook_suffix)
	{
		if (
			$hook_suffix === 'settings_page_' . DC_PLUGIN_SLUG ||
			$hook_suffix === 'options-general_' . DC_PLUGIN_SLUG ||
			$hook_suffix === 'admin_page_' . DC_PLUGIN_SLUG . '_setup'
		) {
			// css
			wp_enqueue_style('sweetalert2',  DC_ASSETS_URI . 'css/sweetalert2.min.css', [], false);
			wp_enqueue_style('disable-comments-style',  DC_ASSETS_URI . 'css/style.css', [], false);
			// js
			wp_enqueue_script('sweetalert2', DC_ASSETS_URI . 'js/sweetalert2.all.min.js', array('jquery'), false, true);
			wp_enqueue_script('disable-comments-scripts', DC_ASSETS_URI . 'js/disable-comments-settings-scripts.js', array('jquery'), false, true);
			wp_localize_script(
				'disable-comments-scripts',
				'disableCommentsObj',
				array(
					'save_action' => 'disable_comments_save_settings',
					'delete_action' => 'disable_comments_delete_comments',
					'settings_URI' => $this->settings_page_url(),
					'_nonce' => wp_create_nonce('disable_comments_save_settings')
				)
			);
		}
	}

	/**
	 * Remove comment links from the admin bar in a multisite network.
	 */
	public function remove_network_comment_links($wp_admin_bar)
	{
		if ($this->networkactive && is_user_logged_in()) {
			foreach ((array) $wp_admin_bar->user->blogs as $blog) {
				$wp_admin_bar->remove_menu('blog-' . $blog->userblog_id . '-c');
			}
		} else {
			// We have no way to know whether the plugin is active on other sites, so only remove this one.
			$wp_admin_bar->remove_menu('blog-' . get_current_blog_id() . '-c');
		}
	}

	public function discussion_notice()
	{
		$disabled_post_types = $this->get_disabled_post_types();
		if (get_current_screen()->id == 'options-discussion' && !empty($disabled_post_types)) {
			$names = array();
			foreach ($disabled_post_types as $type) {
				$names[$type] = get_post_type_object($type)->labels->name;
			}

			echo '<div class="notice notice-warning"><p>' . sprintf(__('Note: The <em>Disable Comments</em> plugin is currently active, and comments are completely disabled on: %s. Many of the settings below will not be applicable for those post types.', 'disable-comments'), implode(__(', ', 'disable-comments'), $names)) . '</p></div>';
		}
	}

	/**
	 * Return context-aware settings page URL
	 */
	private function settings_page_url()
	{
		$base = $this->networkactive ? network_admin_url('settings.php') : admin_url('options-general.php');
		return add_query_arg('page', DC_PLUGIN_SLUG, $base);
	}

	/**
	 * Return context-aware tools page URL
	 */
	private function tools_page_url()
	{
		$base = $this->networkactive ? network_admin_url('settings.php') : admin_url('tools.php');
		return add_query_arg('page', 'disable_comments_tools', $base);
	}


	public function setup_notice()
	{
		if (strpos(get_current_screen()->id, 'settings_page_disable_comments_settings') === 0) {
			return;
		}
		$hascaps = $this->networkactive ? is_network_admin() && current_user_can('manage_network_plugins') : current_user_can('manage_options');
		if ($hascaps) {
			echo '<div class="updated fade"><p>' . sprintf(__('The <em>Disable Comments</em> plugin is active, but isn\'t configured to do anything yet. Visit the <a href="%s">configuration page</a> to choose which post types to disable comments on.', 'disable-comments'), esc_attr($this->settings_page_url())) . '</p></div>';
		}
	}

	public function filter_admin_menu()
	{
		global $pagenow;

		if ($pagenow == 'comment.php' || $pagenow == 'edit-comments.php') {
			wp_die(__('Comments are closed.', 'disable-comments'), '', array('response' => 403));
		}

		remove_menu_page('edit-comments.php');

		if (!$this->discussion_settings_allowed()) {
			if ($pagenow == 'options-discussion.php') {
				wp_die(__('Comments are closed.', 'disable-comments'), '', array('response' => 403));
			}

			remove_submenu_page('options-general.php', 'options-discussion.php');
		}
	}

	public function filter_dashboard()
	{
		remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
	}

	public function admin_css()
	{
		echo '<style>
			#dashboard_right_now .comment-count,
			#dashboard_right_now .comment-mod-count,
			#latest-comments,
			#welcome-panel .welcome-comments,
			.user-comment-shortcuts-wrap {
				display: none !important;
			}
		</style>';
	}

	public function filter_existing_comments($comments, $post_id)
	{
		$post = get_post($post_id);
		return ($this->options['remove_everywhere'] || $this->is_post_type_disabled($post->post_type)  ? array() : $comments);
	}

	public function filter_comment_status($open, $post_id)
	{
		$post = get_post($post_id);
		return ($this->options['remove_everywhere'] || $this->is_post_type_disabled($post->post_type) ? false : $open);
	}

	public function filter_comments_number($count, $post_id)
	{
		$post = get_post($post_id);
		return ($this->options['remove_everywhere'] || $this->is_post_type_disabled($post->post_type) ? 0 : $count);
	}

	public function disable_rc_widget()
	{
		unregister_widget('WP_Widget_Recent_Comments');
		/**
		 * The widget has added a style action when it was constructed - which will
		 * still fire even if we now unregister the widget... so filter that out
		 */
		add_filter('show_recent_comments_widget_style', '__return_false');
	}

	public function set_plugin_meta($links, $file)
	{
		static $plugin;
		$plugin = plugin_basename(__FILE__);
		if ($file == $plugin) {
			$links[] = '<a href="https://github.com/WPDevelopers/disable-comments">GitHub</a>';
		}
		return $links;
	}

	/**
	 * Add links to Settings page
	 */
	public function plugin_actions_links($links, $file)
	{
		static $plugin;
		$plugin = plugin_basename(__FILE__);
		if ($file == $plugin && current_user_can('manage_options')) {
			array_unshift(
				$links,
				sprintf('<a href="%s">%s</a>', esc_attr($this->settings_page_url()), __('Settings', 'disable-comments')),
				sprintf('<a href="%s">%s</a>', esc_attr($this->tools_page_url()), __('Tools', 'disable-comments'))
			);
		}

		return $links;
	}

	public function settings_menu()
	{
		$title = _x('Disable Comments', 'settings menu title', 'disable-comments');
		if ($this->networkactive) {
			add_submenu_page('settings.php', $title, $title, 'manage_network_plugins', DC_PLUGIN_SLUG, array($this, 'settings_page'));
		} else {
			add_submenu_page('options-general.php', $title, $title, 'manage_options', DC_PLUGIN_SLUG, array($this, 'settings_page'));
		}
		add_submenu_page(
			null,
			$title,
			$title,
			'manage_options',
			DC_PLUGIN_SLUG . '_setup',
			array($this, 'setup_settings_page')
		);
	}
	public function tools_menu()
	{
		$title = __('Delete Comments', 'disable-comments');
		$hook = '';
		if ($this->networkactive) {
			$hook = add_submenu_page('settings.php', $title, $title, 'manage_network_plugins', 'disable_comments_tools', array($this, 'tools_page'));
		} else {
			$hook = add_submenu_page('tools.php', $title, $title, 'manage_options', 'disable_comments_tools', array($this, 'tools_page'));
		}
		add_action('load-' . $hook, array($this, 'redirectToMainSettingsPage'));
	}

	public function redirectToMainSettingsPage()
	{
		if ($this->networkactive) {
			wp_redirect(admin_url('settings.php?page=' . DC_PLUGIN_SLUG . '#delete'));
		} else {
			wp_redirect(admin_url('options-general.php?page=' . DC_PLUGIN_SLUG . '#delete'));
		}
	}

	public function get_all_comments_number()
	{
		global $wpdb;
		return $wpdb->get_var("SELECT count(comment_id) from $wpdb->comments");
	}

	public function get_all_comment_types()
	{
		global $wpdb;
		$commenttypes = array();
		$commenttypes_query = $wpdb->get_results("SELECT DISTINCT comment_type FROM $wpdb->comments", ARRAY_A);
		if (!empty($commenttypes_query) && is_array($commenttypes_query)) {
			foreach ($commenttypes_query as $entry) {
				$value = $entry['comment_type'];
				if ('' === $value) {
					$commenttypes['default'] = __('Default (no type)', 'disable-comments');
				} else {
					$commenttypes[$value] = ucwords(str_replace('_', ' ', $value)) . ' (' . $value . ')';
				}
			}
		}
		return $commenttypes;
	}

	public function get_all_post_types()
	{
		$typeargs = array('public' => true);
		if ($this->networkactive) {
			$typeargs['_builtin'] = true;   // stick to known types for network.
		}
		$types = get_post_types($typeargs, 'objects');
		foreach (array_keys($types) as $type) {
			if (!in_array($type, $this->modified_types) && !post_type_supports($type, 'comments')) {   // the type doesn't support comments anyway.
				unset($types[$type]);
			}
		}
		return $types;
	}

	public function tools_page()
	{
		return;
	}

	public function settings_page()
	{
		if( isset( $_GET['cancel'] ) && trim( $_GET['cancel'] ) === 'setup' ){
			update_option('dc_setup_screen_seen', true);
		}
		include_once DC_PLUGIN_VIEWS_PATH . 'settings.php';
	}

	public function setup_settings_page()
	{
		if( get_option('dc_setup_screen_seen', false ) ) {
			wp_safe_redirect(admin_url('options-general.php?page=' . DC_PLUGIN_SLUG ));
			exit;
		}
		update_option('dc_setup_screen_seen', true);
		include_once DC_PLUGIN_VIEWS_PATH . 'setup-settings.php';
	}

	public function form_data_modify($form_data)
	{
		$formArray = [];
		if (is_array($form_data) && count($form_data) > 0) {
			foreach ($form_data as $form_item) {
				if (preg_match('/[[]]/', $form_item['name'])) {
					$formArray[str_replace("[]", "", $form_item['name'])][] = $form_item['value'];
				} else {
					$formArray[$form_item['name']] = $form_item['value'];
				}
			}
		}
		return $formArray;
	}

	public function disable_comments_settings($_args = array())
	{
		$nonce = (isset($_POST['nonce']) ? $_POST['nonce'] : '');
		if (($this->is_CLI && !empty($_args)) || wp_verify_nonce($nonce, 'disable_comments_save_settings')) {
			if (!empty($_args)) {
				$formArray = wp_parse_args($_args);
			} else {
				$formArray = (isset($_POST['data']) ? $this->form_data_modify($_POST['data']) : []);
			}
			if (isset($formArray['mode'])) {
				$this->options['remove_everywhere'] = (sanitize_text_field($formArray['mode']) == 'remove_everywhere');
			}
			$post_types = $this->get_all_post_types();

			if ($this->options['remove_everywhere']) {
				$disabled_post_types = array_keys($post_types);
			} else {
				$disabled_post_types = (isset($formArray['disabled_types']) ? array_map('sanitize_key', (array) $formArray['disabled_types']) : ( isset( $this->options['disabled_post_types'] ) ? $this->options['disabled_post_types'] : [] ));
			}

			$disabled_post_types = array_intersect($disabled_post_types, array_keys($post_types));
			$this->options['disabled_post_types'] = $disabled_post_types;

			// Extra custom post types.
			if ($this->networkactive && !empty($formArray['extra_post_types'])) {
				$extra_post_types                  = array_filter(array_map('sanitize_key', explode(',', $formArray['extra_post_types'])));
				$this->options['extra_post_types'] = array_diff($extra_post_types, array_keys($post_types)); // Make sure we don't double up builtins.
			}
			// xml rpc
			$this->options['remove_xmlrpc_comments'] = (isset($formArray['remove_xmlrpc_comments']) ? intval($formArray['remove_xmlrpc_comments']) : ($this->is_CLI && isset($this->options['remove_xmlrpc_comments']) ? $this->options['remove_xmlrpc_comments'] : 0));
			// rest api comments
			$this->options['remove_rest_API_comments'] = (isset($formArray['remove_rest_API_comments']) ? intval($formArray['remove_rest_API_comments']) : ($this->is_CLI && isset($this->options['remove_rest_API_comments']) ? $this->options['remove_rest_API_comments'] : 0));

			// save settings
			$this->update_options();
		}
		if (!$this->is_CLI) {
			wp_send_json_success(array('message' => __('Saved', 'disable-comments')));
			wp_die();
		}
	}

	public function delete_comments_settings($_args = array())
	{
		$log = '';
		$deletedPostTypeNames = [];
		$nonce = (isset($_POST['nonce']) ? $_POST['nonce'] : '');
		if (($this->is_CLI && !empty($_args)) || wp_verify_nonce($nonce, 'disable_comments_save_settings')) {

			if (!empty($_args)) {
				$formArray = wp_parse_args($_args);
			} else {
				$formArray = $this->form_data_modify($_POST['data']);
			}

			$types = $this->get_all_post_types();
			$commenttypes = $this->get_all_comment_types();
			global $wpdb;
			// comments delete
			if (isset($formArray['delete_mode'])) {
				if ($formArray['delete_mode'] == 'delete_everywhere') {
					if ($wpdb->query("TRUNCATE $wpdb->commentmeta") != false) {
						if ($wpdb->query("TRUNCATE $wpdb->comments") != false) {
							$wpdb->query("UPDATE $wpdb->posts SET comment_count = 0");
							$wpdb->query("OPTIMIZE TABLE $wpdb->commentmeta");
							$wpdb->query("OPTIMIZE TABLE $wpdb->comments");
							$log = __('All comments has been deleted', 'disable-comments');
						} else {
							wp_send_json_error(array('message' => __('Internal error occured. Please try again later.', 'disable-comments')));
							wp_die();
						}
					} else {
						wp_send_json_error(array('message' => __('Internal error occured. Please try again later.', 'disable-comments')));
						wp_die();
					}
				} elseif ($formArray['delete_mode'] == 'selected_delete_types') {
					$delete_post_types = empty($formArray['delete_types']) ? array() : (array) $formArray['delete_types'];
					$delete_post_types = array_intersect($delete_post_types, array_keys($types));

					// Extra custom post types.
					if ($this->networkactive && !empty($formArray['delete_extra_post_types'])) {
						$delete_extra_post_types = array_filter(array_map('sanitize_key', explode(',', $formArray['delete_extra_post_types'])));
						$delete_extra_post_types = array_diff($delete_extra_post_types, array_keys($types));    // Make sure we don't double up builtins.
						$delete_post_types       = array_merge($delete_post_types, $delete_extra_post_types);
					}

					if (!empty($delete_post_types)) {
						// Loop through post_types and remove comments/meta and set posts comment_count to 0.
						foreach ($delete_post_types as $delete_post_type) {
							$wpdb->query("DELETE cmeta FROM $wpdb->commentmeta cmeta INNER JOIN $wpdb->comments comments ON cmeta.comment_id=comments.comment_ID INNER JOIN $wpdb->posts posts ON comments.comment_post_ID=posts.ID WHERE posts.post_type = '$delete_post_type'");
							$wpdb->query("DELETE comments FROM $wpdb->comments comments INNER JOIN $wpdb->posts posts ON comments.comment_post_ID=posts.ID WHERE posts.post_type = '$delete_post_type'");
							$wpdb->query("UPDATE $wpdb->posts SET comment_count = 0 WHERE post_author != 0 AND post_type = '$delete_post_type'");

							$post_type_object = get_post_type_object($delete_post_type);
							$post_type_label  = $post_type_object ? $post_type_object->labels->name : $delete_post_type;
							$deletedPostTypeNames[] = $post_type_label;
						}

						$wpdb->query("OPTIMIZE TABLE $wpdb->commentmeta");
						$wpdb->query("OPTIMIZE TABLE $wpdb->comments");
						$log = __('All comments has been deleted', 'disable-comments');
					}
				} elseif ($formArray['delete_mode'] == 'selected_delete_comment_types') {
					$delete_comment_types = empty($formArray['delete_comment_types']) ? array() : (array) $formArray['delete_comment_types'];
					$delete_comment_types = array_intersect($delete_comment_types, array_keys($commenttypes));

					if (!empty($delete_comment_types)) {
						// Loop through comment_types and remove comments/meta and set posts comment_count to 0.
						foreach ($delete_comment_types as $delete_comment_type) {
							$wpdb->query("DELETE cmeta FROM $wpdb->commentmeta cmeta INNER JOIN $wpdb->comments comments ON cmeta.comment_id=comments.comment_ID WHERE comments.comment_type = '$delete_comment_type'");
							$wpdb->query("DELETE comments FROM $wpdb->comments comments  WHERE comments.comment_type = '$delete_comment_type'");
							$deletedPostTypeNames[] = $commenttypes[$delete_comment_type];
						}

						// Update comment_count on post_types
						foreach ($types as $key => $value) {
							$comment_count = $wpdb->get_var("SELECT COUNT(comments.comment_ID) FROM $wpdb->comments comments INNER JOIN $wpdb->posts posts ON comments.comment_post_ID=posts.ID WHERE posts.post_type = '$key'");
							$wpdb->query("UPDATE $wpdb->posts SET comment_count = $comment_count WHERE post_author != 0 AND post_type = '$key'");
						}

						$wpdb->query("OPTIMIZE TABLE $wpdb->commentmeta");
						$wpdb->query("OPTIMIZE TABLE $wpdb->comments");

						$log = __('All comments has been deleted', 'disable-comments');
					}
				}
			}
		}
		// message
		$message = (count($deletedPostTypeNames) == 0 ? $log . '.' : $log . ' for ' . implode(", ", $deletedPostTypeNames) . '.');
		if (!$this->is_CLI) {
			wp_send_json_success(array('message' => $message));
			wp_die();
		} else {
			return $log;
		}
	}

	private function discussion_settings_allowed()
	{
		if (defined('DISABLE_COMMENTS_ALLOW_DISCUSSION_SETTINGS') && DISABLE_COMMENTS_ALLOW_DISCUSSION_SETTINGS == true) {
			return true;
		}
	}

	public function single_site_deactivate()
	{
		// for single sites, delete the options upon deactivation, not uninstall.
		delete_option('disable_comments_options');
	}
}

Disable_Comments::get_instance();
