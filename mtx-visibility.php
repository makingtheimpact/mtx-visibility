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
 * Features include membership-based show/hide, inversion logic, and cache-safe rendering.
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

  /** @var array<int,bool> Map of Elementor documents that include visibility rules */
  private static $document_visibility_map = [];

  /** @var array Legacy member cache placeholder (no longer used for caching) */
  private static $member_cache = [];

  /** @var bool Flag to avoid re-sending nocache headers */
  private static $nocache_headers_sent = false;

  /** @var bool Track if the current request contains restricted elements */
  private static $has_restricted_elements = false;

  /** @var array<string,string> Marker comments to output in Elementor editor/preview */
  private static $editor_marker_comments = [];

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

    // Output editor markers before rendering gated elements
    add_action( 'elementor/frontend/element/before_render', [ $this, 'maybe_output_editor_marker' ], 5, 1 );

    // Disable Elementor cache when restricted elements exist
    add_filter( 'elementor/frontend/should_load_cache', [ $this, 'maybe_disable_elementor_cache' ], 10, 2 );
    add_filter( 'elementor/frontend/should_save_cache', [ $this, 'maybe_disable_elementor_cache' ], 10, 2 );
    
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
    if ( is_bool( $value ) ) {
      return $value;
    }

    if ( is_int( $value ) ) {
      return $value === 1;
    }

    if ( is_string( $value ) ) {
      $value = strtolower( trim( $value ) );

      if ( in_array( $value, [ 'yes', 'true', 'on', '1' ], true ) ) {
        return true;
      }

      if ( in_array( $value, [ 'no', 'false', 'off', '0' ], true ) ) {
        return false;
      }
    }

    return ! empty( $value );
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
   * Normalize plan IDs from MemberPress subscription objects or arrays.
   *
   * @param mixed $active_subs Subscriptions returned by MemberPress.
   * @return array<int>
   */
  private function normalize_plan_ids_from_subscriptions( $active_subs ) {
    if ( empty( $active_subs ) || ! is_array( $active_subs ) ) {
      return [];
    }

    $ids = [];
    $subscription_class_exists = class_exists( '\\MeprSubscription' );

    foreach ( $active_subs as $sub ) {
      if ( $subscription_class_exists && $sub instanceof \MeprSubscription ) {
        if ( ! empty( $sub->product_id ) ) {
          $ids[] = (int) $sub->product_id;
        }
        continue;
      }

      if ( is_object( $sub ) && isset( $sub->product_id ) ) {
        $ids[] = (int) $sub->product_id;
        continue;
      }

      if ( is_array( $sub ) && isset( $sub['product_id'] ) ) {
        $ids[] = (int) $sub['product_id'];
        continue;
      }

      if ( is_numeric( $sub ) ) {
        $ids[] = (int) $sub;
      }
    }

    return $ids;
  }

  /**
   * Get active MemberPress plan IDs for a user.
   *
   * @param int $user_id
   * @return array<int>
   */
  public function get_active_plan_ids_for_user( $user_id ) {
    $user_id = absint( $user_id );
    if ( $user_id <= 0 || ! class_exists( 'MeprUser' ) ) {
      return [];
    }

    $cache_key = $user_id . '|active_plans';
    if ( array_key_exists( $cache_key, self::$member_cache ) ) {
      return self::$member_cache[ $cache_key ];
    }

    try {
      $user = new \MeprUser( $user_id );
      if ( ! $user || ! is_object( $user ) ) {
        self::$member_cache[ $cache_key ] = [];
        return [];
      }
    } catch ( \Throwable $e ) {
      self::$member_cache[ $cache_key ] = [];
      return [];
    }

    $subscriptions = $this->get_user_subscriptions_for_user( $user );

    $candidate_ids = [];
    foreach ( $subscriptions as $subscription ) {
      $plan_id = $this->get_subscription_product_id( $subscription );
      if ( $plan_id > 0 ) {
        $candidate_ids[] = $plan_id;
      }
    }

    if ( empty( $candidate_ids ) ) {
      $candidate_ids = $this->get_all_plan_ids();
    }

    $candidate_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $candidate_ids ) ) ) );
    $active_ids    = [];

    if ( ! empty( $candidate_ids ) ) {
      foreach ( $candidate_ids as $plan_id ) {
        if ( $this->user_has_access_to_plan( $user, $plan_id, $subscriptions ) ) {
          $active_ids[] = $plan_id;
        }
      }
    }

    $active_ids = array_values( array_unique( $active_ids ) );
    self::$member_cache[ $cache_key ] = $active_ids;

    return $active_ids;
  }

  /**
   * Retrieve subscriptions associated with a MemberPress user.
   *
   * @param \MeprUser $user MemberPress user instance.
   * @return array
   */
  private function get_user_subscriptions_for_user( $user ) {
    if ( ! is_object( $user ) ) {
      return [];
    }

    $subscriptions = [];
    $sources = [ 'subscriptions', 'product_subscriptions', 'active_product_subscriptions' ];

    foreach ( $sources as $method ) {
      if ( ! method_exists( $user, $method ) ) {
        continue;
      }

      try {
        $result = $user->{$method}();
        $subscriptions = array_merge( $subscriptions, $this->normalize_subscription_collection( $result ) );
      } catch ( \Throwable $e ) {
        // Ignore errors from individual MemberPress methods.
      }
    }

    return $subscriptions;
  }

  /**
   * Normalize various subscription collection formats to an array.
   *
   * @param mixed $subscriptions Subscription collection.
   * @return array
   */
  private function normalize_subscription_collection( $subscriptions ) {
    if ( empty( $subscriptions ) ) {
      return [];
    }

    if ( $subscriptions instanceof \Traversable ) {
      $subscriptions = iterator_to_array( $subscriptions );
    } elseif ( is_object( $subscriptions ) && method_exists( $subscriptions, 'to_array' ) ) {
      $subscriptions = $subscriptions->to_array();
    } elseif ( is_object( $subscriptions ) && isset( $subscriptions->subscriptions ) && is_array( $subscriptions->subscriptions ) ) {
      $subscriptions = $subscriptions->subscriptions;
    }

    if ( ! is_array( $subscriptions ) ) {
      return [];
    }

    $normalized = [];
    foreach ( $subscriptions as $subscription ) {
      if ( null === $subscription ) {
        continue;
      }

      if ( class_exists( '\\MeprSubscription' ) && is_numeric( $subscription ) && (int) $subscription > 0 ) {
        try {
          $normalized[] = new \MeprSubscription( (int) $subscription );
          continue;
        } catch ( \Throwable $e ) {
          continue;
        }
      }

      $normalized[] = $subscription;
    }

    return $normalized;
  }

  /**
   * Extract the MemberPress product ID from a subscription record.
   *
   * @param mixed $subscription Subscription entry.
   * @return int
   */
  private function get_subscription_product_id( $subscription ) {
    if ( is_object( $subscription ) && isset( $subscription->product_id ) ) {
      return absint( $subscription->product_id );
    }

    if ( is_object( $subscription ) ) {
      foreach ( [ 'product_id', 'get_product_id' ] as $method ) {
        if ( method_exists( $subscription, $method ) ) {
          try {
            return absint( $subscription->{$method}() );
          } catch ( \Throwable $e ) {
            // Ignore and continue.
          }
        }
      }
    }

    if ( is_array( $subscription ) && isset( $subscription['product_id'] ) ) {
      return absint( $subscription['product_id'] );
    }

    return 0;
  }

  /**
   * Determine whether the user has access to a given plan.
   *
   * @param \MeprUser $user          MemberPress user.
   * @param int        $plan_id       Plan/Product ID.
   * @param array|null $subscriptions Optional subscriptions array for reuse.
   * @return bool
   */
  private function user_has_access_to_plan( $user, $plan_id, $subscriptions = null ) {
    $plan_id = absint( $plan_id );
    if ( $plan_id <= 0 || ! is_object( $user ) ) {
      return false;
    }

    if ( method_exists( $user, 'has_access_to' ) ) {
      try {
        if ( $user->has_access_to( $plan_id ) ) {
          return true;
        }
      } catch ( \Throwable $e ) {
        // Ignore and fall back to subscription checks.
      }
    }

    if ( null === $subscriptions ) {
      $subscriptions = $this->get_user_subscriptions_for_user( $user );
    }

    foreach ( (array) $subscriptions as $subscription ) {
      if ( $this->get_subscription_product_id( $subscription ) !== $plan_id ) {
        continue;
      }

      if ( $this->subscription_grants_access( $subscription ) ) {
        return true;
      }
    }

    return false;
  }

  /**
   * Evaluate whether a subscription currently grants access.
   *
   * @param mixed $subscription Subscription entry.
   * @return bool
   */
  private function subscription_grants_access( $subscription ) {
    $status = strtolower( (string) $this->get_subscription_status( $subscription ) );

    if ( in_array( $status, [ 'active', 'trial', 'trialing', 'complete', 'completed', 'confirmed' ], true ) ) {
      return true;
    }

    if ( false !== strpos( $status, 'cancel' ) ) {
      $paid_through = $this->get_subscription_paid_through_timestamp( $subscription );
      if ( $paid_through > 0 ) {
        return current_time( 'timestamp' ) <= $paid_through;
      }
      return false;
    }

    if ( '' === $status && is_object( $subscription ) && method_exists( $subscription, 'is_active' ) ) {
      try {
        if ( $subscription->is_active() ) {
          return true;
        }
      } catch ( \Throwable $e ) {
        // Ignore method failures.
      }
    }

    return false;
  }

  /**
   * Retrieve the status string from a subscription entry.
   *
   * @param mixed $subscription Subscription entry.
   * @return string
   */
  private function get_subscription_status( $subscription ) {
    if ( is_object( $subscription ) && isset( $subscription->status ) ) {
      return (string) $subscription->status;
    }

    if ( is_object( $subscription ) ) {
      foreach ( [ 'status', 'get_status' ] as $method ) {
        if ( method_exists( $subscription, $method ) ) {
          try {
            return (string) $subscription->{$method}();
          } catch ( \Throwable $e ) {
            // Ignore and continue.
          }
        }
      }
    }

    if ( is_array( $subscription ) && isset( $subscription['status'] ) ) {
      return (string) $subscription['status'];
    }

    return '';
  }

  /**
   * Determine the paid-through timestamp for a subscription.
   *
   * @param mixed $subscription Subscription entry.
   * @return int Timestamp when access expires.
   */
  private function get_subscription_paid_through_timestamp( $subscription ) {
    $candidates = [];

    if ( is_object( $subscription ) ) {
      foreach ( [ 'get_paid_through', 'get_paid_thru', 'get_expires_at', 'get_expires_at_gmt' ] as $method ) {
        if ( method_exists( $subscription, $method ) ) {
          try {
            $candidates[] = $subscription->{$method}();
          } catch ( \Throwable $e ) {
            // Ignore individual method failures.
          }
        }
      }
    }

    $properties = [ 'paid_through', 'paid_thru', 'paidthrough', 'expires_at', 'expire_at', 'expires_on', 'expires', 'expiration_date', 'expires_at_gmt' ];

    foreach ( $properties as $property ) {
      if ( is_object( $subscription ) && isset( $subscription->{$property} ) ) {
        $candidates[] = $subscription->{$property};
      } elseif ( is_array( $subscription ) && isset( $subscription[ $property ] ) ) {
        $candidates[] = $subscription[ $property ];
      }
    }

    $timestamp = 0;
    foreach ( $candidates as $candidate ) {
      $candidate_ts = $this->normalize_timestamp_value( $candidate );
      if ( $candidate_ts > $timestamp ) {
        $timestamp = $candidate_ts;
      }
    }

    return $timestamp;
  }

  /**
   * Normalize a potential timestamp value returned by MemberPress.
   *
   * @param mixed $value Raw value.
   * @return int
   */
  private function normalize_timestamp_value( $value ) {
    if ( $value instanceof \DateTimeInterface ) {
      return (int) $value->getTimestamp();
    }

    if ( is_object( $value ) && method_exists( $value, 'format' ) ) {
      try {
        $formatted = $value->format( 'U' );
        if ( is_numeric( $formatted ) ) {
          return (int) $formatted;
        }
      } catch ( \Throwable $e ) {
        // Ignore format failures.
      }
    }

    if ( is_numeric( $value ) ) {
      $int_value = (int) $value;
      if ( $int_value > 0 ) {
        return $int_value;
      }
    }

    if ( is_string( $value ) ) {
      $value = trim( $value );
      if ( '' === $value ) {
        return 0;
      }

      if ( '0000-00-00 00:00:00' === $value || '0000-00-00' === $value ) {
        return PHP_INT_MAX;
      }

      $timestamp = strtotime( $value );
      if ( $timestamp && $timestamp > 0 ) {
        return $timestamp;
      }
    }

    return 0;
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
    if ( ! $should_render ) {
      return false;
    }

    try {
      $settings = ( is_object( $element ) && method_exists( $element, 'get_settings_for_display' ) )
        ? (array) $element->get_settings_for_display()
        : [];
    } catch ( \Throwable $e ) {
      if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'MTX Visibility Error: Failed to read element settings - ' . $e->getMessage() );
      }
      return false;
    }

    if ( ! $this->parse_boolean_setting( $settings['mtx_mepr_enable'] ?? '' ) ) {
      return $should_render;
    }

    $invert  = $this->parse_boolean_setting( $settings['mtx_mepr_invert'] ?? '' );
    $require = $this->parse_require_setting( $settings['mtx_mepr_require'] ?? '' );

    $ids = [];
    if ( ! empty( $settings['mtx_mepr_ids'] ) ) {
      if ( is_string( $settings['mtx_mepr_ids'] ) ) {
        $raw_ids = array_map( 'trim', explode( ',', $settings['mtx_mepr_ids'] ) );
      } elseif ( is_array( $settings['mtx_mepr_ids'] ) ) {
        $raw_ids = $settings['mtx_mepr_ids'];
      } else {
        $raw_ids = [];
      }

      foreach ( $raw_ids as $raw_id ) {
        $id = absint( $raw_id );
        if ( $id > 0 ) {
          $ids[] = $id;
        }
      }
    }

    self::$has_restricted_elements = true;
    $this->mark_document_as_restricted( $element );
    $this->send_nocache_headers();

    if ( $this->is_elementor_edit_or_preview() ) {
      $this->schedule_editor_marker( $element, $this->build_editor_marker_message( $invert, $ids ) );
      return true;
    }

    if ( ! $this->is_plugin_ready() ) {
      return $invert ? $should_render : false;
    }

    $authorized = false;

    try {
      $authorized = $this->user_is_authorized( get_current_user_id(), $ids, $require );
    } catch ( \Throwable $e ) {
      if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'MTX Visibility Error: Authorization check failed - ' . $e->getMessage() );
      }
      $authorized = false;
    }

    $display = $invert ? ! $authorized : $authorized;

    return $display ? $should_render : false;
  }

  /**
   * Determine if Elementor is currently in edit or preview mode.
   *
   * @return bool
   */
  private function is_elementor_edit_or_preview() {
    if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
      return false;
    }

    $plugin = \Elementor\Plugin::$instance;

    if ( isset( $plugin->editor ) && is_object( $plugin->editor ) && method_exists( $plugin->editor, 'is_edit_mode' ) ) {
      try {
        if ( $plugin->editor->is_edit_mode() ) {
          return true;
        }
      } catch ( \Throwable $e ) {
        // Ignore editor detection errors.
      }
    }

    if ( isset( $plugin->preview ) && is_object( $plugin->preview ) && method_exists( $plugin->preview, 'is_preview_mode' ) ) {
      try {
        if ( $plugin->preview->is_preview_mode() ) {
          return true;
        }
      } catch ( \Throwable $e ) {
        // Ignore preview detection errors.
      }
    }

    return false;
  }

  /**
   * Build the editor marker message used in Elementor edit/preview modes.
   *
   * @param bool  $invert Whether invert (non-member view) is enabled.
   * @param array $ids    Plan IDs associated with the element.
   * @return string
   */
  private function build_editor_marker_message( $invert, array $ids ) {
    $label = $invert
      ? 'MTX Visibility: Non-member preview (invert enabled)'
      : 'MTX Visibility: Members-only preview';

    if ( ! empty( $ids ) ) {
      $label .= ' | Plans: ' . implode( ', ', array_map( 'intval', $ids ) );
    } else {
      $label .= ' | Any active membership';
    }

    return $label;
  }

  /**
   * Schedule an editor marker comment for a specific Elementor element.
   *
   * @param \Elementor\Element_Base $element Element instance.
   * @param string                   $message Comment message.
   * @return void
   */
  private function schedule_editor_marker( $element, $message ) {
    if ( empty( $message ) ) {
      return;
    }

    $element_id = $this->get_element_unique_id( $element );
    if ( empty( $element_id ) ) {
      return;
    }

    self::$editor_marker_comments[ $element_id ] = $message;
  }

  /**
   * Output editor marker comments before rendering gated elements.
   *
   * @param \Elementor\Element_Base $element Element instance.
   * @return void
   */
  public function maybe_output_editor_marker( $element ) {
    if ( empty( self::$editor_marker_comments ) || ! $this->is_elementor_edit_or_preview() ) {
      if ( ! $this->is_elementor_edit_or_preview() ) {
        self::$editor_marker_comments = [];
      }
      return;
    }

    $element_id = $this->get_element_unique_id( $element );
    if ( empty( $element_id ) || ! isset( self::$editor_marker_comments[ $element_id ] ) ) {
      return;
    }

    echo "\n<!-- " . esc_html( self::$editor_marker_comments[ $element_id ] ) . " -->\n";
    unset( self::$editor_marker_comments[ $element_id ] );
  }

  /**
   * Retrieve a stable identifier for an Elementor element.
   *
   * @param \Elementor\Element_Base $element Element instance.
   * @return string
   */
  private function get_element_unique_id( $element ) {
    if ( ! is_object( $element ) ) {
      return '';
    }

    if ( method_exists( $element, 'get_id' ) ) {
      $id = $element->get_id();
      if ( ! empty( $id ) ) {
        return (string) $id;
      }
    }

    if ( method_exists( $element, 'get_unique_id' ) ) {
      $id = $element->get_unique_id();
      if ( ! empty( $id ) ) {
        return (string) $id;
      }
    }

    if ( isset( $element->id ) && ! empty( $element->id ) ) {
      return (string) $element->id;
    }

    if ( method_exists( $element, 'get_unique_name' ) ) {
      $id = $element->get_unique_name();
      if ( ! empty( $id ) ) {
        return (string) $id;
      }
    }

    return '';
  }

  /**
   * Mark the current Elementor document as containing restricted content.
   *
   * @param \Elementor\Element_Base $element Elementor element instance
   * @return void
   */
  private function mark_document_as_restricted( $element ) {
    $doc_id = null;

    if ( is_object( $element ) ) {
      if ( method_exists( $element, 'get_document' ) ) {
        $document = $element->get_document();
        if ( $document && is_object( $document ) ) {
          if ( method_exists( $document, 'get_main_id' ) ) {
            $doc_id = $document->get_main_id();
          } elseif ( method_exists( $document, 'get_id' ) ) {
            $doc_id = $document->get_id();
          }
        }
      }

      if ( null === $doc_id && method_exists( $element, 'get_main_id' ) ) {
        $doc_id = $element->get_main_id();
      }

      if ( null === $doc_id && method_exists( $element, 'get_id' ) ) {
        $doc_id = $element->get_id();
      }
    }

    if ( null === $doc_id && function_exists( 'get_the_ID' ) ) {
      $doc_id = get_the_ID();
    }

    $doc_id = absint( $doc_id );
    if ( $doc_id > 0 ) {
      self::$document_visibility_map[ $doc_id ] = true;
    }
  }

  /**
   * Disable Elementor caching for documents that include restricted elements.
   *
   * @param bool $should_cache Whether Elementor plans to cache the document output.
   * @param int|null $post_id Document/post ID being cached.
   * @return bool
   */
  public function maybe_disable_elementor_cache( $should_cache, $post_id = null ) {
    if ( ! $should_cache ) {
      return $should_cache;
    }

    if ( self::$has_restricted_elements ) {
      $this->send_nocache_headers();
      return false;
    }

    $post_id = absint( $post_id );
    if ( $post_id <= 0 && function_exists( 'get_the_ID' ) ) {
      $post_id = absint( get_the_ID() );
    }
    if ( $post_id <= 0 && function_exists( 'get_queried_object_id' ) ) {
      $post_id = absint( get_queried_object_id() );
    }

    if ( $post_id > 0 && $this->document_has_visibility_rules( $post_id ) ) {
      self::$has_restricted_elements = true;
      $this->send_nocache_headers();
      return false;
    }

    return $should_cache;
  }

  /**
   * Ensure responses with restricted content are not cached by proxies or browsers.
   */
  private function send_nocache_headers() {
    if ( self::$nocache_headers_sent ) {
      return;
    }

    if ( headers_sent() ) {
      self::$nocache_headers_sent = true;
      return;
    }

    // Ensure caches vary by authentication state when restricted content exists.
    header( 'Vary: Cookie', false );

    if ( is_user_logged_in() && function_exists( 'nocache_headers' ) ) {
      nocache_headers();
    }

    self::$nocache_headers_sent = true;
  }

  /**
   * Determine whether an Elementor document contains MTX visibility rules.
   *
   * @param int $post_id Document/post ID.
   * @return bool
   */
  private function document_has_visibility_rules( $post_id ) {
    $post_id = absint( $post_id );
    if ( $post_id <= 0 ) {
      return false;
    }

    if ( array_key_exists( $post_id, self::$document_visibility_map ) ) {
      return (bool) self::$document_visibility_map[ $post_id ];
    }

    // Prevent recursive loops by setting a default before inspection.
    self::$document_visibility_map[ $post_id ] = false;

    $has_rules = false;

    if ( class_exists( '\\Elementor\\Plugin' ) ) {
      $documents = isset( \Elementor\Plugin::$instance->documents ) ? \Elementor\Plugin::$instance->documents : null;
      if ( $documents && method_exists( $documents, 'get' ) ) {
        $document = $documents->get( $post_id );
        if ( $document && is_object( $document ) && method_exists( $document, 'get_elements_data' ) ) {
          $elements = $document->get_elements_data();
          if ( is_array( $elements ) ) {
            $has_rules = $this->elements_have_visibility_rules( $elements );
          }
        }
      }
    }

    if ( ! $has_rules ) {
      $raw_data = get_post_meta( $post_id, '_elementor_data', true );
      if ( is_string( $raw_data ) && $raw_data !== '' ) {
        $decoded = json_decode( $raw_data, true );
        if ( is_array( $decoded ) ) {
          $has_rules = $this->elements_have_visibility_rules( $decoded );
        }
      }
    }

    self::$document_visibility_map[ $post_id ] = $has_rules;
    return $has_rules;
  }

  /**
   * Recursively inspect Elementor element data for MTX visibility rules.
   *
   * @param array $elements Elementor element definitions.
   * @return bool
   */
  private function elements_have_visibility_rules( $elements ) {
    if ( empty( $elements ) || ! is_array( $elements ) ) {
      return false;
    }

    foreach ( $elements as $element ) {
      if ( ! is_array( $element ) ) {
        continue;
      }

      $settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
      if ( $this->parse_boolean_setting( $settings['mtx_mepr_enable'] ?? '' ) ) {
        return true;
      }

      if ( ! empty( $element['template_id'] ) ) {
        $template_id = absint( $element['template_id'] );
        if ( $template_id > 0 && $this->document_has_visibility_rules( $template_id ) ) {
          return true;
        }
      }

      if ( ! empty( $element['elements'] ) && $this->elements_have_visibility_rules( $element['elements'] ) ) {
        return true;
      }
    }

    return false;
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

    $active_ids = $this->get_active_plan_ids_for_user( (int) $user_id );

    // If no IDs specified: "any active membership?"
    if ( empty( $ids ) ) {
      return ! empty( $active_ids );
    }

    // With specific IDs: set comparison against active_ids
    $ids = array_filter( array_map( 'absint', (array) $ids ) );
    if ( empty( $ids ) ) {
      return ! empty( $active_ids );
    }

    if ( empty( $active_ids ) ) {
      return false;
    }

    $ok = ( $require === 'all' )
      ? empty( array_diff( $ids, $active_ids ) )       // must have ALL IDs
      : ( count( array_intersect( $ids, $active_ids ) ) > 0 ); // ANY overlap

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
   * @param mixed $subscription Subscription object or ID
   */
  public function clear_user_cache_from_sub( $old_status, $new_status, $subscription ) {
    $this->clear_user_cache_from_sub_id( $subscription );
  }

  /**
   * Clear user cache from subscription ID
   *
   * @param mixed $sub_id Subscription object or ID
   */
  public function clear_user_cache_from_sub_id( $sub_id ) {
    $user_id = null;

    $subscription_class_exists = class_exists( '\MeprSubscription' );

    if ( $subscription_class_exists && $sub_id instanceof \MeprSubscription ) {
      $user_id = $sub_id->user_id;
    } elseif ( is_object( $sub_id ) && isset( $sub_id->user_id ) ) {
      $user_id = $sub_id->user_id;
    } elseif ( $subscription_class_exists && is_numeric( $sub_id ) && (int) $sub_id > 0 ) {
      $subscription = new \MeprSubscription( (int) $sub_id );
      if ( is_object( $subscription ) && isset( $subscription->user_id ) ) {
        $user_id = $subscription->user_id;
      }
    }

    if ( empty( $user_id ) ) {
      return;
    }

    $this->clear_user_cache( (int) $user_id );
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
      'member_cache_enabled' => false,
      'plan_cache_cached' => self::$plan_ids_cache !== null,
      'plan_cache_size' => self::$plan_ids_cache ? count( self::$plan_ids_cache ) : 0,
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
   if ( ! $is_logged_in ) {
     $active_plan_ids = [];
   } elseif ( $active_plan_ids === null ) {
     $active_plan_ids = [];
     if ( class_exists( 'MTX_Elementor_MemberPress_Visibility' ) ) {
       $visibility = MTX_Elementor_MemberPress_Visibility::instance();
       if ( $visibility && method_exists( $visibility, 'get_active_plan_ids_for_user' ) ) {
         try {
           $active_plan_ids = $visibility->get_active_plan_ids_for_user( $user_id );
         } catch ( \Throwable $e ) {
           $active_plan_ids = [];
         }
       }
     }
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
