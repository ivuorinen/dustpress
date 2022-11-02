<?php
/*
Plugin Name: DustPress
Plugin URI: http://www.geniem.com
Description: Dust.js templating system for WordPress
Author: Miika Arponen & Ville Siltala / Geniem Oy
Author URI: http://www.geniem.com
License: GPLv3
Version: 1.34.4
*/

final class DustPress {

    /**
     * Singleton DustPress instance
     *
     * @var DustPress
     */
    private static DustPress $instance;

    /**
     * Instance of DustPHP
     *
     * @var \Dust\Dust
     */
    public \Dust\Dust $dust;

    // Main model
    private $model;

    // This is where the data will be stored
    private $data;

    // DustPress settings
    private $settings;

    // Is DustPress disabled?
    public $disabled;

    // Paths for locating files
    private $paths;

    // Paths for template files
    private array $templates = [];

    // Are we on an activation page
    private bool $activate;

    // Registered custom ajax functions
    private $ajax_functions;

    // Custom routes
    private array $custom_routes = [];

    private $request_data;

    private array $autoload_paths = [];

    /**
     * Should performance metrics be collected? Defaults to false.
     * Using DustPress Debugger (while not using WP_CLI) sets this true.
     *
     * @var bool
     */
    public bool $performance_enabled = false;

    // Runtime performance is stored here.
    // DustPress Debugger will read it (via $this->get_performance_data) after the page has been rendered.
    private array $performance = [];

    // Multiple microtime(true) values stored here for execution time calculations.
    private array $performance_timers = [];
    private array $dustpress_performance_timers = [];

    public static function instance() : DustPress {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     *  Constructor for DustPress core class.
     *
     * @type    function
     * @date     10/8/2015
     * @since    0.2.0
     */

    protected function __construct() {
        $self = $this;

        $self->maybe_enable_performance_metrics();
        $self->save_performance( 'Before DustPress', $_SERVER['REQUEST_TIME_FLOAT'] );
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );
        $self->measure_hooks_performance();

        $this->register_autoloaders();

        // Create a DustPHP instance
        $this->dust = new Dust\Dust();

        $this->add_theme_paths();
        $this->add_core_paths();

        // Add the fetched paths to the Dust instance.
        $this->dust->includedDirectories = $this->get_template_paths( 'partials' );

        // Find and include Dust helpers from DustPress plugin
        $paths = [
            __DIR__ . '/helpers',
        ];

        foreach ( $paths as $path ) {
            if ( ! is_readable( $path ) ) {
                continue;
            }

            foreach (
                $this->get_files_from_path( $path ) as $file
            ) {
                if ( is_readable( $file ) && '.php' === substr( $file, - 4, 4 ) ) {
                    require_once( $file );
                }
            }
        }

        // Add create_instance to right action hook if we are not on the admin side
        if ( $this->want_autoload() ) {
            add_filter( 'template_include', [ $this, 'create_instance' ] );

            // If we are on wp-activate.php hook into activate_header
            if ( strpos( $_SERVER['REQUEST_URI'], 'wp-activate.php' ) !== false ) {
                $this->activate = true;
                // Run create_instance for use partial and model
                add_action( 'activate_header', [ $this, 'create_instance' ] );
                // Kill original wp-activate.php execution
                add_action( 'activate_header', function () {
                    die();
                } );
            }
        }
        elseif ( $this->is_dustpress_ajax() ) {
            $this->parse_request_data();

            // Optimize the run if we don't want to run the main WP_Query at all.
            if ( ( is_object( $this->request_data ) && ! empty( $this->request_data->bypassMainQuery ) ) ||
                 ( is_array( $this->request_data ) && ! empty( $this->request_data['bypassMainQuery'] ) ) ) {
                // DustPress.js request is never 404.
                add_filter( 'status_header', fn($status, $header) => 'status: 200', 10, 2 );

                // Make the main query to be as fast as possible within DustPress.js requests.
                add_filter( 'posts_request', function ( $request, \WP_Query $query ) {
                    // Target main home query
                    if ( $query->is_main_query() ) {
                        $request = str_replace( '1=1', '0=1', $request );
                    }

                    return $request;
                }, PHP_INT_MAX, 2 );

                // Populate the main query posts variable with a dummy post to prevent errors and notices.
                add_filter( 'posts_pre_query', function ( $posts, \WP_Query $query ) {
                    if ( $query->is_main_query() ) {
                        $posts              = [ new WP_Post( (object) [] ) ];
                        $query->found_posts = 1;
                    }

                    return $posts;
                }, 10, 2 );
            }

            add_filter( 'template_include', [ $this, 'create_ajax_instance' ] );
        }

        // Initialize settings
        add_action( 'init', [ $this, 'init_settings' ] );

        // Register custom route rewrite tag
        add_action( 'after_setup_theme', [ $this, 'rewrite_tags' ], 20 );

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     * Should performance tracking be enabled.
     *
     * @return bool
     */
    public function maybe_enable_performance_metrics() {
        if ( defined( 'WP_CLI' ) ) {
            $this->performance_enabled = false;

            return false;
        }

        // DustPress has probably been initialized before WP_IMPORTING
        // has been defined. This might make this check useless, but
        // the default is already false, so it shouldn't matter.
        if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
            $this->performance_enabled = false;

            return false;
        }

        if ( $this->debugger_is_active() ) {
            $this->performance_enabled = true;

            return true;
        }

        return false;
    }

    /**
     *  Register custom route rewrite tags
     *
     * @type    function
     * @date     19/3/2019
     * @since    1.13.1
     */
    public function rewrite_tags() {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        // Register custom route rewrite tag
        add_rewrite_tag( '%dustpress_custom_route%', '([^\/]+)' );
        add_rewrite_tag( '%dustpress_custom_route_route%', '(.+)' );
        add_rewrite_tag( '%dustpress_custom_route_parameters%', '(.+)' );
        add_rewrite_tag( '%dustpress_custom_route_render%', '(.+)' );

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     *  This function creates the instance of the main model that is defined by the WordPress template
     *
     * @type    function
     * @date     19/3/2015
     * @since    0.0.1
     */
    public function create_instance() {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        global $post, $wp_query;

        // Initialize an array for debugging.
        $debugs = [];

        if ( ! $this->activate ) {
            $post_id = null;
            if ( is_object( $post ) && isset( $post->ID ) ) {
                $post_id = $post->ID;
            }

            // Filter for wanted post ID
            $new_post = apply_filters( 'dustpress/router', $post_id );

            // If developer wanted a post by post ID.
            if ( $new_post !== $post_id ) {
                $post = get_post( $new_post );

                setup_postdata( $post );
            }

            // Get current template name tidied up a bit.
            $template = $this->get_template_filename( $debugs );
        }
        else {
            // Use user-activate.php and user-activate.dust to replace wp-activate.php
            $template = apply_filters( 'dustpress/template/useractivate', 'UserActivate' );
            $debugs[] = $template;
            // Prevent 404 on multisite sub pages.
            $wp_query->is_404 = false;
        }

        $custom_route_args       = [];
        $custom_route            = $wp_query->get( 'dustpress_custom_route' );
        $custom_route_parameters = $wp_query->get( 'dustpress_custom_route_parameters' );

        // Handle registered DustPress custom routes
        if ( ! empty( $custom_route ) ) {
            $template = $custom_route;

            $custom_route_args['route']  = $wp_query->get( 'dustpress_custom_route_route' );
            $custom_route_args['render'] = $wp_query->get( 'dustpress_custom_route_render' );

            if ( ! empty( $custom_route_parameters ) ) {
                $custom_route_args['params'] = explode( DIRECTORY_SEPARATOR, $custom_route_parameters );
            }

            $type = $custom_route_args['render'] ?: 'default';
        }

        $template = apply_filters( 'dustpress/template', $template, $custom_route_args );

        if ( ! defined( 'DOING_AJAX' ) && ! $this->disabled ) {
            // If class exists with the template's name, create new instance with it.
            // We do not throw error if the class does not exist, to ensure that you can still create
            // templates in traditional style if needed.

            if ( ! class_exists( $template ) ) {
                http_response_code( 500 );
                $debugs_required = implode( ', ', $debugs );
                die( 'DustPress error: No suitable model found. One of these is required: ' . $debugs_required );
            }

            $this->start_performance( 'Models total' );

            $this->model = new $template( $custom_route_args );
            $this->model->fetch_data();

            $this->save_performance( 'Models total' );

            do_action( 'dustpress/model_list', array_keys( (array) $this->model->get_submodels() ) );

            $this->start_performance( 'Templates total' );

            $template_override = $this->model->get_template();

            $this->save_performance( 'Templates total' );

            $partial = $template_override ?: strtolower( $this->camelcase_to_dashed( $template ) );

            $this->render( [
                'partial' => $partial,
                'main'    => true,
                'type'    => $type ?? 'default',
            ] );
        }
    }

    /**
     * This function returns the model name of current custom route, or false if we are not on a custom route.
     *
     * @type    function
     * @date     8/1/2019
     * @since    1.20.0
     *
     * @return    string|boolean
     */
    public function get_custom_route() {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        global $wp_query;

        $custom_route = $wp_query->get( 'dustpress_custom_route' );

        if ( ! empty( $custom_route ) ) {
            if ( isset( $this->custom_routes[ $custom_route ] ) ) {
                return [
                    'template' => $custom_route,
                    'route'    => $this->custom_routes[ $custom_route ],
                ];
            }
        }

        $this->save_dustpress_performance( $performance_measure_id );

        return false;
    }

    /**
     *  This function gets current template's filename and returns without extension or WP-template prefixes
     *  such as page- or single-.
     *
     * @type    function
     * @date     19/3/2015
     * @since    0.0.1
     *
     * @return    string
     */
    private function get_template_filename( &$debugs = [] ) {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        global $post;

        $template = 'default';
        if ( is_object( $post ) && isset( $post->ID ) ) {
            $page_template = get_post_meta( $post->ID, '_wp_page_template', true );

            if ( $page_template ) {
                $array = explode( DIRECTORY_SEPARATOR, $page_template );

                $template = array_pop( $array );

                // strip out .php, page- and single-
                $template = str_replace( [ 'page-', 'single-', '.php' ], '', $template );

                if ( $template === 'default' ) {
                    $template = 'page';
                }
            }
        }

        $hierarchy = [];

        if ( is_front_page() ) {
            $hierarchy['is_front_page'] = [
                'FrontPage',
            ];
        }

        if ( is_search() ) {
            $hierarchy['is_search'] = [
                'Search',
            ];
        }

        if ( is_home() ) {
            $hierarchy['is_home'] = [
                'Home',
            ];
        }

        if ( is_page() ) {
            $hierarchy['is_page'] = [
                'Page' . $this->dashed_to_camelcase( $template, '_' ),
                'Page' . $this->dashed_to_camelcase( $template ),
                'Page' . $this->dashed_to_camelcase( $post->post_name, '_' ),
                'Page' . $this->dashed_to_camelcase( $post->post_name ),
                'Page' . $post->ID,
                'Page',
            ];
        }

        if ( is_category() ) {
            $cat = get_category( get_query_var( 'cat' ) );

            $hierarchy['is_category'] = [
                'Category' . $this->dashed_to_camelcase( $cat->slug, '_' ),
                'Category' . $this->dashed_to_camelcase( $cat->slug ),
                'Category' . $cat->term_id,
                'Category',
                'Archive',
            ];
        }

        if ( is_tag() ) {
            $term_id = get_queried_object()->term_id;
            $term    = get_term_by( 'id', $term_id, 'post_tag' );

            $hierarchy['is_tag'] = [
                'Tag' . $this->dashed_to_camelcase( $term->slug . '_' ),
                'Tag' . $this->dashed_to_camelcase( $term->slug ),
                'Tag',
                'Archive',
            ];
        }

        if ( is_tax() ) {
            $term = get_queried_object();

            $hierarchy['is_tax'] = [
                'Taxonomy' . $this->dashed_to_camelcase( $term->taxonomy,
                    '_' ) . $this->dashed_to_camelcase( $term->slug ),
                'Taxonomy' . $this->dashed_to_camelcase( $term->taxonomy ) . $this->dashed_to_camelcase( $term->slug ),
                'Taxonomy' . $this->dashed_to_camelcase( $term->taxonomy, '_' ),
                'Taxonomy' . $this->dashed_to_camelcase( $term->taxonomy ),
                'Taxonomy',
                'Archive',
            ];
        }

        if ( is_author() ) {
            $author = get_user_by( 'slug', get_query_var( 'author_name' ) );

            $hierarchy['is_author'] = [
                'Author' . $this->dashed_to_camelcase( $author->user_nicename, '_' ),
                'Author' . $this->dashed_to_camelcase( $author->user_nicename ),
                'Author' . $author->ID,
                'Author',
                'Archive',
            ];
        }

        $hierarchy['is_404'] = [
            'Error404',
        ];

        if ( is_attachment() ) {
            $mime_type = get_post_mime_type( get_the_ID() );

            $hierarchy['is_attachment'] = [
                fn() => preg_match( '/^image/', $mime_type ) && class_exists( 'Image' )
                    ? 'Image'
                    : false,
                fn() => preg_match( '/^video/', $mime_type ) && class_exists( 'Video' )
                    ? 'Video'
                    : false,
                fn() => preg_match( '/^application/', $mime_type ) && class_exists( 'Application' )
                    ? 'Application'
                    : false,
                function () use ( $mime_type ) {
                    if ( $mime_type !== 'text/plain' ) {
                        return false;
                    }

                    if ( class_exists( 'Text' ) ) {
                        return 'Text';
                    }

                    if ( class_exists( 'Plain' ) ) {
                        return 'Plain';
                    }

                    if ( class_exists( 'TextPlain' ) ) {
                        return 'TextPlain';
                    }

                    return false;
                },
                'Attachment',
                'SingleAttachment',
                'Single',
            ];
        }

        if ( is_single() ) {
            $type = get_post_type();

            $hierarchy['is_single'] = [
                'Single' . $this->dashed_to_camelcase( $template, '_' ),
                'Single' . $this->dashed_to_camelcase( $template ),
                'Single' . $this->dashed_to_camelcase( $type, '_' ),
                'Single' . $this->dashed_to_camelcase( $type ),
                'Single',
            ];
        }

        if ( is_archive() ) {
            // Double check just to keep the function structure.
            $hierarchy['is_archive'] = [
                function () {
                    $post_types = get_post_types();

                    foreach ( $post_types as $type ) {
                        if ( is_post_type_archive( $type ) ) {
                            if ( class_exists( 'Archive' . $this->dashed_to_camelcase( $type ) ) ) {
                                return 'Archive' . $this->dashed_to_camelcase( $type );
                            }

                            if ( class_exists( 'Archive' ) ) {
                                return 'Archive';
                            }

                            return false;
                        }
                    }

                    return false;
                },
            ];
        }

        if ( is_date() ) {
            // Double check just to keep the function structure.
            $hierarchy['is_date'] = [
                function () {
                    if ( class_exists( 'Date' ) ) {
                        return 'Date';
                    }

                    if ( class_exists( 'Archive' ) ) {
                        return 'Archive';
                    }

                    return false;
                },
            ];
        }

        // I don't think you really want to do this.
        $hierarchy = apply_filters( 'dustpress/template_hierarchy', $hierarchy );

        foreach ( $hierarchy as $level => $keys ) {
            if ( true === $level() ) {
                foreach ( $keys as $key => $value ) {
                    if ( is_int( $key ) ) {
                        if ( is_string( $value ) ) {
                            $debugs[] = $value;
                            if ( class_exists( $value ) ) {
                                return $value;
                            }
                        }
                        elseif ( is_callable( $value ) ) {
                            $value    = $value();
                            $debugs[] = $value;
                            if ( is_string( $value ) && class_exists( $value ) ) {
                                return $value;
                            }
                        }
                    }
                    elseif ( is_string( $key ) ) {
                        if ( class_exists( $key ) ) {
                            if ( is_string( $value ) ) {
                                $debugs[] = $value;
                                if ( class_exists( $value ) ) {
                                    return $value;
                                }
                            }
                            elseif ( is_callable( $value ) ) {
                                $debugs[] = $value;

                                return $value();
                            }
                        }
                    }
                    elseif ( true === $key || is_callable( $key ) ) {
                        if ( true === $key() ) {
                            if ( is_string( $value ) ) {
                                $debugs[] = $value;
                                if ( class_exists( $value ) ) {
                                    return $value;
                                }
                            }
                            elseif ( is_callable( $value ) ) {
                                $debugs[] = $value;

                                return $value();
                            }
                        }
                    }
                }
            }
        }

        $debugs[] = 'Index';

        $this->save_dustpress_performance( $performance_measure_id );

        return 'Index';
    }

    /**
     * This function populates the data collection with essential data
     *
     * @date  2015-03-17
     * @since 0.0.1
     */
    private function populate_data_collection() {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $wp_data = [];

        // Insert WordPress blog info data to collection
        $infos = [
            'name',
            'description',
            'wpurl',
            'url',
            'admin_email',
            'charset',
            'version',
            'html_type',
            'is_rtl',
            'language',
            'stylesheet_url',
            'stylesheet_directory',
            'template_url',
            'template_directory',
            'pingback_url',
            'atom_url',
            'rdf_url',
            'rss_url',
            'rss2_url',
            'comments_atom_url',
            'comments_rss2_url',
            'url',
        ];

        if ( $this->is_dustpress_ajax() ) {
            $remove_infos = [ 'wpurl', 'admin_email', 'version', 'user' ];
            $remove_infos = apply_filters( 'dustpress/ajax/remove_wp', $remove_infos );

            $infos = array_diff( $infos, $remove_infos );
        }

        foreach ( $infos as $info ) {
            $wp_data[ $info ] = get_bloginfo( $info );
        }

        // Insert user info to collection
        $current_user = wp_get_current_user();

        $wp_data['loggedin'] = false;
        if ( $current_user !== null && 0 !== $current_user->ID ) {
            $wp_data['loggedin']    = true;
            $wp_data['user']        = $current_user->data;
            $wp_data['user']->roles = $current_user->roles;
            unset( $wp_data['user']->user_pass );
        }

        // Insert WP title to collection
        ob_start();
        wp_title();
        $wp_data['title'] = ob_get_clean();

        // Insert admin ajax url
        $wp_data['admin_ajax_url'] = admin_url( 'admin-ajax.php' );

        // Insert current page permalink
        $wp_data['permalink'] = get_permalink();

        // Insert body classes
        $wp_data['body_class'] = get_body_class();

        // Return collection after filters
        $apply_filters = apply_filters( 'dustpress/data/wp', $wp_data );

        $this->save_dustpress_performance( $performance_measure_id );

        return $apply_filters;
    }

    /**
     *  This function will render the given data in selected format
     *
     * @date  2015-03-17
     * @since 0.0.1
     *
     * @param array $args Arguments.
     *
     * @return bool
     */
    public function render( array $args = [] ) : bool {
        $type = null;
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        global $dustpress;
        global $hash;

        $defaults = [
            'data' => false,
            'type' => 'default',
            'echo' => true,
            'main' => false,
        ];

        $options = array_merge( $defaults, (array) $args );

        // FIXME -> WP function
        extract( $options );

        if ( 'default' === $type && ! get_option( 'dustpress_default_format' ) ) {
            $type = 'html';
        }
        elseif ( 'default' === $type && get_option( 'dustpress_default_format' ) ) {
            $type = get_option( 'dustpress_default_format' );
        }

        if ( $this->get_setting( 'json_url' ) && isset( $_GET['json'] ) ) {
            $type = 'json';
        }

        if (
            isset( $_SERVER['HTTP_ACCEPT'] ) &&
            $_SERVER['HTTP_ACCEPT'] === 'application/json' &&
            $this->get_setting( 'json_headers' )
        ) {
            $type = 'json';
        }

        $types = [
            'html' => function ( $data, $partial, $dust ) {
                try {
                    if (
                        apply_filters( 'dustpress/cache/partials', false ) &&
                        apply_filters( 'dustpress/cache/partials/' . $partial, true )
                    ) {
                        if ( ! ( $compiled = wp_cache_get( $partial, 'dustpress/partials' ) ) ) {
                            $compiled = $dust->compileFile( $partial );

                            wp_cache_set( $partial, $compiled, 'dustpress/partials' );
                        }
                    }
                    else {
                        $compiled = $dust->compileFile( $partial );
                    }
                }
                catch ( Exception $e ) {
                    http_response_code( 500 );
                    die( 'DustPress error: ' . $e->getMessage() );
                }

                if (
                    apply_filters( 'dustpress/cache/rendered', false ) &&
                    apply_filters( 'dustpress/cache/rendered/' . $partial, true )
                ) {
                    $data_hash = sha1( serialize( $compiled ) . serialize( $data ) );

                    $cache_time = apply_filters(
                        'dustpress/settings/partial/' . $partial,
                        $this->get_setting( 'rendered_expire_time' )
                    );

                    if ( ! ( $rendered = wp_cache_get( $data_hash, 'dustpress/rendered' ) ) ) {
                        $rendered = $dust->renderTemplate( $compiled, $data );

                        wp_cache_set( $data_hash, $rendered, 'dustpress/rendered', $cache_time );
                    }
                }
                else {
                    $rendered = $dust->renderTemplate( $compiled, $data );
                }

                return $rendered;
            },
            'json' => function ( $data, $partial, $dust ) {
                try {
                    $output = json_encode( $data, JSON_THROW_ON_ERROR );
                    header( 'Content-Type: application/json' );
                }
                catch ( Exception $e ) {
                    http_response_code( 500 );
                    die( 'JSON encode error: ' . $e->getMessage() );
                }

                return $output;
            },
        ];

        add_filter( 'dustpress/formats/use_dust/html', '__return_true' );

        $types = apply_filters( 'dustpress/formats', $types );

        if ( ! $data && ! empty( $this->model ) ) {
            $this->model->data = (array) $this->model->data;

            $this->model->data['WP'] = $this->populate_data_collection();
        }

        if ( apply_filters( 'dustpress/formats/use_dust/' . $type, false ) ) {
            // Ensure we have a DustPHP instance.
            if ( isset( $this->dust ) ) {
                $dust = $this->dust;
            }
            else {
                http_response_code( 500 );
                die( 'DustPress error: Something very unexpected happened: there is no DustPHP.' );
            }

            if ( ! isset( $args['partial'] ) ) {
                http_response_code( 500 );
                die( '<p><b>DustPress error:</b> No partial is given to the render function.</p>' );
            }

            $dust->helpers = apply_filters( 'dustpress/helpers', $dust->helpers );

            // Fetch Dust partial by given name. Throw error if there is something wrong.
            try {
                $template = $this->get_template( $partial );

                $helpers = $this->prerender( $partial );

                $this->prerun_helpers( $helpers );
            }
            catch ( Exception $e ) {
                http_response_code( 500 );
                die( 'DustPress error: ' . $e->getMessage() );
            }
        }
        else {
            $dust     = null;
            $template = $partial;
        }

        if ( ! empty( $data ) ) {
            $render_data = apply_filters( 'dustpress/data', $data );
        }
        elseif ( ! empty( $this->model->data ) ) {
            $render_data = apply_filters( 'dustpress/data', $this->model->data );
            $render_data = apply_filters( 'dustpress/data/main', $render_data );
        }
        else {
            $render_data = null;
        }

        // Create output with wanted format.
        $output = call_user_func( $types[ $type ], $render_data, $template, $dust );

        // Filter output
        $output = apply_filters( 'dustpress/output', $output, $options );

        $this->save_dustpress_performance( $performance_measure_id );

        if ( $main ) {
            # A bit hacky but create_instance performance has to be ended here, because
            # debugger fetches the data on dustpress/data/after_render filter.
            $this->save_dustpress_performance( 'create_instance--0' );
        }

        // Do something with the data after rendering
        apply_filters( 'dustpress/data/after_render', $render_data, $main );

        if ( ! $echo ) {
            return $output;
        }

        if ( empty ( strlen( $output ) ) ) {
            $error = true;
            echo 'DustPress warning: empty output.';
        }
        else {
            echo $output;
        }

        return ! isset( $error );
    }

    /**
     * This function checks whether the given partial exists and
     * returns the contents of the file as a string
     *
     * @since 0.0.1
     * @date  2015-03-17
     *
     * @param string $partial
     *
     * @return mixed|string $template (string)
     * @throws \Exception
     */
    private function get_template( $partial ) {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        // Check if we have received an absolute path.
        if ( file_exists( $partial ) ) {
            $this->save_dustpress_performance( $performance_measure_id );

            return $partial;
        }

        $templatefile = $partial . '.dust';
        $templates    = $this->get_templates();

        if ( isset( $templates[ $templatefile ] ) ) {
            $this->save_dustpress_performance( $performance_measure_id );

            return $templates[ $templatefile ];
        }

        // Force searching of templates bypassing the cache.
        $templates = $this->get_templates( true );

        if ( isset( $templates[ $templatefile ] ) ) {
            $this->save_dustpress_performance( $performance_measure_id );

            return $templates[ $templatefile ];
        }

        // If we could not find such template.
        throw new Exception( 'Error loading template file: ' . $partial, 1 );
    }

    /**
     *  This function initializes DustPress settings with default values
     *
     * @date  2016-04-01
     * @since 0.4.0
     */
    public function init_settings() {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $this->settings = [
            'cache'                 => false,
            'debug_data_block_name' => 'Debug',
            'rendered_expire_time'  => 7 * 60 * 60 * 24,
            'json_url'              => false,
            'json_headers'          => false,
        ];

        // loop through the settings and execute possible filters from functions
        foreach ( $this->settings as $key => $value ) {
            $this->settings[ $key ] = apply_filters( 'dustpress/settings/' . $key, $value );
        }

        // A hook to prevent DustPress error to appear in Yoast's sitemap
        add_filter( 'wpseo_build_sitemap_post_type', [ $this, 'disable' ], 1, 1 );

        // A hook to prevent DustPress error to appear when using WP Rest API
        add_action( 'rest_api_init', [ $this, 'disable' ], 1, 1 );

        // A hook to prevent DustPress error to appear when generating robots.txt
        add_action( 'do_robotstxt', [ $this, 'disable' ], 1, 1 );

        $this->save_dustpress_performance( $performance_measure_id );

        return null;
    }

    /**
     *  This function returns DustPress setting for specific key.
     *
     * @date  2016-01-29
     * @since 0.3.1
     *
     * @return mixed
     */
    public function get_setting( $key ) {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $apply_filters = apply_filters( 'dustpress/settings/' . $key, $this->settings[ $key ] ?? null );

        $this->save_dustpress_performance( $performance_measure_id );

        return $apply_filters;
    }

    /**
     * Returns true if we are on login or register page.
     *
     * @date  2015-04-09
     * @since 0.0.7
     *
     * @return bool
     */
    public function is_login_page() : bool {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $is_login_page = ! empty( $GLOBALS['pagenow'] ) &&
                         in_array( $GLOBALS['pagenow'], [ 'wp-login.php', 'wp-register.php' ] );

        $this->save_dustpress_performance( $performance_measure_id );

        return $is_login_page;
    }

    /**
     * This function returns given string converted from CamelCase to camel-case
     * (or probably camel_case or something else, if wanted).
     *
     * @date  2015-06-15
     * @since 0.1.0
     *
     * @param string $string
     * @param string $char
     *
     * @return string
     */
    public function camelcase_to_dashed( $string, string $char = '-' ) : string {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        preg_match_all( '!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $string, $matches );
        $results = $matches[0];
        foreach ( $results as &$match ) {
            $match = $match === strtoupper( $match )
                ? strtolower( $match )
                : lcfirst( $match );
        }
        unset( $match );

        $return_value = implode( $char, $results );

        $this->save_dustpress_performance( $performance_measure_id );

        return $return_value;
    }

    /**
     *  This function returns given string converted from camel-case to CamelCase
     *  (or probably camel_case or something else, if wanted).
     *
     * @date  2016-10-01
     * @since 1.2.9
     *
     * @param string $string
     * @param string $char
     *
     * @return string
     */
    public function dashed_to_camelcase( string $string, string $char = '-' ) : string {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $string = str_replace( $char, ' ', $string );
        $string = str_replace( ' ', '', ucwords( $string ) );

        $this->save_dustpress_performance( $performance_measure_id );

        return $string;
    }

    /**
     *  This function determines if we want to autoload and render the model or not.
     *
     * @date  2015-08-10
     * @since 0.2.0
     *
     * @return bool
     */
    private function want_autoload() : bool {
        $conditions = [
            fn() => ! $this->is_dustpress_ajax(),
            fn() => ! is_admin(),
            fn() => ! $this->is_login_page(),
            fn() => ! defined( 'WP_CLI' ),
            fn() => PHP_SAPI !== 'cli',
            fn() => ! defined( 'DOING_AJAX' ),
            function () {
                if ( strpos( $_SERVER['REQUEST_URI'], 'wp-activate' ) > 0 ) {
                    return true;
                }

                return defined( 'WP_USE_THEMES' ) && WP_USE_THEMES !== false;
            },
            fn() => ! (
                substr( $_SERVER['REQUEST_URI'], - 5 ) === '/feed' ||
                strpos( $_SERVER['REQUEST_URI'], '/feed/' ) !== false ||
                strpos( $_SERVER['REQUEST_URI'], 'feed=' ) !== false
            ),
            fn() => ! isset( $_GET['_wpcf7_is_ajax_call'] ),
            fn() => ! isset( $_POST['_wpcf7_is_ajax_call'] ),
            fn() => ! isset( $_POST['gform_ajax'] ),
            fn() => ! isset( $_POST['dustpress_comments_ajax'] ),
        ];

        $conditions = apply_filters( 'dustpress/want_autoload', $conditions );

        foreach ( $conditions as $condition ) {
            if ( is_callable( $condition ) ) {
                if ( false === $condition() ) {
                    return false;
                }
            }
            elseif ( ! is_null( $condition ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * This function determines if we are on a DustPress AJAX call or not.
     *
     * @date  2015-12-17
     * @since 0.3.0
     *
     * @return bool
     * @throws \JsonException If decoded string isn't a valid JSON object.
     */
    private function is_dustpress_ajax() : bool {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $request_body = file_get_contents( 'php://input' );
        $json         = json_decode( $request_body, false, 512, JSON_THROW_ON_ERROR );

        $return_value = ( isset( $_REQUEST['dustpress_data'] ) ||
                          ( is_object( $json ) && property_exists( $json, 'dustpress_data' ) ) );

        $this->save_dustpress_performance( $performance_measure_id );

        return $return_value;
    }

    /**
     * Register a function to be run with a keyword from DustPress.js.
     *
     * @date  2016-11-25
     * @since 1.3.2
     *
     * @param string $key      Ajax function key.
     * @param mixed  $callable Ajax callable.
     *
     * @return void
     */
    public function register_ajax_function( string $key, $callable ) : void {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $this->ajax_functions[ $key ] = $callable;

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     * A function to determine if a keyword has already been registered for an ajax function.
     *
     * @date  2017-10-10
     * @since 1.6.9
     *
     * @param string $key
     *
     * @return bool
     */
    public function ajax_function_exists( string $key ) : bool {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $return_value = isset( $this->ajax_functions[ $key ] );

        $this->save_dustpress_performance( $performance_measure_id );

        return $return_value;
    }

    /**
     * This function does lots of AJAX stuff with the parameters from the JS side.
     *
     * @date  2015-12-17
     * @since 0.3.0
     */
    public function create_ajax_instance() : void {
        $functions = [];
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        global $post;

        $model = false;

        $request_data = $this->request_data;

        $token = ! empty( $request_data->token ) &&
                 ! empty( $_COOKIE['dpjs_token'] ) &&
                 $request_data->token === $_COOKIE['dpjs_token'];

        if ( ! $token ) {
            $this->send_json( [ 'error' => 'CSRF token mismatch.' ] );
        }

        if ( ! defined( 'DOING_AJAX' ) ) {
            define( 'DOING_AJAX', true );
        }

        $runs = [];
        $args = [];

        // Get the args
        if ( ! empty( $request_data->args ) ) {
            $args = $request_data->args;
        }

        if ( ! preg_match( '/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff,\/]*$/', $request_data->path ) ) {
            $this->send_json( [ 'error' => 'AJAX call path contains illegal characters.' ] );
        }

        // Check if the data we got from the JS side has a function path
        if ( isset( $request_data->path ) ) {
            // If the path is set as a custom ajax function key, run the custom function
            if ( isset( $this->ajax_functions[ $request_data->path ] ) ) {
                try {
                    $data = call_user_func( $this->ajax_functions[ $request_data->path ], $args );
                }
                catch ( Exception $e ) {
                    $this->send_json( [ 'error' => $e->getMessage() ] );
                }

                if ( isset( $request_data->partial ) ) {
                    $partial = $request_data->partial;
                }

                if ( empty( $partial ) ) {
                    $output = [ 'success' => $data ];

                    if ( isset( $request_data->data ) && $request_data->data === true ) {
                        $output['data'] = $data;
                    }

                    $this->send_json( $output );
                }
                else {
                    $html = $this->render( [ 'partial' => $partial, 'data' => $data, 'echo' => false ] );

                    $response = isset( $request_data->data ) && $request_data->data === true
                        ? [ 'success' => $html, 'data' => $data ]
                        : [ 'success' => $html ];

                    if (
                        method_exists( '\DustPress\Debugger', 'use_debugger' ) &&
                        \DustPress\Debugger::use_debugger()
                    ) {
                        $response['data']  = $data;
                        $response['debug'] = \DustPress\Debugger::get_data( 'Debugs' );
                    }

                    $this->send_json( $response );
                }
            }
            else {
                $path = explode( DIRECTORY_SEPARATOR, $request_data->path );

                if ( count( $path ) > 2 ) {
                    $this->send_json( [
                        'error' => 'AJAX call did not have a proper function path defined (syntax: model/function).',
                    ] );
                }
                elseif ( count( $path ) === 2 ) {
                    if ( $path[0] === '' || $path[1] === '' ) {
                        $this->send_json( [
                            'error' => 'AJAX call did not have a proper function path defined (syntax: model/function).',
                        ] );
                    }

                    $model     = $path[0];
                    $functions = explode( ',', $path[1] );

                    foreach ( $functions as $function ) {
                        $runs[] = $function;
                    }
                }
                else {
                    $this->send_json( [
                        'error' => sprintf(
                            "Custom AJAX function key '%s' was not found.",
                            $request_data->path
                        ),
                    ] );
                }
            }
        }

        // If there was no model defined in the JS call, use the one we already are in.
        if ( ! $model ) {
            // Get current template name
            $model = $this->get_template_filename();
            $model = apply_filters( 'dustpress/template', $model );
        }

        // If render is set true, set the model's default template to be used.
        if ( isset( $request_data->render ) && $request_data->render === true ) {
            $partial = strtolower( $this->camelcase_to_dashed( $model ) );
        }

        // Do we want tidy output or not?
        $tidy = isset( $request_data->tidy ) && $request_data->tidy !== false;

        // Get the possible defined partial and possible override the default template.
        if ( isset( $request_data->partial ) && $request_data->partial !== '' ) {
            $partial = $request_data->partial;
        }

        if ( class_exists( $model ) ) {
            $instance = new $model( $args );

            // Get the data
            $instance->fetch_data( $functions, $tidy );

            // If we don't want to render, json-encode and return just the data
            if ( empty( $partial ) ) {
                $output = [ 'success' => $instance->data ];

                if ( isset( $request_data->data ) && $request_data->data === true ) {
                    $output['data'] = $instance->data;
                }

                if (
                    method_exists( '\DustPress\Debugger', 'use_debugger' ) &&
                    \DustPress\Debugger::use_debugger()
                ) {
                    $output['debug'] = \DustPress\Debugger::get_data( 'Debugs' );
                }

                $this->send_json( $output );
            }
            else {
                $template_override = $instance->get_template();

                $partial = $template_override ?: strtolower( $this->camelcase_to_dashed( $partial ) );

                $data = $tidy &&
                        is_array( $functions ) &&
                        count( $functions ) === 1
                    ? $instance->data->{$functions[0]}
                    : $instance->data;
                $html = $this->render( [ 'partial' => $partial, 'data' => $data, 'echo' => false ] );

                $response = [ 'success' => $html ];
                if ( isset( $request_data->data ) && $request_data->data === true ) {
                    $response = [ 'success' => $html, 'data' => $data ];
                }

                if ( method_exists( '\DustPress\Debugger',
                        'use_debugger' ) && \DustPress\Debugger::use_debugger() ) {
                    $response['data']  = $data;
                    $response['debug'] = \DustPress\Debugger::get_data( 'Debugs' );
                }

                $this->send_json( $response );
            }
        }
        elseif ( empty( $model ) ) {
            $this->send_json( [ 'error' => 'No model defined.' ] );
        }
        else {
            $this->send_json( [ 'error' => sprintf( "Model '%s' does not exist.", $model ) ] );
        }

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     * This function prints out the given data as JSON or appropriate error message
     * if something goes wrong.
     *
     * @param mixed $data The data to print out.
     */
    public function send_json( $data ) : void {
        try {
            $output = wp_json_encode( $data, JSON_THROW_ON_ERROR );
        }
        catch ( \Exception $e ) {
            $output = wp_json_encode( [ 'error' => $e->getMessage() ] );
        }

        die( $output );
    }

    /**
     * This function loops through the wanted partial and finds all helpers that are used.
     * It is used recursively.
     *
     * @date  2015-12-17
     * @since 0.3.0
     *
     * @param string $partial
     * @param array  $already
     *
     * @return array|void
     */
    public function prerender( string $partial, array $already = [] ) {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $filename = $this->get_prerender_file( $partial );

        if ( ! $filename ) {
            return;
        }

        $file = file_get_contents( $filename );

        if ( in_array( $file, $already, true ) ) {
            $this->save_dustpress_performance( $performance_measure_id );

            return;
        }

        $already[] = $file;

        $helpers = [];

        // Get helpers
        preg_match_all( '/\{@(\w+)/', $file, $findings );

        $helpers = array_merge( $helpers, array_unique( $findings[1] ) );

        // Get includes
        preg_match_all( '/\{>["\']?([-a-zA-z0-9\/]+)?/', $file, $includes );

        foreach ( $includes[1] as $include ) {
            $incl_explode = explode( DIRECTORY_SEPARATOR, $include );

            $include = array_pop( $incl_explode );

            $include_helpers = $this->prerender( $include, $already );

            if ( is_array( $include_helpers ) ) {
                $helpers = array_merge( $helpers, array_unique( $include_helpers ) );
            }
        }

        $return_value = [];
        if ( is_array( $helpers ) ) {
            $return_value = array_unique( $helpers );
        }

        $this->save_dustpress_performance( $performance_measure_id );

        return $return_value;
    }

    /**
     *  This function is used to get a template file to prerender.
     *
     * @date  2015-12-17
     * @since 0.3.0
     *
     * @param string $partial
     *
     * @return string
     */
    public function get_prerender_file( string $partial ) {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $templatefile = $partial . '.dust';
        $templates    = $this->get_templates();

        $return_value = $templates[ $templatefile ] ?? false;

        $this->save_dustpress_performance( $performance_measure_id );

        return $return_value;
    }

    /**
     *  This function executes dummy runs through all wanted helpers to enqueue scripts they need.
     *
     * @date  2015-12-17
     * @since 0.3.0
     *
     * @param array|string $helpers
     */
    public function prerun_helpers( $helpers ) : void {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        if ( is_array( $helpers ) ) {
            $dummyEvaluator  = new Dust\Evaluate\Evaluator( $this->dust );
            $dummyChunk      = new Dust\Evaluate\Chunk( $dummyEvaluator );
            $dummyContext    = new Dust\Evaluate\Context( $dummyEvaluator );
            $dummySection    = new Dust\Ast\Section( null );
            $dummyBodies     = new Dust\Evaluate\Bodies( $dummySection );
            $dummyParameters = new Dust\Evaluate\Parameters( $dummyEvaluator, $dummyContext );

            foreach ( $this->dust->helpers as $name => $helper ) {
                if ( ! in_array( $name, $helpers, true ) ) {
                    continue;
                }
                if ( ( $helper instanceof \Closure ) || ( $helper instanceof \DustPress\Helper ) ) {
                    $dummyBodies->dummy = true;
                    $helper( $dummyChunk, $dummyContext, $dummyBodies, $dummyParameters );
                }
            }
        }

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     * Returns an array of Dust files present in the project.
     * If the variable is empty, populates it recursively.
     *
     * @date     2018-03-21
     * @since    1.14.0
     *
     * @param bool $force
     *
     * @return array
     */
    public function get_templates( bool $force = false ) : array {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        if ( empty( $this->templates ) || $force ) {
            if ( ! defined( 'DUSTPRESS_DISABLE_TEMPLATE_CACHE' ) || ( ! method_exists( '\DustPress\Debugger',
                        'use_debugger' ) && ! \DustPress\Debugger::use_debugger() ) ) {
                $this->templates = wp_cache_get( 'dustpress/templates' );
            }

            if ( empty( $this->templates ) || $force ) {
                $templatepaths = $this->get_template_paths( 'partials' );

                foreach ( $templatepaths as $templatepath ) {
                    if ( ! is_readable( $templatepath ) ) {
                        continue;
                    }

                    foreach ( $this->get_files_from_path( $templatepath ) as $file ) {
                        if (
                            ! is_readable( $file->getPathname() ) ||
                            substr( $file->getFilename(), - 5 ) !== '.dust'
                        ) {
                            continue;
                        }
                        // Use only the first found template, do not override.
                        if ( empty( $this->templates[ $file->getFilename() ] ) ) {
                            $this->templates[ $file->getFilename() ] = $file->getPathname();
                        }
                    }
                }

                wp_cache_set( 'dustpress/templates', $this->templates );
            }
        }

        $this->save_dustpress_performance( $performance_measure_id );

        return $this->templates;
    }

    /**
     * This function disables DustPress from doing pretty much anything.
     *
     * @date  2016-06-02
     * @since 0.3.3
     *
     * @param mixed $param
     */
    public function disable( $param = null ) {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );
        $this->disabled         = true;
        $return_value           = $param;

        $this->save_dustpress_performance( $performance_measure_id );

        return $return_value;
    }

    /**
     * This function adds a helper.
     *
     * @date  2016-06-08
     * @since 0.4.0
     *
     * @param string $name
     * @param        $instance
     */
    public function add_helper( string $name, $instance ) : void {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $this->dust->helpers[ $name ] = $instance;

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     * This function adds autoloaders for the classes
     *
     * @date  2016-06-08
     * @since 0.04.0
     */
    private function register_autoloaders() : void {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        // Autoload DustPHP classes
        spl_autoload_register( function ( $class ) {
            // project-specific namespace prefix
            $prefix = 'Dust\\';

            // base directory for the namespace prefix
            $base_dir = __DIR__ . DIRECTORY_SEPARATOR . 'dust' . DIRECTORY_SEPARATOR;

            // does the class use the namespace prefix?
            $len = strlen( $prefix );
            if ( strncmp( $prefix, $class, $len ) !== 0 ) {
                // no, move to the next registered autoloader
                return;
            }

            // get the relative class name
            $relative_class = substr( $class, $len );

            // replace the namespace prefix with the base directory, replace namespace
            // separators with directory separators in the relative class name, append
            // with .php
            $file = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

            // if the file exists, require it
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        } );

        // Autoload DustPress classes
        spl_autoload_register( function ( $class ) {
            $paths = $this->get_template_paths( 'models' );

            $paths[] = __DIR__;

            if ( $class === \DustPress\Query::class ) {
                $class = 'classes' . DIRECTORY_SEPARATOR . 'query';
            }
            elseif ( $class === \DustPress\Model::class ) {
                $class = 'classes' . DIRECTORY_SEPARATOR . 'model';
            }
            elseif ( $class === \DustPress\Helper::class ) {
                $class = 'classes' . DIRECTORY_SEPARATOR . 'helper';
            }
            elseif ( $class === 'DustPress\Data' ) {
                $class = 'classes' . DIRECTORY_SEPARATOR . 'data';
            }
            elseif ( $class === \DustPress\UserActivateExtend::class ) {
                $class = 'classes' . DIRECTORY_SEPARATOR . 'user-activate-extend';
            }
            else {
                $class = $this->camelcase_to_dashed( $class, '-' );
            }

            $filename = strtolower( $class ) . '.php';

            // Store all paths when autoloading for the first time.
            if ( empty( $this->autoload_paths ) ) {
                foreach ( $paths as $path ) {
                    if ( is_readable( $path ) ) {
                        foreach ( $this->get_files_from_path( $path ) as $file ) {
                            $file_basename = $file->getBaseName();
                            $file_fullpath = $file->getPath() . DIRECTORY_SEPARATOR . $file->getFileName();

                            // Ignore certain files as they do not contain any model files.
                            if (
                                strpos( $file_basename, '.' ) === false ||
                                strpos( $file_fullpath, '/.git/' ) !== false
                            ) {
                                continue;
                            }

                            // Only store filepath and filename from the SplFileObject.
                            $this->autoload_paths[] = $file->getPath() . DIRECTORY_SEPARATOR . $file->getFileName();
                        }
                    }
                    elseif ( __DIR__ . DIRECTORY_SEPARATOR . 'models' !== $path ) {
                        http_response_code( 500 );
                        die( 'DustPress error: Your theme does not have required directory ' . $path );
                    }
                }
            }

            // Find the file in the stored paths.
            foreach ( $this->autoload_paths as $file ) {
                if ( ! strpos( $file, DIRECTORY_SEPARATOR . $filename ) ) {
                    continue;
                }
                if ( is_readable( $file ) ) {
                    require_once $file;

                    return;
                }
            }
        } );

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     *  This function returns the paths from which to look for models or partials
     *
     * @date  2016-06-17
     * @since 0.4.0.4
     *
     * @param string $append [models|partials]
     *
     * @return array  list of paths to look in
     */
    private function get_template_paths( string $append ) : array {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $tag = $append ? 'dustpress/' . $append : '';

        $return = apply_filters( $tag, [] );

        $this->save_dustpress_performance( $performance_measure_id );

        return $return;
    }

    /**
     * Add themes to template paths array
     *
     * @date  2018-05-25
     * @since 1.15.1
     *
     * @return void
     */
    private function add_theme_paths() : void {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        foreach ( [ 'models', 'partials' ] as $target ) {
            add_filter( 'dustpress/' . $target, function ( $paths ) use ( $target ) {
                // Set paths for where to look for partials and models
                $theme_paths = [
                    get_stylesheet_directory(),
                    get_template_directory(),
                ];

                $theme_paths = array_values( array_unique( $theme_paths ) );

                array_walk( $theme_paths, function ( &$path ) use ( $target ) {
                    $path .= DIRECTORY_SEPARATOR . $target;
                } );

                return array_merge( $theme_paths, $paths );
            }, 1000, 1 );
        }

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     * Add core path to template paths array
     *
     * @date  2018-05-25
     * @since 1.15.1
     *
     * @return void
     */
    private function add_core_paths() : void {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        foreach ( [ 'models', 'partials' ] as $target ) {
            add_filter( 'dustpress/' . $target, function ( $paths ) use ( $target ) {
                $paths[] = __DIR__ . DIRECTORY_SEPARATOR . $target;

                return $paths;
            }, 1, 1 );
        }

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     * Register a custom route for a model to be used outside the posts context.
     *
     * @date  2018-03-15
     * @since 1.13.0
     *
     * @param string $route    A regular expression to be used as the route.
     * @param string $template The model name to be used with the matching route.
     * @param string $render   How to render the output. Defaults to HTML which means the Dust template
     *                         system.
     *
     * @return void
     */
    public function register_custom_route(
        string $route,
        string $template,
        string $render = 'default'
    ) : void {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $this->custom_routes[ $template ] = $route;

        add_action( 'init', function () use ( $route, $template, $render ) {
            $route_regex = '(' . $route . ')(\/(.+))?\/?$';

            $custom_route_parts = [
                'dustpress_custom_route'            => $template,
                'dustpress_custom_route_route'      => '$matches[1]', // needs to be single quoted string
                'dustpress_custom_route_parameters' => '$matches[3]',  // needs to be single quoted string
                'dustpress_custom_route_render'     => $render,
            ];

            $route_query = sprintf(
                'index.php?%s',
                implode( '&', $custom_route_parts )
            );

            add_rewrite_rule( $route_regex, $route_query, 'top' );
        }, 30 );

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     * Parse DustPress.js request data
     *
     * @date  2018-06-20
     * @since 1.16.1
     *
     * @return void
     * @throws \JsonException If generated or received JSON is broken.
     */
    private function parse_request_data() : void {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        $request_body = file_get_contents( 'php://input' );
        $json         = json_decode( $request_body, false, 512, JSON_THROW_ON_ERROR );

        if ( isset( $json->dustpress_data ) ) {
            $this->request_data = $json->dustpress_data;
        }
        elseif ( isset( $_REQUEST['dustpress_data'] ) ) {
            $args = [
                'bypassMainQuery' => FILTER_VALIDATE_BOOLEAN,
                'partial'         => FILTER_SANITIZE_STRING,
                'path'            => FILTER_SANITIZE_STRING,
                'render'          => FILTER_VALIDATE_BOOLEAN,
                'tidy'            => FILTER_VALIDATE_BOOLEAN,
                'data'            => FILTER_VALIDATE_BOOLEAN,
                'token'           => FILTER_SANITIZE_NUMBER_INT,
                'args'            => [
                    'flags' => FILTER_REQUIRE_ARRAY,
                ],
            ];

            $data = $_REQUEST['dustpress_data'];
            if ( $_SERVER['REQUEST_METHOD'] === 'GET' ) {
                $data                   = $_REQUEST;
                $args['dustpress_data'] = FILTER_VALIDATE_BOOLEAN;
            }

            $this->request_data = (object) \filter_var_array( $data, $args );
        }
        else {
            http_response_code( 500 );
            die( json_encode( [
                'error' => 'Something went wrong. There was no dustpress_data present at the request.',
            ], JSON_THROW_ON_ERROR ) );
        }

        $this->save_dustpress_performance( $performance_measure_id );
    }

    /**
     * Force 404 page and status from anywhere
     *
     * @date  2019-04-03
     * @since 1.23.0
     *
     * @param \DustPress\Model $model Model.
     *
     * @return void
     */
    public function error404( \DustPress\Model $model ) {
        $performance_measure_id = $this->start_dustpress_performance( __FUNCTION__ );

        global $wp_query;

        // Terminate the parent model's execution.
        $model->terminate();

        $wp_query->is_404 = true;

        $debugs = [];

        // We have a very limited list of models to try when it comes to the 404 page.
        foreach ( [ 'Error404', 'Index' ] as $new_model ) {
            if ( class_exists( $new_model ) ) {
                $template = $this->camelcase_to_dashed( $new_model );
                $model->set_template( $template );

                $instance = new $new_model();

                // Set the newly fetched data to the global data to be rendered.
                $model->data[ $new_model ] = $instance->fetch_data();
                \status_header( 404 );

                $this->save_dustpress_performance( $performance_measure_id );

                return;
            }

            $debugs[] = $new_model;
        }

        // Set the proper status code and show error message for not found templates.
        \status_header( 500 );
        die( sprintf(
            'DustPress error: No suitable model found. One of these is required: %s',
            implode( ', ', $debugs )
        ) );
    }

    /**
     * Checks if dustpress-debugger is active.
     *
     * @date  2021-02-26
     * @since 1.32.0
     */
    private function debugger_is_active() : bool {
        return (
            (
                is_user_logged_in() &&
                current_user_can( 'manage_options' ) &&
                get_the_author_meta( 'dustpress_debugger', get_current_user_id() )
            ) ||
            (
                defined( 'DUSTPRESS_DEBUGGER_ALWAYS_ON' ) &&
                \DUSTPRESS_DEBUGGER_ALWAYS_ON === true
            )
        );
    }

    /**
     *  Starts performance time measuring.
     *
     * @date  2019-02-20
     * @since 1.28.0
     *
     * @param string $name
     */
    public function start_performance( string $name ) : void {
        if ( $this->performance_enabled ) {
            $this->performance_timers[ $name ] = microtime( true );
        }
    }

    /**
     *  Stops performance time measuring and saves the measurement.
     *  Can be used with or without start_performance(). If used without, $start_time must be provided.
     *
     * @date  2019-02-20
     * @since 1.28.0
     *
     * @param string   $name
     * @param int|bool $start_time
     *
     * @return false
     */
    public function save_performance( string $name, $start_time = false ) : bool {
        if ( ! $this->performance_enabled ) {
            return false;
        }

        if ( ! $start_time && ! empty( $this->performance_timers[ $name ] ) ) {
            $start_time = $this->performance_timers[ $name ];

            unset( $this->performance_timers[ $name ] );
        }

        if ( ! $start_time ) {
            return false;
        }

        $execution_time = $this->clean_execution_time( microtime( true ) - $start_time );

        // The dot syntax can be used to created "groups".
        // Models.myFunction() becomes [Models][myFunction()].
        if ( strpos( $name, '.' ) !== false ) {
            // Converts the name from [\"ArchiveEvent\",\"ResultCount\"]" to ArchiveEvent->ResultCount().
            $name_explode  = explode( '.', str_replace( [ '[', ']', '\\', '"' ], '', $name ) );
            $group_name    = $name_explode[0];
            $function_name = str_replace( ',', '->', $name_explode[1] );

            // Hooks are not functions so do not append them with a ().
            if ( stripos( $group_name, 'hook' ) === false ) {
                $function_name .= '()';
            }

            $this->performance[ $group_name ][ $function_name ] = $execution_time;

            return true;
        }

        $this->performance[ $name ] = $execution_time;

        return true;
    }

    /**
     * Starts performance time measuring for DustPress.
     *
     * @date  2021-02-26
     * @since 1.32.0
     *
     * @param string $function_name Performance marker name.
     */
    public function start_dustpress_performance( string $function_name ) {
        if ( ! $this->performance_enabled ) {
            return false;
        }

        $measure_id = false;

        if ( ! array_key_exists( $function_name, $this->dustpress_performance_timers ) ) {
            $this->dustpress_performance_timers[ $function_name ] = [];
        }

        $measure_id = $function_name . '--' . (is_countable($this->dustpress_performance_timers[ $function_name ]) ? count( $this->dustpress_performance_timers[ $function_name ] ) : 0);

        $this->dustpress_performance_timers[ $function_name ][] = microtime( true );

        return $measure_id;
    }

    /**
     * Starts performance time measuring for DustPress.
     *
     * @date  2021-02-26
     * @since 1.32.0
     *
     * @param string $measure_id
     *
     * @return bool
     */
    public function save_dustpress_performance( string $measure_id ) : bool {
        if ( ! $this->performance_enabled ) {
            return false;
        }

        $measure_explode = explode( '--', $measure_id, 3 );

        if ( empty( $measure_explode ) || count( $measure_explode ) < 2 ) {
            return false;
        }

        [ $function_name, $measure_nth ] = $measure_explode;

        if ( empty( $this->dustpress_performance_timers[ $function_name ][ $measure_nth ] ) ) {
            return false;
        }

        $execution_time = microtime( true ) - $this->dustpress_performance_timers[ $function_name ][ $measure_nth ];

        $this->performance['DustPress'][ $function_name ][] = $execution_time;

        return true;
    }

    /**
     * Measures how much time passes between the first and last action of each hook.
     *
     * @date  2020-02-28
     * @since 1.28.2
     */
    private function measure_hooks_performance() : void {
        if ( ! $this->performance_enabled ) {
            return;
        }

        // All hooks from https://codex.wordpress.org/Plugin_API/Action_Reference.
        $hooks = [
            'add_admin_bar_menus', 'admin_bar_init', 'admin_bar_menu', 'after_setup_theme',
            'auth_cookie_malformed', 'auth_cookie_valid', 'dynamic_sidebar', 'get_footer', 'get_header',
            'get_search_form', 'get_sidebar', 'get_template_part_content', 'init', 'load_textdomain',
            'loop_end', 'loop_start', 'parse_query', 'parse_request', 'posts_selection', 'pre_get_comments',
            'pre_get_posts', 'register_sidebar', 'send_headers', 'set_current_user', 'shutdown',
            'template_redirect', 'the_post', 'twentyeleven_credits', 'twentyeleven_enqueue_color_scheme',
            'widgets_init', 'wp', 'wp_after_admin_bar_render', 'wp_before_admin_bar_render',
            'wp_default_scripts', 'wp_default_styles', 'wp_enqueue_scripts', 'wp_footer', 'wp_head',
            'wp_loaded', 'wp_meta', 'wp_print_footer_scripts', 'wp_print_scripts', 'wp_print_styles',
            'wp_register_sidebar_widget',
        ];

        // Add a measuring action in the beginning and end of each hook.
        foreach ( $hooks as $hook ) {
            add_action( $hook, function () use ( $hook ) {
                $this->start_performance( 'Hooks.' . $hook );
            }, PHP_INT_MIN );

            add_action( $hook, function () use ( $hook ) {
                $this->save_performance( 'Hooks.' . $hook );
            }, PHP_INT_MAX );

            add_action( $hook, function () use ( $hook ) {
                global $wp_filter;

                if ( ! property_exists( $wp_filter[ $hook ], 'callbacks' ) ) {
                    return;
                }

                foreach ( array_keys( $wp_filter[ $hook ]->callbacks ) as $priority ) {
                    // The priority has to be decreased by a little and increased by a little
                    // so ignore values higher and lower than PHP_INT.
                    if ( $priority <= PHP_INT_MIN || $priority >= PHP_INT_MAX ) {
                        continue;
                    }

                    // We shouldn't track performance of our performance tracking hooks
                    if ( (int) $priority != $priority ) {
                        continue;
                    }

                    // Add an action just before and just after each individual priority,
                    // so we can measure how much each priority takes. The priority is
                    // deliberately cast as string, otherwise it ruins the sorting
                    // order of ksort(*, SORT_NUMERIC).
                    add_action( $hook, function () use ( $hook, $priority ) {
                        $this->start_performance( sprintf(
                            'Hook performance per priority.%s-%s',
                            $hook,
                            $priority
                        ) );
                    }, (string) ( $priority - 0.001 ) );

                    add_action( $hook, function () use ( $hook, $priority ) {
                        $this->save_performance( sprintf(
                            'Hook performance per priority.%s-%s',
                            $hook,
                            $priority
                        ) );
                    }, (string) ( $priority + 0.001 ) );
                }
            }, PHP_INT_MIN );
        }
    }

    /**
     * Adds performance_per_priority data into slow_hooks data and unsets the aforementioned data.
     *
     * @date  2020-02-28
     * @since 1.28.2
     */
    private function combine_slow_hooks_and_performance_per_priority_data() : void {
        if (
            empty( $this->performance ) ||
            ! array_key_exists( 'Slow hook actions', $this->performance ) ||
            ! array_key_exists( 'Hook performance per priority', $this->performance ) ) {
            return;
        }

        $perf_per_priority = (array) $this->performance['Hook performance per priority'];
        $slow_hook_actions = (array) $this->performance['Slow hook actions'];

        foreach ( $perf_per_priority as $hook_and_prio => $execution_time ) {
            // The data looks like: init-10, first part being the hook name and the second one being the priority.
            $explode = explode( '-', $hook_and_prio, 3 );
            [ $hook_name, $priority ] = $explode;

            $priority_key = 'Priority ' . $priority;

            if (
                ! array_key_exists( $hook_name, $slow_hook_actions ) ||
                ! array_key_exists( $priority_key, $slow_hook_actions[ $hook_name ] )
            ) {
                continue;
            }

            // Add more detailed version of each priority to slow hook actions.
            $key = sprintf( 'Priority %s (%s)', $priority, $execution_time );
            $val = $slow_hook_actions[ $hook_name ][ 'Priority ' . $priority ];

            $this->performance['Slow hook actions'][ $hook_name ][ $key ] = $val;

            // Unset the less detailed version.
            unset( $this->performance['Slow hook actions'][ $hook_name ][ $priority_key ] );
        }

        unset( $this->performance['Hook performance per priority'] );
    }

    /**
     *  Adds slow_hooks data into hooks data and unsets the aforementioned data.
     *
     * @date  2020-02-28
     * @since 1.28.2
     */
    private function combine_slow_hooks_and_hooks_data() : void {
        if (
            empty( $this->performance ) ||
            ! array_key_exists( 'Slow hook actions', $this->performance )
        ) {
            return;
        }

        // Combines the $this->performance['Hooks'] with $this->performance['Slow hook actions'].
        foreach (
            $this->performance['Slow hook actions'] as $hook_name =>
            $hook_performance_per_priority
        ) {
            if ( ! array_key_exists( $hook_name, $this->performance['Hooks'] ) ) {
                continue;
            }

            $hook_total_execution_time = $this->performance['Hooks'][ $hook_name ];

            // Apply more detailed data of this hook to the array.
            // The [] + [] is a hack to get the relevant info appear first in an associative array.
            $this->performance['Hooks'] = [ $hook_name . ' (' . $hook_total_execution_time . ')' => $hook_performance_per_priority ] + $this->performance['Hooks'];

            // Unset the original less detailed data.
            unset( $this->performance['Hooks'][ $hook_name ] );
        }

        // Unset the slow hooks as they are no longer needed. They are now combined to $this->performance['Hooks'].
        unset( $this->performance['Slow hook actions'] );
    }

    /**
     * A public method for DustPress Debugger to use to get the performance data.
     * This method also analyzes the hook performance data.
     *
     * @date  2020-02-25
     * @since 1.28.2
     */
    public function get_performance_data() : array {
        if ( $this->performance_enabled ) {
            $this->parse_slow_hook_actions();
            $this->combine_slow_hooks_and_performance_per_priority_data();
            $this->combine_slow_hooks_and_hooks_data();
            $this->clean_dustpress_performance_data();
        }

        return $this->performance;
    }

    /**
     * A method for DustPress Debugger to use to get the dustpress performance data.
     * This method also analyzes the dustpress performance data.
     *
     * @date  2020-02-25
     * @since 1.32.0
     */
    private function clean_dustpress_performance_data() : void {
        if ( empty( $this->performance['DustPress'] ) ) {
            return;
        }

        $total_dustpress_execution_time = 0;
        foreach ( $this->performance['DustPress'] as $function_name => $execution_times ) {
            $function_name .= '()';

            // This includes render time so subtract them from this one to prevent double calculation.
            if ( $function_name === 'create_instance()' ) {
                $execution_times[0] -= array_sum( $this->performance['DustPress']['render'] );
            }

            if ( (is_countable($execution_times) ? count( $execution_times ) : 0) === 1 ) {
                $cleaned_exec_time = $this->clean_execution_time( array_sum( $execution_times ) );

                $this->performance['DustPressCleaned'][ $function_name ] = $cleaned_exec_time;
            }
            else {
                // The main render includes all other renders, so they are subtracted from it
                // to prevent double calculation
                if ( $function_name === 'render()' ) {
                    $calc = array_sum( array_slice( $execution_times, 0, - 1 ) );

                    $execution_times[ (is_countable($execution_times) ? count( $execution_times ) : 0) - 1 ] = end( $execution_times ) - $calc;
                }

                $execution_times_cleaned = array_map( [ $this, 'clean_execution_time' ], $execution_times );

                $cleaned_key = sprintf(
                    '%s = %s, %sx',
                    $function_name,
                    $this->clean_execution_time( array_sum( $execution_times ) ),
                    is_countable($execution_times) ? count( $execution_times ) : 0
                );

                $this->performance['DustPressCleaned'][ $cleaned_key ] = $execution_times_cleaned;
            }

            $total_dustpress_execution_time += array_sum( $execution_times );
        }

        # Add total execution time to DustPress key.
        $perf_key = sprintf(
            'DustPress (%s)',
            $this->clean_execution_time( $total_dustpress_execution_time )
        );

        $this->performance[ $perf_key ] = $this->performance['DustPressCleaned'];

        // The original values are stored in: $this->performance['DustPress']
        // The cleaned values are stored in: $this->performance['DustPressCleaned']
        // The final values are stored in: $this->performance['DustPress (totalruntime)]
        // The first two are no longer needed.
        unset( $this->performance['DustPress'], $this->performance['DustPressCleaned'] );
    }

    /**
     * Cleans and colorizes execution times.
     *
     * @date  2020-02-25
     * @since 1.32.0
     */
    private function clean_execution_time( $execution_time ) : string {
        // Having all irrelevant times marked as "< 0.02" speeds up the reading of the performance report.
        return ( $execution_time < 0.02 )
            ? '[GREEN]< 0.02s[/GREEN]'
            : '[RED]' . round( $execution_time, 4 ) . 's[/RED]';
    }

    /**
     *  Parses the $wp_filter to see what actions are being run in each of the slow hooks.
     *
     * @date  2020-02-25
     * @since 1.28.2
     */
    private function parse_slow_hook_actions() {
        global $wp_filter;

        if ( ! array_key_exists( 'Hooks', $this->performance ) ) {
            return;
        }

        foreach ( $this->performance['Hooks'] as $hook_name => $execution_time ) {
            // Only analyze hooks that took over 0.02s to execute.
            if ( $execution_time === '< 0.02s' ) {
                continue;
            }

            // Make sure there are hooks to parse.
            if ( ! property_exists( $wp_filter[ $hook_name ], 'callbacks' ) ) {
                continue;
            }

            $callback_data = [];

            foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
                // Ignore hooks made by performance measurements itself.
                if (
                    $priority === PHP_INT_MIN ||
                    $priority === PHP_INT_MAX ||
                    strpos( $priority, '.' ) !== false
                ) {
                    continue;
                }

                if ( empty( $callbacks ) ) {
                    continue;
                }

                foreach ( $callbacks as $callback_key => $callback ) {
                    $tmp_action = false;

                    if ( array_key_exists( 'function', $callback ) ) {
                        // The callback is an array.
                        // Usually it means a method within an object (both static and instantiated).
                        if ( is_array( $callback['function'] ) ) {
                            // Object->method() or Object::method().
                            $tmp_action = $this->parse_hook_callback_name( $callback['function'],
                                $callback_key );

                            // This usually doesn't happen. It's a backup to catch any rare circumstances.
                            if ( ! $tmp_action ) {
                                foreach ( $callback['function'] as $function ) {
                                    if ( ! is_array( $tmp_action ) ) {
                                        $tmp_action = [];
                                    }

                                    $tmp_action = $this->parse_hook_callback_name( $function );
                                }
                            }
                        }
                        // The callback is a normal function without an object.
                        else {
                            $tmp_action = $this->parse_hook_callback_name( $callback['function'] );
                        }
                    }

                    // Group all actions by their priority.
                    if ( ! empty( $tmp_action ) ) {
                        $priority_key = 'Priority ' . $priority;

                        if ( ! array_key_exists( $priority_key, $callback_data ) ) {
                            $callback_data[ $priority_key ] = [];
                        }

                        $callback_data[ $priority_key ][] = $tmp_action;
                    }
                }
            }

            if ( ! empty( $callback_data ) ) {
                $this->performance["Slow hook actions"][ $hook_name ] = $callback_data;
            }
        }
    }

    /**
     * A helper function to convert $wp_filter data into meaningful object and method names.
     *
     * @date  2020-02-28
     * @since 1.28.2
     *
     * @param mixed       $data
     * @param string|null $callback_key
     *
     * @throws \ReflectionException
     */
    private function parse_hook_callback_name( $data, string $callback_key = null ) {
        // Check if the callback is Object->method() or Object::method().
        if ( is_array( $data ) && count( $data ) === 2 ) {
            // Object->method().
            if ( is_object( $data[0] ) && is_string( $data[1] ) ) {
                return get_class( $data[0] ) . '->' . $data[1] . '()';
            }

            // Object::method().
            if ( ! empty( $callback_key ) && strpos( $callback_key, '::' ) !== false ) {
                return $data[0] . '::' . $data[1] . '()';
            }
        }
        else {
            if ( $data instanceof Closure ) {
                $reflection = new ReflectionFunction( $data );

                return (string) $reflection;
            }

            if ( is_object( $data ) ) {
                return 'Class ' . get_class( $data );
            }

            // The callback is a function.
            if ( is_string( $data ) ) {
                return $data . '()';
            }
        }

        return false;
    }

    /**
     * Shorter version of RecursiveIterator generation.
     *
     * @param string $path Path to return non-hidden files and directories from.
     *
     * @return \RecursiveIteratorIterator
     */
    public function get_files_from_path( string $path ) : RecursiveIteratorIterator {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
        );
    }
}

// Global function that returns the DustPress singleton
function dustpress() {
    return DustPress::instance();
}
