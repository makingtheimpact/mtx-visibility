<?php
/**
 * Plugin Name: MTX — Elementor x MemberPress Visibility
 * Plugin URI: https://github.com/your-repo/mtx-visibility
 * Description: Per-element show/hide in Elementor based on active MemberPress memberships.
 * Version: 1.0.4
 * Author: Making The Impact LLC
 * Author URI: https://makingtheimpact.com
 * License: GPL v2 or later
 * Text Domain: mtx-visibility
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: elementor, memberpress
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * MTX Elementor MemberPress Visibility Plugin
 * 
 * Provides per-element visibility control in Elementor based on MemberPress membership status.
 * Features include membership-based show/hide, inversion logic, and efficient caching.
 * 
 * @package MTX_Visibility
 * @version 1.0.4
 * @author MTX
 */
final class MTX_Elementor_MemberPress_Visibility {
  /** @var MTX_Elementor_MemberPress_Visibility|null Singleton instance */
  private static $instance = null;
  
  /** @var array|null Cached MemberPress product IDs */
  private static $plan_ids_cache = null;
  
  /** @var array User authorization cache with TTL */
  private static $member_cache = [];
  
  /** @var int Maximum cache entries before cleanup */
  private static $cache_max_size = 100;
  
  /** @var int Cache TTL in seconds (5 minutes) */
  private static $cache_ttl = 300;

  /**
   * Get singleton instance
   *
   * @return MTX_Elementor_MemberPress_Visibility
   */
  public static function instance() {
    if ( null === self::$instance ) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Constructor - Initialize the plugin
   */
  private function __construct() {
    // Check dependencies first
    add_action( 'admin_notices', [ $this, 'check_dependencies' ] );
    
    if ( ! did_action( 'elementor/loaded' ) ) {
      add_action( 'elementor/loaded', [ $this, 'init_elementor_hooks' ] );
      return;
    }
    
    $this->init_elementor_hooks();
  }
  
  /**
   * Initialize Elementor hooks
   */
  public function init_elementor_hooks() {
    // Always register controls - don't require MemberPress to be ready
    // Add controls to Elementor UI - use specific hooks for each element type
    add_action( 'elementor/element/common/section_advanced/after_section_end',   [ $this, 'register_controls' ], 10, 2 );
    add_action( 'elementor/element/section/section_advanced/after_section_end',  [ $this, 'register_controls' ], 10, 2 );
    add_action( 'elementor/element/column/section_advanced/after_section_end',   [ $this, 'register_controls' ], 10, 2 );
    // Container (Flexbox) uses a different hook section in some versions — register on layout too.
    add_action( 'elementor/element/container/section_layout/after_section_end',  [ $this, 'register_controls' ], 10, 2 );
    add_action( 'elementor/element/container/section_advanced/after_section_end',[ $this, 'register_controls' ], 10, 2 );

    // Decide rendering
    add_filter( 'elementor/frontend/widget/should_render',    [ $this, 'should_render' ], 10, 2 );
    add_filter( 'elementor/frontend/section/should_render',   [ $this, 'should_render' ], 10, 2 );
    add_filter( 'elementor/frontend/column/should_render',    [ $this, 'should_render' ], 10, 2 );
    // Container (Flexbox) newer filter
    add_filter( 'elementor/frontend/container/should_render', [ $this, 'should_render' ], 10, 2 );
    
    // Add cache invalidation hooks only if MemberPress is ready
    if ( $this->is_plugin_ready() ) {
      $this->add_cache_invalidation_hooks();
    }
  }
  
  /**
   * Check if plugin is ready to function
   *
   * @return bool
   */
  private function is_plugin_ready() {
    return class_exists( 'MeprUser' ) && 
           did_action( 'elementor/loaded' ) && 
           function_exists( 'get_current_user_id' );
  }

  /**
   * Parse boolean setting from Elementor
   *
   * @param mixed $value
   * @return bool
   */
  private function parse_boolean_setting( $value ) {
    return ! empty( $value ) && $value === 'yes';
  }

  /**
   * Parse require setting from Elementor
   *
   * @param mixed $value
   * @return string
   */
  private function parse_require_setting( $value ) {
    return ( ! empty( $value ) && $value === 'all' ) ? 'all' : 'any';
  }

  /**
   * Check for required dependencies
   */
  public function check_dependencies() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    
    $missing = [];
    
    if ( ! class_exists( 'MeprUser' ) ) {
      $missing[] = 'MemberPress';
    }
    
    if ( ! did_action( 'elementor/loaded' ) ) {
      $missing[] = 'Elementor';
    }
    
    if ( ! empty( $missing ) ) {
      echo '<div class="notice notice-warning is-dismissible">';
      echo '<p><strong>MTX Visibility:</strong> This plugin requires the following plugins to be active: ' . implode( ', ', $missing ) . '</p>';
      echo '</div>';
    }
  }

  /**
   * Register Elementor controls for MemberPress visibility
   *
   * @param \Elementor\Controls_Stack $element
   * @param array $args
   */
  public function register_controls( $element, $args ) {
    // Check if controls already exist to prevent duplicates
    $controls = method_exists( $element, 'get_controls' ) ? $element->get_controls() : [];
    if ( is_array( $controls ) && array_key_exists( 'mtx_mepr_enable', $controls ) ) return;

    $element->start_controls_section('mtx_mepr_section', [
      'label' => __( 'MemberPress', 'mtx' ),
      'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
    ]);

    $element->add_control('mtx_mepr_enable', [
      'label'        => __( 'Restrict by MemberPress', 'mtx' ),
      'type'         => \Elementor\Controls_Manager::SWITCHER,
      'label_on'     => __( 'Yes', 'mtx' ),
      'label_off'    => __( 'No', 'mtx' ),
      'return_value' => 'yes',
      'default'      => '',
    ]);

    $element->add_control('mtx_mepr_ids', [
      'label'       => __( 'Membership IDs (comma-separated)', 'mtx' ),
      'type'        => \Elementor\Controls_Manager::TEXT,
      'placeholder' => 'e.g. 12,34,56 (leave empty = any active membership)',
      'condition'   => [ 'mtx_mepr_enable' => 'yes' ],
    ]);

    $element->add_control('mtx_mepr_require', [
      'label'     => __( 'Require', 'mtx' ),
      'type'      => \Elementor\Controls_Manager::SELECT,
      'default'   => 'any',
      'options'   => [ 'any' => __( 'Any of these', 'mtx' ), 'all' => __( 'All of these', 'mtx' ) ],
      'condition' => [ 'mtx_mepr_enable' => 'yes' ],
    ]);

    $element->add_control('mtx_mepr_invert', [
      'label'        => __( 'Invert (show to non-members)', 'mtx' ),
      'type'         => \Elementor\Controls_Manager::SWITCHER,
      'return_value' => 'yes',
      'default'      => '',
      'description'  => __( 'ON: show to guests / users NOT authorized by these IDs (or any active plan when IDs are blank).', 'mtx' ),
      'condition'    => [ 'mtx_mepr_enable' => 'yes' ],
    ]);

    $element->end_controls_section();
  }

  /**
   * Determine if element should render based on MemberPress visibility settings
   *
   * @param bool $should_render Original render decision
   * @param \Elementor\Element_Base $element Elementor element
   * @return bool Final render decision
   */
  public function should_render( $should_render, $element ) {
    try {
      // Always show in editor so you can work
      if ( method_exists( \Elementor\Plugin::$instance, 'editor' ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
        return true;
      }
      
      if ( ! $this->is_plugin_ready() ) {
        return $should_render;
      }

      // Get element settings
      $settings = $element->get_settings_for_display();
      
      // Check if MemberPress restriction is enabled
      if ( ! $this->parse_boolean_setting( $settings['mtx_mepr_enable'] ?? '' ) ) {
        return $should_render;
      }

      // Parse and validate visibility settings
      $invert = $this->parse_boolean_setting( $settings['mtx_mepr_invert'] ?? '' );
      $require = $this->parse_require_setting( $settings['mtx_mepr_require'] ?? '' );

      // Parse membership IDs from settings with proper validation
      $ids = [];
      if ( ! empty( $settings['mtx_mepr_ids'] ) && is_string( $settings['mtx_mepr_ids'] ) ) {
        $raw_ids = array_map( 'trim', explode( ',', $settings['mtx_mepr_ids'] ) );
        $ids = array_filter( array_map( 'absint', $raw_ids ), function( $id ) {
          return $id > 0; // Only allow positive integers
        } );
      }

      // Check user authorization
      $authorized = $this->user_is_authorized( get_current_user_id(), $ids, $require );

      // Apply inversion logic: invert means "show to NOT authorized"
      $display = $invert ? ! $authorized : $authorized;


      return $should_render && $display;

    } catch ( \Throwable $e ) {
      // Log error only in debug mode to avoid cluttering production logs
      if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'MTX Visibility Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
      }
      return $should_render; // fail open - show content if there's an error
    }
  }

  /**
   * Check if user is authorized based on membership
   *
   * @param int $user_id
   * @param array $ids
   * @param string $require
   * @return bool
   */
  private function user_is_authorized( $user_id, $ids, $require ) {
    // Validate inputs
    if ( ! is_user_logged_in() || ! $user_id || ! is_numeric( $user_id ) || $user_id <= 0 ) {
      return false;
    }

    // Validate require parameter
    if ( ! in_array( $require, [ 'any', 'all' ], true ) ) {
      $require = 'any'; // Default to 'any' if invalid
    }

    $cache_key = $user_id . '|' . implode( ',', (array) $ids ) . '|' . $require;
    
    // Check cache with TTL
    if ( isset( self::$member_cache[$cache_key] ) ) {
      $cached = self::$member_cache[$cache_key];
      if ( isset( $cached['timestamp'] ) && ( time() - $cached['timestamp'] ) < self::$cache_ttl ) {
        return $cached['result'];
      }
      unset( self::$member_cache[$cache_key] );
    }
    
    // Manage cache size
    if ( count( self::$member_cache ) >= self::$cache_max_size ) {
      self::$member_cache = array_slice( self::$member_cache, -50, null, true );
    }

    try {
      $user = new \MeprUser( $user_id );
      
      // Validate user object
      if ( ! $user || ! is_object( $user ) ) {
        self::$member_cache[$cache_key] = [ 'result' => false, 'timestamp' => time() ];
        return false;
      }
    } catch ( \Exception $e ) {
      // If we can't create the user object, they're not authorized
      self::$member_cache[$cache_key] = [ 'result' => false, 'timestamp' => time() ];
      return false;
    }

    // Get active product IDs using the most reliable MemberPress method
    $active_ids = [];
    
    // Primary method: active_product_subscriptions with 'ids' parameter (most reliable)
    if ( method_exists( $user, 'active_product_subscriptions' ) ) {
      try {
        $active_ids_array = $user->active_product_subscriptions( 'ids' );
        if ( is_array( $active_ids_array ) ) {
          $active_ids = array_merge( $active_ids, $active_ids_array );
        }
      } catch ( \Exception $e ) {
        // If the method fails, fall through to fallback
      }
    }
    
    // Fallback method: is_active_member for each product (for older MemberPress versions)
    if ( empty( $active_ids ) && method_exists( $user, 'is_active_member' ) ) {
      $all_plan_ids = $this->get_all_plan_ids();
      foreach ( $all_plan_ids as $plan_id ) {
        if ( $user->is_active_member( $plan_id ) ) {
          $active_ids[] = $plan_id;
        }
      }
    }
    
    $active_ids = array_unique( array_filter( array_map( 'absint', $active_ids ) ) );

    // If no IDs specified: "any active membership?"
    if ( empty( $ids ) ) {
      $ok = ! empty( $active_ids );
      self::$member_cache[$cache_key] = [ 'result' => $ok, 'timestamp' => time() ];
      return $ok;
    }

    // With specific IDs: set comparison against active_ids
    $ids = array_filter( array_map( 'absint', (array) $ids ) );
    if ( ! empty( $active_ids ) ) {
      $ok = ( $require === 'all' )
        ? empty( array_diff( $ids, $active_ids ) )       // must have ALL IDs
        : ( count( array_intersect( $ids, $active_ids ) ) > 0 ); // ANY overlap
      self::$member_cache[$cache_key] = [ 'result' => $ok, 'timestamp' => time() ];
      return $ok;
    }

    // Fallback (older MP): per-ID check
    $checks = [];
    foreach ( $ids as $pid ) {
      $checks[] = (bool) ( method_exists( $user, 'is_active_member' )
                ? $user->is_active_member( (int) $pid )
                : false );
    }
    $ok = ( $require === 'all' ) ? ! in_array( false, $checks, true ) : in_array( true, $checks, true );
    self::$member_cache[$cache_key] = [ 'result' => $ok, 'timestamp' => time() ];
    return $ok;
  }

  /**
   * Get all MemberPress product IDs with caching
   *
   * @return array
   */
  private function get_all_plan_ids() {
    if ( null !== self::$plan_ids_cache ) {
      return self::$plan_ids_cache;
    }

    try {
      $ids = get_posts([
        'post_type'        => 'memberpressproduct',
        'post_status'      => 'publish',
        'numberposts'      => -1,
        'fields'           => 'ids',
        'suppress_filters' => false,
        'no_found_rows'    => true, // Performance optimization
        'update_post_meta_cache' => false, // Performance optimization
        'update_post_term_cache' => false, // Performance optimization
      ]);
      
      if ( is_array( $ids ) ) {
        self::$plan_ids_cache = array_filter( array_map( 'absint', $ids ), function( $id ) {
          return $id > 0;
        } );
      } else {
        self::$plan_ids_cache = [];
      }
    } catch ( \Exception $e ) {
      self::$plan_ids_cache = [];
    }
    
    return self::$plan_ids_cache;
  }
  
  /**
   * Add hooks for cache invalidation when memberships change
   */
  private function add_cache_invalidation_hooks() {
    // Clear cache when user memberships change - use correct hook parameters
    add_action( 'mepr_subscription_status_changed', [ $this, 'clear_user_cache_from_sub' ], 10, 3 );
    add_action( 'mepr_subscription_created', [ $this, 'clear_user_cache_from_sub_id' ], 10, 1 );
    add_action( 'mepr_subscription_deleted', [ $this, 'clear_user_cache_from_sub_id' ], 10, 1 );
    
    // Clear plan cache when MemberPress products are updated
    add_action( 'save_post', [ $this, 'maybe_clear_plan_cache' ], 10, 2 );
    add_action( 'delete_post', [ $this, 'maybe_clear_plan_cache' ], 10, 2 );
    
    // Clear all caches on plugin deactivation/activation
    add_action( 'mepr_plugin_activated', [ $this, 'clear_all_cache' ] );
    add_action( 'mepr_plugin_deactivated', [ $this, 'clear_all_cache' ] );
  }
  
  /**
   * Clear cache for specific user when their membership changes
   *
   * @param int $user_id User ID
   */
  public function clear_user_cache( $user_id ) {
    if ( ! $user_id || ! is_numeric( $user_id ) ) {
      return;
    }
    
    // Remove all cache entries for this user
    $user_id = (int) $user_id;
    foreach ( array_keys( self::$member_cache ) as $key ) {
      if ( strpos( $key, $user_id . '|' ) === 0 ) {
        unset( self::$member_cache[$key] );
      }
    }
  }
  
  /**
   * Clear user cache from subscription status change hook
   *
   * @param string $old_status Old subscription status
   * @param string $new_status New subscription status  
   * @param int $sub_id Subscription ID
   */
  public function clear_user_cache_from_sub( $old_status, $new_status, $sub_id ) {
    $this->clear_user_cache_from_sub_id( $sub_id );
  }
  
  /**
   * Clear user cache from subscription ID
   *
   * @param int $sub_id Subscription ID
   */
  public function clear_user_cache_from_sub_id( $sub_id ) {
    if ( ! class_exists( 'MeprSubscription' ) ) {
      return;
    }
    
    $sub = new \MeprSubscription( (int) $sub_id );
    if ( ! is_object( $sub ) || empty( $sub->user_id ) ) {
      return;
    }
    
    $this->clear_user_cache( (int) $sub->user_id );
  }
  
  /**
   * Clear plan cache if MemberPress product was modified
   *
   * @param int $post_id Post ID
   * @param \WP_Post $post Post object
   */
  public function maybe_clear_plan_cache( $post_id, $post ) {
    if ( $post && is_object( $post ) && isset( $post->post_type ) && $post->post_type === 'memberpressproduct' ) {
      self::$plan_ids_cache = null;
    }
  }
  
  /**
   * Clear all caches
   */
  public function clear_all_cache() {
    self::$member_cache = [];
    self::$plan_ids_cache = null;
  }
  
  /**
   * Clear caches (useful for debugging or when memberships change)
   */
  public static function clear_cache() {
    self::$member_cache = [];
    self::$plan_ids_cache = null;
  }
  
  /**
   * Get cache statistics for debugging
   *
   * @return array Cache statistics
   */
  public static function get_cache_stats() {
    return [
      'member_cache_size' => count( self::$member_cache ),
      'member_cache_max' => self::$cache_max_size,
      'plan_cache_cached' => self::$plan_ids_cache !== null,
      'plan_cache_size' => self::$plan_ids_cache ? count( self::$plan_ids_cache ) : 0,
      'cache_ttl' => self::$cache_ttl,
    ];
  }
  
  /**
   * Debug method to log cache operations (only in debug mode)
   *
   * @param string $message Debug message
   * @param array $data Additional data to log
   */
  private function debug_log( $message, $data = [] ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
      $log_data = [
        'message' => $message,
        'data' => $data,
        'timestamp' => current_time( 'mysql' ),
        'memory_usage' => memory_get_usage( true ),
      ];
      error_log( 'MTX Visibility Debug: ' . wp_json_encode( $log_data ) );
    }
  }

}
add_action( 'plugins_loaded', function(){ 
  MTX_Elementor_MemberPress_Visibility::instance(); 
} );

/**
 * MTX: Menu Item Visibility Settings
 * Adds visibility controls to WordPress menu items in Appearance → Menus
 */

// Add custom fields to menu items
add_action('wp_nav_menu_item_custom_fields', function($item_id, $item, $depth, $args) {
  $visibility_type = get_post_meta($item_id, '_mtx_menu_visibility_type', true);
  $membership_ids = get_post_meta($item_id, '_mtx_menu_membership_ids', true);
  $require_all = get_post_meta($item_id, '_mtx_menu_require_all', true);
  
  ?>
  <div class="field-mtx-visibility description description-wide">
    <h4><?php _e('MTX Visibility Settings', 'mtx-visibility'); ?></h4>
    
    <p class="field-visibility-type description">
      <label for="edit-menu-item-visibility-type-<?php echo $item_id; ?>">
        <?php _e('Visibility Rule', 'mtx-visibility'); ?><br />
        <select id="edit-menu-item-visibility-type-<?php echo $item_id; ?>" 
                class="widefat" 
                name="menu-item-mtx-visibility-type[<?php echo $item_id; ?>]">
          <option value=""><?php _e('Always visible', 'mtx-visibility'); ?></option>
          <option value="logged-in" <?php selected($visibility_type, 'logged-in'); ?>><?php _e('Logged-in users only', 'mtx-visibility'); ?></option>
          <option value="logged-out" <?php selected($visibility_type, 'logged-out'); ?>><?php _e('Logged-out users only', 'mtx-visibility'); ?></option>
          <option value="membership" <?php selected($visibility_type, 'membership'); ?>><?php _e('Specific membership plans', 'mtx-visibility'); ?></option>
        </select>
      </label>
    </p>
    
    <p class="field-membership-ids description" style="<?php echo $visibility_type === 'membership' ? '' : 'display:none;'; ?>">
      <label for="edit-menu-item-membership-ids-<?php echo $item_id; ?>">
        <?php _e('Membership Plan IDs (comma-separated)', 'mtx-visibility'); ?><br />
        <input type="text" 
               id="edit-menu-item-membership-ids-<?php echo $item_id; ?>" 
               class="widefat" 
               name="menu-item-mtx-membership-ids[<?php echo $item_id; ?>]"
               value="<?php echo esc_attr($membership_ids); ?>"
               placeholder="e.g. 12,34,56" />
        <small><?php _e('Leave empty to show to any active member', 'mtx-visibility'); ?></small>
      </label>
    </p>
    
    <p class="field-require-all description" style="<?php echo $visibility_type === 'membership' ? '' : 'display:none;'; ?>">
      <label for="edit-menu-item-require-all-<?php echo $item_id; ?>">
        <input type="checkbox" 
               id="edit-menu-item-require-all-<?php echo $item_id; ?>" 
               name="menu-item-mtx-require-all[<?php echo $item_id; ?>]"
               value="1" 
               <?php checked($require_all, '1'); ?> />
        <?php _e('Require ALL specified plans (instead of ANY)', 'mtx-visibility'); ?>
      </label>
    </p>
  </div>
  
  <script>
  jQuery(document).ready(function($) {
    $('#edit-menu-item-visibility-type-<?php echo $item_id; ?>').change(function() {
      var isMembership = $(this).val() === 'membership';
      $(this).closest('.field-mtx-visibility').find('.field-membership-ids, .field-require-all').toggle(isMembership);
    });
  });
  </script>
  <?php
}, 10, 4);

// Save menu item custom fields
add_action('wp_update_nav_menu_item', function($menu_id, $menu_item_db_id, $args) {
  // Save visibility type
  if (isset($_POST['menu-item-mtx-visibility-type'][$menu_item_db_id])) {
    update_post_meta($menu_item_db_id, '_mtx_menu_visibility_type', sanitize_text_field($_POST['menu-item-mtx-visibility-type'][$menu_item_db_id]));
  } else {
    delete_post_meta($menu_item_db_id, '_mtx_menu_visibility_type');
  }
  
  // Save membership IDs
  if (isset($_POST['menu-item-mtx-membership-ids'][$menu_item_db_id])) {
    $ids = sanitize_text_field($_POST['menu-item-mtx-membership-ids'][$menu_item_db_id]);
    if (!empty($ids)) {
      update_post_meta($menu_item_db_id, '_mtx_menu_membership_ids', $ids);
    } else {
      delete_post_meta($menu_item_db_id, '_mtx_menu_membership_ids');
    }
  } else {
    delete_post_meta($menu_item_db_id, '_mtx_menu_membership_ids');
  }
  
  // Save require all setting
  if (isset($_POST['menu-item-mtx-require-all'][$menu_item_db_id])) {
    update_post_meta($menu_item_db_id, '_mtx_menu_require_all', '1');
  } else {
    delete_post_meta($menu_item_db_id, '_mtx_menu_require_all');
  }
}, 10, 3);

// Add CSS for better styling
add_action('admin_head-nav-menus.php', function() {
  ?>
  <style>
  .field-mtx-visibility {
    border-top: 1px solid #eee;
    margin-top: 10px;
    padding-top: 10px;
  }
  .field-mtx-visibility h4 {
    margin: 0 0 10px 0;
    color: #333;
  }
  .field-mtx-visibility .description {
    margin-bottom: 8px;
  }
  .field-mtx-visibility small {
    color: #666;
    font-style: italic;
  }
  </style>
  <?php
});

add_filter('wp_nav_menu_objects', function($items, $args) {
  if (empty($items) || !is_array($items)) return $items;

  $is_logged_in = is_user_logged_in();
  $user_id = $is_logged_in ? get_current_user_id() : 0;

   // Cache active plan IDs per request
   static $active_plan_ids = null;
   if ($is_logged_in && class_exists('MeprUser') && $active_plan_ids === null) {
     $active_plan_ids = [];
     try {
       $user = new \MeprUser($user_id);
       
       // Use the same reliable method as the main plugin
       if (method_exists($user, 'active_product_subscriptions')) {
         $active_ids_array = $user->active_product_subscriptions('ids');
         if (is_array($active_ids_array)) {
           $active_plan_ids = array_merge($active_plan_ids, $active_ids_array);
         }
       }
       
       // Fallback method for older MemberPress versions
       if (empty($active_plan_ids) && method_exists($user, 'is_active_member')) {
         $all_plan_ids = get_posts([
           'post_type' => 'memberpressproduct',
           'post_status' => 'publish',
           'numberposts' => -1,
           'fields' => 'ids',
           'suppress_filters' => false,
         ]);
         foreach ($all_plan_ids as $plan_id) {
           if ($user->is_active_member($plan_id)) {
             $active_plan_ids[] = $plan_id;
           }
         }
       }
     } catch (\Throwable $e) { $active_plan_ids = []; }
     $active_plan_ids = array_unique(array_filter(array_map('absint', $active_plan_ids)));
   }

  foreach ($items as $key => $item) {
    // Get visibility settings from menu item meta
    $visibility_type = get_post_meta($item->ID, '_mtx_menu_visibility_type', true);
    
    // Skip if no visibility rule is set
    if (empty($visibility_type)) {
      continue;
    }
    
    $should_show = true;
    
    // 1) Basic login gating
    if ($visibility_type === 'logged-in' && !$is_logged_in) {
      $should_show = false;
    } elseif ($visibility_type === 'logged-out' && $is_logged_in) {
      $should_show = false;
    }
    
    // 2) MemberPress plan gating
    elseif ($visibility_type === 'membership') {
      $membership_ids = get_post_meta($item->ID, '_mtx_menu_membership_ids', true);
      $require_all = get_post_meta($item->ID, '_mtx_menu_require_all', true) === '1';
      
      // If not logged in → treat as unauthorized
      if (!$is_logged_in) {
        $should_show = false;
      } else {
        // Parse membership IDs
        $ids = [];
        if (!empty($membership_ids)) {
          $ids = array_filter(array_map('absint', explode(',', $membership_ids)));
        }
        
        if (empty($ids)) {
          // No specific IDs = show to any active member
          $should_show = !empty($active_plan_ids);
        } else {
          // Check specific IDs
          if (is_array($active_plan_ids) && !empty($active_plan_ids)) {
            $should_show = $require_all
              ? empty(array_diff($ids, $active_plan_ids))                      // has ALL
              : (count(array_intersect($ids, $active_plan_ids)) > 0);          // has ANY
          } elseif (class_exists('MeprUser')) {
            // Fallback per-ID (older MP)
            $should_show = false;
            try {
              $user = new \MeprUser($user_id);
              $checks = [];
              foreach ($ids as $pid) {
                $checks[] = (bool)(method_exists($user,'is_active_member') ? $user->is_active_member((int)$pid) : false);
              }
              $should_show = $require_all ? !in_array(false,$checks,true) : in_array(true,$checks,true);
            } catch (\Throwable $e) { $should_show = false; }
          } else {
            $should_show = false;
          }
        }
      }
    }
    
    // Hide item if it shouldn't be shown
    if (!$should_show) {
      unset($items[$key]);
    }
  }

  // Reindex to avoid gaps
  return array_values($items);
}, 10, 2);
