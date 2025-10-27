<?php
/**
 * Plugin Name: MTX Visibility
 * Description: Deterministic, cache-agnostic visibility controls for Elementor + MemberPress. Preserves existing settings and menu meta.
 * Version:     2.0.0
 * Author:      Making The Impact, LLC
 * License:     GPL-2.0+
 *
 * Requires Plugins: elementor
 *
 * Tested with:
 * - Elementor Pro 3.28.4
 * - Elementor 3.32.2
 * - MemberPress Pro 1.12.9
 * - User Switching 1.10.0
 */

// Global state to coordinate between Elementor and HTTP layer
if ( ! class_exists( 'MTX_Visibility_State' ) ) {
    final class MTX_Visibility_State {
        public static bool $has_gated_elements = false;
    }
}

// If true, any malformed/unknown state fails closed (DENY)
if ( ! defined( 'MTX_VIS_FAIL_CLOSED' ) ) {
    define( 'MTX_VIS_FAIL_CLOSED', true );
}

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MTX_Visibility_Plugin {
    public const VERSION = '2.0.0';

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ], 11 );
    }

    public function init() {
        MTX_Visibility_Engine::boot();
        MTX_Visibility_HTTP::boot();

        if ( did_action( 'elementor/loaded' ) ) {
            MTX_Visibility_Elementor::boot();
        } else {
            add_action( 'elementor/loaded', [ MTX_Visibility_Elementor::class, 'boot' ] );
        }

        MTX_Visibility_Menus::boot();
    }
}

class MTX_Visibility_Engine {
    private static ?self $instance = null;

    public static function boot(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function instance(): self {
        return self::boot();
    }

    public function is_authorized( int $user_id, array $required_ids, string $require ): bool {
        $require = ( strtolower( $require ) === 'all' ) ? 'all' : 'any';

        // Must be logged in and MP available
        if ( $user_id <= 0 || ! is_user_logged_in() || ! class_exists( '\\MeprUser' ) ) {
            return false;
        }

        try {
            $user = new \MeprUser( $user_id );
        } catch ( \Throwable $e ) {
            return false;
        }

        // If specific Product IDs are provided, check active access to each product.
        if ( ! empty( $required_ids ) ) {
            $results = [];
            foreach ( $required_ids as $product_id ) {
                $results[] = $this->has_active_access_to_product( $user, (int) $product_id );
            }

            return ( $require === 'all' )
                ? ! in_array( false, $results, true )
                : in_array( true, $results, true );
        }

        // No IDs provided => treat as "user has any active membership"
        return $this->user_has_any_active_membership( $user );
    }

    public function get_active_membership_ids( int $user_id ): array {
        if ( $user_id <= 0 || ! class_exists( '\\MeprUser' ) ) {
            return [];
        }

        try {
            $user = new \MeprUser( $user_id );
        } catch ( \Throwable $e ) {
            return [];
        }

        $ids = [];

        // Prefer MP's "active product subscriptions" when available
        try {
            if ( method_exists( $user, 'active_product_subscriptions' ) ) {
                $subs = (array) $user->active_product_subscriptions();
                foreach ( $subs as $sub ) {
                    $pid = $this->extract_product_id_from_subscription( $sub );
                    if ( $pid > 0 ) {
                        $ids[] = $pid;
                    }
                }
            } else if ( method_exists( $user, 'subscriptions' ) ) {
                $subs = (array) $user->subscriptions();
                foreach ( $subs as $sub ) {
                    if ( $this->subscription_is_active_like( $sub ) ) {
                        $pid = $this->extract_product_id_from_subscription( $sub );
                        if ( $pid > 0 ) {
                            $ids[] = $pid;
                        }
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // ignore and fall through
        }

        return array_values( array_unique( $ids ) );
    }

    public function resolve_visibility( array $settings, int $user_id ): bool {
        $enable = ! empty( $settings['enable'] );

        // If engine is asked to resolve but settings are weird and fail-closed is on, deny.
        if ( MTX_VIS_FAIL_CLOSED && $enable && ! isset( $settings['require'] ) ) {
            return false;
        }

        if ( ! $enable ) {
            return true;
        }

        $ids     = $this->sanitize_ids( $settings['ids'] ?? [] );
        $require = isset( $settings['require'] ) ? (string) $settings['require'] : 'any';
        $invert  = ! empty( $settings['invert'] );

        $authorized = $this->is_authorized( $user_id, $ids, $require );
        $decision   = $invert ? ! $authorized : $authorized;

        if ( defined( 'MTX_VIS_DEBUG' ) && true === MTX_VIS_DEBUG ) {
            error_log( sprintf(
                '[MTX] uid=%d enable=%s ids=%s require=%s invert=%s => %s',
                $user_id,
                $enable ? '1' : '0',
                implode( ',', $ids ),
                strtolower( $require ) === 'all' ? 'all' : 'any',
                $invert ? '1' : '0',
                $decision ? 'ALLOW' : 'DENY'
            ) );
        }

        return $decision;
    }

    private function extract_product_ids_from_subscriptions( array $subs ): array {
        $ids = [];

        foreach ( $subs as $sub ) {
            $product_id = null;

            if ( is_object( $sub ) ) {
                if ( isset( $sub->product_id ) ) {
                    $product_id = $sub->product_id;
                } elseif ( method_exists( $sub, 'product_id' ) ) {
                    try {
                        $product_id = $sub->product_id();
                    } catch ( \Throwable $e ) {
                        $product_id = null;
                    }
                }

                if ( null === $product_id && isset( $sub->product ) && is_object( $sub->product ) && isset( $sub->product->ID ) ) {
                    $product_id = $sub->product->ID;
                }

                if ( null === $product_id && method_exists( $sub, 'product' ) ) {
                    try {
                        $product = $sub->product();
                        if ( is_object( $product ) && isset( $product->ID ) ) {
                            $product_id = $product->ID;
                        }
                    } catch ( \Throwable $e ) {
                        $product_id = null;
                    }
                }
            } elseif ( is_array( $sub ) ) {
                if ( isset( $sub['product_id'] ) ) {
                    $product_id = $sub['product_id'];
                } elseif ( isset( $sub['ID'] ) ) {
                    $product_id = $sub['ID'];
                }
            } elseif ( is_numeric( $sub ) ) {
                $product_id = $sub;
            }

            $product_id = (int) $product_id;

            if ( $product_id > 0 ) {
                $ids[] = $product_id;
            }
        }

        return array_values( array_unique( $ids ) );
    }

    private function sanitize_ids( $raw ): array {
        if ( is_string( $raw ) ) {
            $raw = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        }

        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        $ids = [];

        foreach ( $raw as $value ) {
            $value = (int) $value;

            if ( $value > 0 ) {
                $ids[] = $value;
            }
        }

        return array_values( array_unique( $ids ) );
    }

    /** True if user has active (or canceled-but-paid-through) access to this Product ID */
    private function has_active_access_to_product( \MeprUser $user, int $product_id ): bool {
        if ( $product_id <= 0 ) return false;

        // Try active subscriptions accessor first
        try {
            if ( method_exists( $user, 'active_product_subscriptions' ) ) {
                $subs = (array) $user->active_product_subscriptions();
                foreach ( $subs as $sub ) {
                    if ( $product_id === $this->extract_product_id_from_subscription( $sub ) ) {
                        // Active list implies active => allow
                        return true;
                    }
                }
            }
        } catch ( \Throwable $e ) { /* continue */ }

        // Fallback: full subscriptions list with our own "active-like" check
        try {
            if ( method_exists( $user, 'subscriptions' ) ) {
                $subs = (array) $user->subscriptions();
                foreach ( $subs as $sub ) {
                    $pid = $this->extract_product_id_from_subscription( $sub );
                    if ( $pid === $product_id && $this->subscription_is_active_like( $sub ) ) {
                        return true;
                    }
                }
            }
        } catch ( \Throwable $e ) { /* continue */ }

        return false;
    }

    /** True if user has any active (or canceled-but-paid-through) membership */
    private function user_has_any_active_membership( \MeprUser $user ): bool {
        try {
            if ( method_exists( $user, 'active_product_subscriptions' ) ) {
                $subs = (array) $user->active_product_subscriptions();
                if ( ! empty( $subs ) ) return true;
            }
            if ( method_exists( $user, 'subscriptions' ) ) {
                $subs = (array) $user->subscriptions();
                foreach ( $subs as $sub ) {
                    if ( $this->subscription_is_active_like( $sub ) ) {
                        return true;
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // conservative default below
        }
        return false;
    }

    /** Normalize "active-enough" status: active OR canceled but paid_through in the future */
    private function subscription_is_active_like( $sub ): bool {
        $status = '';
        $paid_through_ts = 0;

        // Status (property or method)
        if ( is_object( $sub ) ) {
            if ( isset( $sub->status ) ) {
                $status = (string) $sub->status;
            } elseif ( method_exists( $sub, 'status' ) ) {
                try { $status = (string) $sub->status(); } catch ( \Throwable $e ) {}
            }
        } elseif ( is_array( $sub ) && isset( $sub['status'] ) ) {
            $status = (string) $sub['status'];
        }

        // Paid-through / expires (several possible shapes)
        if ( is_object( $sub ) ) {
            if ( isset( $sub->paid_through ) ) {
                $paid_through_ts = is_numeric( $sub->paid_through ) ? (int) $sub->paid_through : strtotime( (string) $sub->paid_through );
            } elseif ( method_exists( $sub, 'paid_through' ) ) {
                try { $paid_through_ts = strtotime( (string) $sub->paid_through() ); } catch ( \Throwable $e ) {}
            } elseif ( isset( $sub->expires_at ) ) {
                $paid_through_ts = is_numeric( $sub->expires_at ) ? (int) $sub->expires_at : strtotime( (string) $sub->expires_at );
            }
        } elseif ( is_array( $sub ) ) {
            if ( isset( $sub['paid_through'] ) ) {
                $paid_through_ts = is_numeric( $sub['paid_through'] ) ? (int) $sub['paid_through'] : strtotime( (string) $sub['paid_through'] );
            } elseif ( isset( $sub['expires_at'] ) ) {
                $paid_through_ts = is_numeric( $sub['expires_at'] ) ? (int) $sub['expires_at'] : strtotime( (string) $sub['expires_at'] );
            }
        }

        $status_lc = strtolower( $status );
        $now = time();

        $is_active      = ( false !== strpos( $status_lc, 'active' ) );
        $is_canceled    = ( false !== strpos( $status_lc, 'cancel' ) );
        $paid_in_future = ( $paid_through_ts > $now );

        return ( $is_active || ( $is_canceled && $paid_in_future ) );
    }

    /** Best-effort extraction of product_id from a subscription object/array/int */
    private function extract_product_id_from_subscription( $sub ): int {
        $pid = null;

        if ( is_object( $sub ) ) {
            if ( isset( $sub->product_id ) ) {
                $pid = $sub->product_id;
            } elseif ( method_exists( $sub, 'product_id' ) ) {
                try { $pid = $sub->product_id(); } catch ( \Throwable $e ) {}
            } elseif ( isset( $sub->product ) && is_object( $sub->product ) && isset( $sub->product->ID ) ) {
                $pid = $sub->product->ID;
            } elseif ( method_exists( $sub, 'product' ) ) {
                try {
                    $product = $sub->product();
                    if ( is_object( $product ) && isset( $product->ID ) ) $pid = $product->ID;
                } catch ( \Throwable $e ) {}
            }
        } elseif ( is_array( $sub ) ) {
            if ( isset( $sub['product_id'] ) ) {
                $pid = $sub['product_id'];
            } elseif ( isset( $sub['ID'] ) ) {
                $pid = $sub['ID'];
            }
        } elseif ( is_numeric( $sub ) ) {
            $pid = $sub;
        }

        $pid = (int) $pid;
        return ( $pid > 0 ) ? $pid : 0;
    }
}

class MTX_Visibility_Elementor {

    /** When present in the URL (…?mtxdiag=1), show badges and log all decisions */
    private static function diag_mode(): bool {
        return isset( $_GET['mtxdiag'] ) && $_GET['mtxdiag'] == '1';
    }

    /** Render a small badge next to the element in diag mode */
    private static function diag_badge( $element, array $info ): void {
        if ( ! self::diag_mode() ) {
            return;
        }

        $name = method_exists( $element, 'get_name' ) ? $element->get_name() : 'unknown';
        $id   = method_exists( $element, 'get_id' ) ? $element->get_id() : 'n/a';
        $text = sprintf(
            'MTX[%s #%s] %s | enable=%s ids="%s" require=%s invert=%s | user=%d',
            esc_html( $name ),
            esc_html( $id ),
            $info['decision'] ? 'ALLOW' : 'DENY',
            $info['enable'] ? '1' : '0',
            esc_html( is_string( $info['ids'] ) ? $info['ids'] : json_encode( $info['ids'] ) ),
            $info['require'] === 'all' ? 'all' : 'any',
            $info['invert'] ? '1' : '0',
            (int) get_current_user_id()
        );
        echo '<div style="position:relative;z-index:9999;"><div style="display:inline-block;padding:2px 6px;margin:2px 0;border:1px solid #c00;border-radius:3px;font:12px/1.2 monospace;background:#fff;color:#c00;">'
            . $text . '</div></div>';
    }

    /** Normalize Elementor switchers: accept 'yes' | '1' | 1 | true */
    private static function is_switch_on( $val ): bool {
        return ( $val === 'yes' || $val === '1' || $val === 1 || $val === true );
    }

    /** Output a small HTML comment for admins so we can see decisions inline */
    private static function admin_comment( string $text ): void {
        if ( current_user_can( 'manage_options' ) ) {
            echo "\n<!-- MTX {$text} -->\n";
        }
    }

    /** If an element has no MTX controls, inherit from closest parent that does */
    private static function inherit_parent_settings( $element, array $settings ): array {
        // If element already has enable set explicitly, keep it.
        $has_own = isset( $settings['mtx_mepr_enable'] ) && $settings['mtx_mepr_enable'] !== '';
        if ( $has_own ) {
            return $settings;
        }

        // Walk up parents to find first with our controls
        if ( ! method_exists( $element, 'get_parent' ) ) {
            return $settings;
        }
        $parent = $element->get_parent();
        while ( $parent && is_object( $parent ) ) {
            $ps = method_exists( $parent, 'get_settings_for_display' )
                ? (array) $parent->get_settings_for_display()
                : (array) ( method_exists( $parent, 'get_settings' ) ? $parent->get_settings() : [] );
            if ( isset( $ps['mtx_mepr_enable'] ) && $ps['mtx_mepr_enable'] !== '' ) {
                // Inherit these down
                $settings['mtx_mepr_enable']  = $ps['mtx_mepr_enable'];
                $settings['mtx_mepr_ids']     = $settings['mtx_mepr_ids']     ?? ( $ps['mtx_mepr_ids'] ?? '' );
                $settings['mtx_mepr_require'] = $settings['mtx_mepr_require'] ?? ( $ps['mtx_mepr_require'] ?? 'any' );
                $settings['mtx_mepr_invert']  = $settings['mtx_mepr_invert']  ?? ( $ps['mtx_mepr_invert'] ?? '' );
                break;
            }
            $parent = method_exists( $parent, 'get_parent' ) ? $parent->get_parent() : null;
        }
        return $settings;
    }

    public static function boot(): void {
        add_action( 'elementor/element/common/section_advanced/after_section_end', [ __CLASS__, 'register_controls' ], 10, 1 );
        add_action( 'elementor/element/section/section_advanced/after_section_end', [ __CLASS__, 'register_controls' ], 10, 1 );
        add_action( 'elementor/element/column/section_advanced/after_section_end', [ __CLASS__, 'register_controls' ], 10, 1 );
        add_action( 'elementor/element/container/section_layout/after_section_end', [ __CLASS__, 'register_controls' ], 10, 1 );

        // One universal filter that covers ALL element types (core + third-party)
        add_filter( 'elementor/frontend/element/should_render', [ __CLASS__, 'should_render' ], 999, 2 );

        // Tag gated elements in the final HTML so we can scrub safely for guests
        add_action( 'elementor/frontend/before_render', [ __CLASS__, 'before_render' ], 9 );
        add_action( 'elementor/frontend/after_render',  [ __CLASS__, 'after_render' ], 1000 );
    }

    /** Compute MTX settings (with inheritance) once for tagging */
    private static function compute_mtx_settings( $element ): array {
        $settings = method_exists( $element, 'get_settings_for_display' )
            ? (array) $element->get_settings_for_display()
            : (array) ( method_exists( $element, 'get_settings' ) ? $element->get_settings() : [] );

        // inherit from parent if needed
        $settings = self::inherit_parent_settings( $element, $settings );

        $raw_enable  = $settings['mtx_mepr_enable']  ?? '';
        $raw_invert  = $settings['mtx_mepr_invert']  ?? '';
        $ids         = $settings['mtx_mepr_ids']     ?? '';
        $require     = $settings['mtx_mepr_require'] ?? 'any';

        $enable = self::is_switch_on( $raw_enable );
        $invert = self::is_switch_on( $raw_invert );

        // auto-enable if IDs present but switch missing
        if ( ! $enable && is_string( $ids ) && trim( $ids ) !== '' ) {
            $enable = true;
        }

        return [
            'enable'  => $enable,
            'invert'  => $invert,
            'ids'     => is_string( $ids ) ? $ids : '',
            'require' => ( $require === 'all' ? 'all' : 'any' ),
        ];
    }

    /** Print a start marker for gated elements so we can scrub on guest responses */
    public static function before_render( $element ): void {
        if ( self::is_edit_mode() || ! is_object( $element ) ) {
            return;
        }

        $cfg = self::compute_mtx_settings( $element );
        if ( ! $cfg['enable'] ) {
            return;
        }

        // mark page gated for header rules
        MTX_Visibility_State::$has_gated_elements = true;

        // Tag only if actually “members-only” (invert=0) OR “guest-only” (invert=1)
        $name = method_exists( $element, 'get_name' ) ? $element->get_name() : 'unknown';
        $id   = method_exists( $element, 'get_id' )   ? $element->get_id()   : 'n/a';

        $payload = [
            'name'    => $name,
            'id'      => $id,
            'enable'  => $cfg['enable'] ? 1 : 0,
            'invert'  => $cfg['invert'] ? 1 : 0,
            'require' => $cfg['require'],
            'ids'     => $cfg['ids'],
        ];

        // Emit a compact JSON we can match later
        echo "\n<!-- MTX:GATED START " . wp_json_encode( $payload ) . " -->\n";
    }

    /** Close the marker so we can remove the whole block if needed */
    public static function after_render( $element ): void {
        if ( self::is_edit_mode() || ! is_object( $element ) ) {
            return;
        }

        $cfg = self::compute_mtx_settings( $element );
        if ( ! $cfg['enable'] ) {
            return;
        }

        echo "\n<!-- MTX:GATED END -->\n";
    }

    public static function register_controls( $element ): void {
        if ( ! class_exists( '\\Elementor\\Controls_Manager' ) ) {
            return;
        }

        $element->start_controls_section(
            'mtx_vis_section',
            [
                'label' => __( 'MTX Visibility', 'mtx-visibility' ),
                'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
            ]
        );

        $element->add_control(
            'mtx_mepr_enable',
            [
                'label'        => __( 'Enable MemberPress Visibility', 'mtx-visibility' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default'      => '',
            ]
        );

        $element->add_control(
            'mtx_mepr_ids',
            [
                'label'       => __( 'MemberPress Product IDs (comma-separated)', 'mtx-visibility' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'placeholder' => '123, 456',
                'condition'   => [ 'mtx_mepr_enable' => 'yes' ],
            ]
        );

        $element->add_control(
            'mtx_mepr_require',
            [
                'label'     => __( 'Require', 'mtx-visibility' ),
                'type'      => \Elementor\Controls_Manager::SELECT,
                'default'   => 'any',
                'options'   => [
                    'any' => __( 'Any of the IDs', 'mtx-visibility' ),
                    'all' => __( 'All of the IDs', 'mtx-visibility' ),
                ],
                'condition' => [ 'mtx_mepr_enable' => 'yes' ],
            ]
        );

        $element->add_control(
            'mtx_mepr_invert',
            [
                'label'        => __( 'Invert (show to NON-members of the IDs)', 'mtx-visibility' ),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default'      => '',
                'condition'    => [ 'mtx_mepr_enable' => 'yes' ],
            ]
        );

        $element->end_controls_section();
    }

    public static function should_render( $should_render, $element ) {
        if ( self::is_edit_mode() ) {
            return $should_render;
        }

        if ( ! $should_render ) {
            return false;
        }

        $cfg = self::compute_mtx_settings( $element );

        $enable  = $cfg['enable'];
        $invert  = $cfg['invert'];
        $ids     = $cfg['ids'];
        $require = $cfg['require'];

        // Extra hardening for the Post Content widget: deny guests if inherited says so.
        $el_name = method_exists( $element, 'get_name' ) ? $element->get_name() : '';
        if ( $el_name === 'post-content' && $enable && ! $invert && ! is_user_logged_in() ) {
            self::admin_comment( 'DENY post-content via inherited container settings' );
            self::diag_badge(
                $element,
                [
                    'decision' => false,
                    'enable'   => $enable,
                    'ids'      => $ids,
                    'require'  => $require,
                    'invert'   => $invert,
                ]
            );
            return false;
        }

        // If visibility is enabled on this element, mark page gated (for no-cache header hook)
        if ( $enable ) {
            MTX_Visibility_State::$has_gated_elements = true;
        }

        // Fail-closed: if enabled, not inverted, and user is NOT logged in, deny immediately.
        // (Prevents any chance of a guest seeing a members-only element.)
        if ( $enable && ! $invert && ! is_user_logged_in() ) {
            self::admin_comment( 'DENY (guest short-circuit) enable=1 invert=0' );
            return false;
        }

        $decision = MTX_Visibility_Engine::instance()->resolve_visibility(
            [
                'enable'  => $enable,
                'ids'     => $ids,
                'require' => $require,
                'invert'  => $invert,
            ],
            get_current_user_id()
        );

        // Admin-only inline marker so you can see the computed decision & inputs
        self::admin_comment(
            sprintf(
                'decision=%s enable=%s ids="%s" require=%s invert=%s user=%d',
                $decision ? 'ALLOW' : 'DENY',
                $enable ? '1' : '0',
                is_string( $ids ) ? $ids : json_encode( $ids ),
                $require === 'all' ? 'all' : 'any',
                $invert ? '1' : '0',
                (int) get_current_user_id()
            )
        );

        // Visible badge in ?mtxdiag=1 mode so we can see what was decided for THIS element
        self::diag_badge(
            $element,
            [
                'decision' => $decision,
                'enable'   => $enable,
                'ids'      => $ids,
                'require'  => $require === 'all' ? 'all' : 'any',
                'invert'   => $invert,
            ]
        );

        return $decision ? $should_render : false;
    }

    private static function is_edit_mode(): bool {
        if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
            return false;
        }

        try {
            return \Elementor\Plugin::$instance->editor->is_edit_mode();
        } catch ( \Throwable $e ) {
            return false;
        }
    }
}

class MTX_Visibility_Menus {
    public static function boot(): void {
        add_filter( 'wp_setup_nav_menu_item', [ __CLASS__, 'load_item_meta' ] );
        add_action( 'wp_update_nav_menu_item', [ __CLASS__, 'save_item_meta' ], 10, 3 );
        add_filter( 'wp_nav_menu_objects', [ __CLASS__, 'filter_menu_items' ], 20, 2 );

        add_action( 'wp_nav_menu_item_custom_fields', [ __CLASS__, 'render_menu_fields' ], 10, 4 );
    }

    public static function load_item_meta( $menu_item ) {
        $menu_item->_mtx_menu_visibility_type = get_post_meta( $menu_item->ID, '_mtx_menu_visibility_type', true );
        $menu_item->_mtx_menu_membership_ids  = get_post_meta( $menu_item->ID, '_mtx_menu_membership_ids', true );
        $menu_item->_mtx_menu_require_all     = get_post_meta( $menu_item->ID, '_mtx_menu_require_all', true );

        return $menu_item;
    }

    public static function save_item_meta( $menu_id, $menu_item_db_id, $args ): void {
        $type = isset( $_POST[ 'mtx_menu_visibility_type_' . $menu_item_db_id ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'mtx_menu_visibility_type_' . $menu_item_db_id ] ) ) : '';
        $ids  = isset( $_POST[ 'mtx_menu_membership_ids_' . $menu_item_db_id ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'mtx_menu_membership_ids_' . $menu_item_db_id ] ) ) : '';
        $all  = isset( $_POST[ 'mtx_menu_require_all_' . $menu_item_db_id ] ) ? '1' : '';

        update_post_meta( $menu_item_db_id, '_mtx_menu_visibility_type', $type );
        update_post_meta( $menu_item_db_id, '_mtx_menu_membership_ids', $ids );
        update_post_meta( $menu_item_db_id, '_mtx_menu_require_all', $all );
    }

    public static function filter_menu_items( $items, $args ) {
        $user_id = get_current_user_id();

        foreach ( $items as $index => $item ) {
            $type = isset( $item->_mtx_menu_visibility_type ) ? $item->_mtx_menu_visibility_type : get_post_meta( $item->ID, '_mtx_menu_visibility_type', true );

            if ( '' === $type ) {
                continue;
            }

            if ( 'logged-in' === $type ) {
                if ( ! is_user_logged_in() ) {
                    unset( $items[ $index ] );
                }

                continue;
            }

            if ( 'logged-out' === $type ) {
                if ( is_user_logged_in() ) {
                    unset( $items[ $index ] );
                }

                continue;
            }

            if ( 'membership' === $type ) {
                $ids = isset( $item->_mtx_menu_membership_ids ) ? $item->_mtx_menu_membership_ids : get_post_meta( $item->ID, '_mtx_menu_membership_ids', true );
                $all = isset( $item->_mtx_menu_require_all ) ? $item->_mtx_menu_require_all : get_post_meta( $item->ID, '_mtx_menu_require_all', true );

                $decision = MTX_Visibility_Engine::instance()->resolve_visibility(
                    [
                        'enable'  => true,
                        'ids'     => (string) $ids,
                        'require' => ( '1' === $all ) ? 'all' : 'any',
                        'invert'  => false,
                    ],
                    $user_id
                );

                if ( ! $decision ) {
                    unset( $items[ $index ] );
                }
            }
        }

        return array_values( $items );
    }

    public static function render_menu_fields( $item_id, $item, $depth, $args ): void {
        $type = get_post_meta( $item_id, '_mtx_menu_visibility_type', true );
        $ids  = get_post_meta( $item_id, '_mtx_menu_membership_ids', true );
        $all  = get_post_meta( $item_id, '_mtx_menu_require_all', true );
        ?>
        <p class="field-mtx-visibility description description-wide">
            <label for="mtx_menu_visibility_type_<?php echo esc_attr( $item_id ); ?>">
                <?php esc_html_e( 'MTX Visibility (Menu)', 'mtx-visibility' ); ?><br />
                <select id="mtx_menu_visibility_type_<?php echo esc_attr( $item_id ); ?>" name="mtx_menu_visibility_type_<?php echo esc_attr( $item_id ); ?>">
                    <option value="" <?php selected( $type, '' ); ?>><?php esc_html_e( '— Default (visible) —', 'mtx-visibility' ); ?></option>
                    <option value="logged-in" <?php selected( $type, 'logged-in' ); ?>><?php esc_html_e( 'Logged-in only', 'mtx-visibility' ); ?></option>
                    <option value="logged-out" <?php selected( $type, 'logged-out' ); ?>><?php esc_html_e( 'Logged-out only', 'mtx-visibility' ); ?></option>
                    <option value="membership" <?php selected( $type, 'membership' ); ?>><?php esc_html_e( 'Membership-based (IDs)', 'mtx-visibility' ); ?></option>
                </select>
            </label>
        </p>
        <p class="field-mtx-memberships description description-wide">
            <label for="mtx_menu_membership_ids_<?php echo esc_attr( $item_id ); ?>">
                <?php esc_html_e( 'MemberPress Product IDs (comma-separated)', 'mtx-visibility' ); ?><br />
                <input type="text" id="mtx_menu_membership_ids_<?php echo esc_attr( $item_id ); ?>" name="mtx_menu_membership_ids_<?php echo esc_attr( $item_id ); ?>" value="<?php echo esc_attr( $ids ); ?>" />
            </label>
        </p>
        <p class="field-mtx-require description description-wide">
            <label for="mtx_menu_require_all_<?php echo esc_attr( $item_id ); ?>">
                <input type="checkbox" id="mtx_menu_require_all_<?php echo esc_attr( $item_id ); ?>" name="mtx_menu_require_all_<?php echo esc_attr( $item_id ); ?>" <?php checked( $all, '1' ); ?> />
                <?php esc_html_e( 'Require ALL of the IDs (unchecked = ANY)', 'mtx-visibility' ); ?>
            </label>
        </p>
        <?php
    }
}

// [mtx_nocache] shortcode – use in Elementor templates
add_shortcode( 'mtx_nocache', function() {
    if ( ! headers_sent() ) {
        nocache_headers();
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Vary: Cookie' );
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
    }

    return '';
} );

// "Force No-Cache" meta box on posts/pages
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'mtx_force_nocache',
        __( 'MTX: Force No-Cache', 'mtx-visibility' ),
        function( $post ) {
            $val = get_post_meta( $post->ID, '_mtx_force_nocache', true );
            wp_nonce_field( 'mtx_force_nocache_nonce', 'mtx_force_nocache_nonce' );
            echo '<label><input type="checkbox" name="mtx_force_nocache" value="1" ' . checked( $val, '1', false ) . '> ';
            echo esc_html__( 'Send no-cache headers on this page', 'mtx-visibility' ) . '</label>';
        },
        null,
        'side',
        'default'
    );
} );

add_action( 'save_post', function( $post_id ) {
    if ( ! isset( $_POST['mtx_force_nocache_nonce'] ) || ! wp_verify_nonce( $_POST['mtx_force_nocache_nonce'], 'mtx_force_nocache_nonce' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    $val = isset( $_POST['mtx_force_nocache'] ) ? '1' : '';
    update_post_meta( $post_id, '_mtx_force_nocache', $val );
} );

// Apply no-cache headers when the flag is on
add_action( 'send_headers', function() {
    if ( is_admin() || defined( 'DOING_AJAX' ) ) {
        return;
    }
    if ( ! is_singular() ) {
        return;
    }
    $post_id = get_queried_object_id();
    if ( ! $post_id ) {
        return;
    }
    if ( '1' !== get_post_meta( $post_id, '_mtx_force_nocache', true ) ) {
        return;
    }

    if ( ! headers_sent() ) {
        nocache_headers();
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Vary: Cookie' );
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
    }
}, 0 );

add_shortcode('mtx_vis_debug', function() {
    if ( ! is_user_logged_in() ) return '<pre>Not logged in</pre>';
    if ( ! class_exists('\\MeprUser') ) return '<pre>MemberPress not active</pre>';

    $uid = get_current_user_id();
    $user = new \MeprUser($uid);
    $engine = MTX_Visibility_Engine::instance();

    $active_ids = $engine->get_active_membership_ids($uid);
    $out = [
        'user_id'     => $uid,
        'active_ids'  => $active_ids,
        'timestamp'   => time(),
    ];

    // Try to dump a few raw subs for sanity.
    try {
        if ( method_exists($user, 'subscriptions') ) {
            $subs = (array) $user->subscriptions();
            $sample = [];
            foreach ($subs as $s) {
                $sample[] = [
                    'product_id'   => $engine->extract_product_id_from_subscription($s),
                    'status'       => (is_object($s) && isset($s->status)) ? $s->status : (is_array($s) && isset($s['status']) ? $s['status'] : ''),
                ];
                if (count($sample) >= 5) break;
            }
            $out['subs_sample'] = $sample;
        }
    } catch (\Throwable $e) {}

    return '<pre>'.esc_html(print_r($out, true)).'</pre>';
});

/**
 * HTTP guard: Starts output buffering and sets headers at emission time based on MTX_Visibility_State.
 * Ensures caches never store member HTML snapshots.
 */
if ( ! class_exists( 'MTX_Visibility_HTTP' ) ) {
    final class MTX_Visibility_HTTP {
        public static function boot(): void {
            if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
                return;
            }

            // Start buffering very early in the template lifecycle.
            add_action( 'template_redirect', [ __CLASS__, 'start_buffering' ], 0 );
        }

        private static function scrub_guest_html( string $html ): string {
            if ( is_user_logged_in() ) {
                return $html;
            }

            // Remove any block tagged as MTX:GATED with invert=0 (members-only).
            // We look for a START tag with JSON payload and the corresponding END tag.
            $pattern = '/<!--\s*MTX:GATED START\s+(\{.*?\})\s*-->(.*?)<!--\s*MTX:GATED END\s*-->/si';

            $html = preg_replace_callback( $pattern, function( $m ) {
                $json = json_decode( $m[1], true );
                if ( ! is_array( $json ) ) {
                    // If malformed, fail closed for guests: remove it
                    return '';
                }
                $invert = isset( $json['invert'] ) ? (int) $json['invert'] : 0;

                // invert=0 => members-only => guests should NOT see it => strip it
                // invert=1 => guest-only => guests SHOULD see it => keep it
                if ( $invert === 0 ) {
                    return '';
                }
                return $m[0]; // keep guest-only or anything else
            }, $html );

            return $html;
        }

        public static function start_buffering(): void {
            if ( headers_sent() ) {
                return;
            }

            // Use a buffer so headers can still be changed after render decisions are made.
            if ( function_exists( 'header_register_callback' ) ) {
                // Register a header callback to run right before headers are sent.
                header_register_callback( [ __CLASS__, 'maybe_set_no_cache_headers' ] );
            }

            // Start output buffer; passthrough (we only use it to delay headers)
            if ( ! ob_get_level() ) {
                ob_start();
                // In case someone flushes early, try to set headers on shutdown too
                add_action( 'shutdown', [ __CLASS__, 'maybe_set_no_cache_headers' ], 0 );
                add_action( 'shutdown', function() {
                    // Flush all buffers into one string
                    $output = '';
                    while ( ob_get_level() > 0 ) {
                        $chunk  = ob_get_contents();
                        $output = ( false === $chunk ? '' : $chunk ) . $output;
                        @ob_end_clean();
                    }
                    // Scrub members-only blocks for guests and echo
                    $output = MTX_Visibility_HTTP::scrub_guest_html( $output );
                    echo $output;
                }, 9999 );
            }
        }

        public static function maybe_set_no_cache_headers(): void {
            // Only act on front-end document responses
            if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
                return;
            }

            // If any MTX-enabled element rendered, force private/no-store and Vary: Cookie
            if ( MTX_Visibility_State::$has_gated_elements ) {
                // Remove any pre-existing cache headers that might have been set earlier
                if ( function_exists( 'header_remove' ) ) {
                    @header_remove( 'Cache-Control' );
                    @header_remove( 'Pragma' );
                    @header_remove( 'Expires' );
                }
                // Send strict headers
                @nocache_headers();
                @header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
                @header( 'Pragma: no-cache', true );
                @header( 'Vary: Cookie', false );
                if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                    define( 'DONOTCACHEPAGE', true );
                }
            }
        }
    }
}

new MTX_Visibility_Plugin();
