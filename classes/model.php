<?php
/**
 * DustPress Model Class
 *
 * Extendable class which contains basic functions for DustPress models.
 *
 * @class DustPress_Model
 */

namespace DustPress;

use get_class;

/**
 * The base class for template models.
 */
class Model {

    // The data
    public $data;

    // Class name
    protected $class_name;

    // Arguments of this instance
    private $args = [];

    // Instances of all submodels initiated from this class
    private $submodels;

    // Possible parent model
    private $parent;

    // Possible wanted template
    private $template;

    // Temporary hash key
    private $hash;

    // Is execution terminated
    private $terminated;

    // Called submodels
    protected $called_subs;

    // Methods that are allowed to run externally
    protected $api;

    // The TTL for model cache
    protected $ttl;

    /**
     * Constructor for DustPress model class.
     *
     * @date   2015-08-10
     * @since  0.2.0
     *
     * @param array $args   Arguments.
     * @param mixed $parent Parent model.
     */
    public function __construct( $args = [], $parent = null ) {
        $this->fix_deprecated();

        if ( ! empty( $args ) ) {
            $this->args = $args;
        }

        $this->submodels = (object) [];

        $this->parent = $parent;
    }

    /**
     * Get model's arguments
     *
     * @date  2016-06-03
     * @since 0.4.0
     *
     * @return array
     */
    public function get_args() {
        return $this->args;
    }

    /**
     * Set the arguments for a model
     *
     * @date  2018-01-26
     * @since 1.11.0
     *
     * @param mixed $args Arguments.
     *
     * @return void
     */
    public function set_args( $args ) {
        $this->args = $args;
    }

    /**
     * Get the data from this model after fetch_data() has been run.
     *
     * @date  2016-06-03
     * @since 0.4.0
     *
     * @return mixed
     */
    public function get_data() {
        return $this->data;
    }

    /**
     * Get the instance of an instantiated submodel.
     *
     * @date  2016-06-03
     * @since 0.4.0
     *
     * @param string $name Submodel name.
     *
     * @return \Dustpress\Model
     */
    public function get_submodel( $name ) {
        return $this->submodels->{$name};
    }

    /**
     * Get all instantiated submodels for this model as an array
     *
     * @date  2016-06-03
     * @since 0.4.0
     *
     * @return array|object
     */
    public function get_submodels() {
        return $this->submodels;
    }

    /**
     * Get the ancestor of this model.
     *
     * @date  2016-06-03
     * @since 0.4.0
     *
     * @param Model|null $model The model you want ancestor of.
     *
     * @return \Dustpress\Model
     */
    public function get_ancestor( $model = null ) {
        if ( ! isset( $model ) ) {
            return $this->get_ancestor( $this );
        }
        if ( isset( $model->parent ) ) {
            return $this->get_ancestor( $model->parent );
        }

        return $model;
    }

    /**
     * This function ensures deprecated functionality will not break the model.
     *
     * @date   16/02/2017
     * @since  1.5.5
     */
    public function fix_deprecated() {
        // Reassign deprecated "allowed_functions" to "api".
        if ( isset( $this->allowed_functions ) ) {
            error_log( 'DustPress: Model property "allowed_functions" is deprecated, use "api" instead.' );
            $this->api = $this->allowed_functions;
        }
    }

    /**
     * This function gets the data from models and binds it to the global data structure.
     *
     * @date  2015-10-15
     * @since 0.2.0
     *
     * @param array $functions
     * @param bool  $tidy
     *
     * @return mixed
     * @throws \ReflectionException
     */
    public function fetch_data( $functions = null, $tidy = false ) {
        $this->class_name = get_class( $this );

        // Create a place to store the wanted data in the global data structure.
        if ( ! isset( $this->data[ $this->class_name ] ) ) {
            $this->data[ $this->class_name ] = new \stdClass();
        }

        // Fetch all methods from given class and in its parents.
        $methods = $this->get_class_methods( $this->class_name );

        $method_names = [];

        // If a method has been overridden, remove duplicate instances so that they won't get run twice.
        foreach ( $methods as $model => $values ) {
            foreach ( $values as $key => $value ) {
                if ( isset( $method_names[ $value ] ) ) {
                    unset( $methods[ $model ][ $key ] );
                    continue;
                }
                $method_names[ $value ] = true;
            }
        }

        unset( $method_names );

        // Check that all asked functions exist
        $this->check_methods_exists( $functions, $methods );

        // If we are on an AJAX call, we may want to run some private or protected functions too
        $private_methods = [];

        // Loop through the methods
        foreach ( $methods as $class => &$class_methods ) {
            foreach ( $class_methods as $index => $method_item ) {
                $reflection = new \ReflectionMethod( $class, $method_item );

                // If we have wanted list of functions, check if we can run them and don't run
                // anything else.
                if ( is_array( $functions ) && count( $functions ) > 0 ) {
                    if ( ! $this->in_array_r( $method_item, $functions ) ) {
                        continue;
                    }
                    if ( ! $this->is_function_allowed( $method_item ) ) {
                        $error_txt = sprintf(
                            "Method '%s' is not allowed to be run via AJAX or does not exist.",
                            $method_item
                        );
                        die( wp_json_encode( [ 'error' => $error_txt ] ) );
                    }
                    if ( $reflection->isProtected() || $reflection->isPrivate() ) {
                        $private_methods[] = $method_item;
                        continue;
                    }
                    // If the method has parameters, it should be run manually
                    if ( $reflection->getNumberOfParameters() > 0 ) {
                        unset( $class_methods[ $index ] );
                        continue;
                    }
                    $class_methods[ $index ] = [ $class, $method_item ];
                    continue;
                }

                if ( $reflection->isPublic() ) {
                    // If the method has parameters, it should be run manually
                    if ( $reflection->getNumberOfParameters() > 0 ) {
                        unset( $class_methods[ $index ] );
                        continue;
                    }
                    $class_methods[ $index ] = [ $class, $method_item ];
                    continue;
                }
                unset( $class_methods[ $index ] );
            }
        }

        // Add some filters
        $methods         = apply_filters(
            'dustpress/methods',
            $methods,
            $this->class_name
        );
        $private_methods = apply_filters(
            'dustpress/private_methods',
            $private_methods,
            $this->class_name
        );

        // If we want tidy output, init variable for that
        if ( $tidy ) {
            $tidy_data = (object) [];
        }

        $methods = array_reverse( $methods );

        // Loop through all public methods and run the ones we wanted to deliver the data to the views.
        foreach ( $methods as $class => $class_methods ) {
            foreach ( $class_methods as $name => $m ) {
                if ( is_array( $m ) && isset( $m[1] ) && is_string( $m[1] ) ) {
                    if ( $m[1] === '__construct' ) {
                        continue;
                    }

                    $method = str_replace( 'bind_', '', $m[1] );

                    if ( ! isset( $this->data[ $this->class_name ] ) ) {
                        $this->data[ $this->class_name ] = (object) [];
                    }

                    $data = $this->run_function( $m[1], $class );

                    if ( $tidy ) {
                        $tidy_data->{$m[1]} = $data;
                        continue;
                    }

                    if ( ! is_null( $data ) ) {
                        $content                         = (array) $this->data[ $this->class_name ];
                        $content[ $method ]              = $data;
                        $this->data[ $this->class_name ] = (object) $content;
                    }
                }

                if ( is_callable( $m ) ) {
                    if ( $m === '__construct' ) {
                        continue;
                    }

                    $method = str_replace( 'bind_', '', $m );

                    if ( ! isset( $this->data[ $this->class_name ]->{$method} ) ) {
                        $this->data[ $this->class_name ]->{$method} = [];
                    }

                    $data = $this->run_function( $m, $class );

                    if ( ! is_null( $data ) ) {
                        if ( $tidy ) {
                            $tidy_data->{$method} = $data;
                            continue;
                        }
                        $this->data[ $this->class_name ]->{$method} = $data;
                    }
                }

                if ( $this->terminated === true ) {
                    break 2;
                }
            }

            unset( $class_methods );
        }

        // If there are private methods to run, run them too.
        if ( is_array( $private_methods ) && count( $private_methods ) > 0 ) {
            foreach ( $private_methods as $method ) {
                $data = $this->run_restricted( $method );

                if ( is_null( $data ) ) {
                    continue;
                }
                if ( $tidy ) {
                    $tidy_data->{$method} = $data;
                    continue;
                }
                $content                         = (array) $this->data[ $this->class_name ];
                $content[ $method ]              = $data;
                $this->data[ $this->class_name ] = (object) $content;
            }
        }

        if ( $tidy ) {
            $this->data = $tidy_data;

            return $tidy_data;
        }

        return $this->data[ $this->class_name ];
    }

    /**
     * Check that all the functions can be found and are allowed to run.
     * Extracted from fetch_data.
     *
     * @param array $functions
     * @param array $methods
     */
    private function check_methods_exists( $functions, array $methods ) {
        if ( is_array( $functions ) && count( $functions ) > 0 ) {
            foreach ( $functions as $function ) {
                if ( ! $this->in_array_r( $function, $methods ) ) {
                    $error_txt = sprintf(
                        "Method '%s' is not allowed to be run via AJAX or does not exist.",
                        $function
                    );
                    die( wp_json_encode( [ 'error' => $error_txt ] ) );
                }
            }
        }
    }

    /**
     * This function returns all public methods from current class and it parents up to
     * but not including Model.
     *
     * @date  2015-03-19
     * @since 0.0.1
     *
     * @param string $class_name Class name.
     * @param array  $methods    Array of method names.
     *
     * @return array
     * @throws \ReflectionException
     */
    private function get_class_methods( $class_name, $methods = [] ) {
        $rc   = new \ReflectionClass( $class_name );
        $rmpu = $rc->getMethods();

        if ( ! isset( $methods ) ) {
            $methods = [];
        }
        if ( ! isset( $methods[ $class_name ] ) ) {
            $methods[ $class_name ] = [];
        }

        foreach ( $rmpu as $r ) {
            if ( $r->class === $class_name ) {
                $methods[ $class_name ][] = $r->name;
            }
        }

        $parent = get_parent_class( $class_name );

        if ( ! empty( $parent ) && 'DustPress\Model' !== $parent ) {
            $methods = $this->get_class_methods( $parent, $methods );
        }

        return $methods;
    }

    /**
     * This function checks if a bound submodel is wanted to run and if it is, runs it.
     *
     * @date  2015-03-17
     * @since 0.0.1
     *
     * @param string     $name
     * @param array|null $args
     * @param bool       $cache_sub
     *
     * @throws \Exception 'DustPress error: bind_sub was called with invalid class name: '.
     */
    public function bind_sub( $name, $args = null, $cache_sub = true ) {
        if ( $this->terminated === true ) {
            return;
        }

        $this->class_name = get_class( $this );
        if ( ! is_string( $name ) ) {
            throw new \Exception(
                'DustPress error: bind_sub was called with invalid class name: ' .
                print_r( $name, true )
            );
        }

        $model = new $name( $args, $this );

        // If the submodel is not on the root level, set it under the current submodel.
        if ( $this->parent ) {
            $data       = $model->fetch_data();
            $class_name = $model->class_name;

            if ( isset( $this->data[ $this->class_name ]->{$class_name} ) ) {
                $this->data[ $this->class_name ]->{$class_name} = array_merge(
                    (array) $this->data[ $this->class_name ]->{$class_name},
                    (array) $data
                );
            } else {
                $this->data[ $this->class_name ]->{$class_name} = $data;
            }
        } // Set submodel under the main model.
        else {
            $data       = $model->fetch_data();
            $class_name = $model->class_name;

            if ( isset( $this->data[ $class_name ] ) ) {
                $this->data[ $class_name ] = array_merge( (array) $this->data[ $class_name ], (array) $data );
            } else {
                $this->data[ $class_name ] = $data;
            }
        }

        if ( ! is_object( $this->submodels ) ) {
            $this->submodels = (object) [];
        }

        $this->submodels->{$name} = $model;


        // Store called submodels for caching purposes.
        if ( $cache_sub ) {
            if ( empty( $this->called_subs ) ) {
                $this->called_subs = [];
            }
            $this->called_subs[] = [
                'class_name' => $name,
                'args'       => $args,
            ];
        }

        if ( $model->terminated === true ) {
            $this->terminate();
        }
    }

    /**
     * This function binds the data from the models to the global data structure.
     * It takes the data key as second parameter and optional data block name as third.
     *
     * @date  2015-03-17
     * @since 0.0.1
     *
     * @param mixed  $data
     * @param string $key
     * @param string $model
     *
     * @return boolean
     */
    public function bind( $data, $key = null, $model = null ) {
        if ( ! $key ) {
            die("DustPress error: You need to specify the key if you use bind(). Use return if you want to use the function name.");
        }

        if ( ! isset( $this->class_name ) ) {
            $this->class_name = get_class( $this );
        }

        if ( $model ) {
            // Create a place to store the wanted data in the global data structure.
            if ( ! isset( $this->data[ $model ] ) ) {
                $this->data[ $model ] = new \stdClass();
            }

            if ( ! isset( $this->data[ $model ] ) ) {
                $this->data[ $model ] = (object) [];
            }
            $this->data[ $model ]->{$key} = $data;

            return true;
        }

        // Create a place to store the wanted data in the global data structure.
        if ( ! isset( $this->data[ $this->class_name ] ) ) {
            $this->data[ $this->class_name ] = new \stdClass();
        }

        if ( $this->parent ) {
            $this->data[ $this->class_name ]->{$key} = $data;

            return true;
        }

        if ( ! is_array( $data ) ) {
            $this->data[ $this->class_name ]->{$key} = $data;

            return true;
        }

        if ( isset( $this->data[ $this->class_name ]->{$key} ) ) {
            $this->data[ $this->class_name ]->{$key} = array_merge(
                (array) $this->data[ $this->class_name ]->{$key},
                $data
            );

            return true;
        }
        $this->data[ $this->class_name ]->{$key} = $data;

        return true;
    }

    /**
     * This function returns the desired Dust template, if the developer has defined one instead of default.
     * Otherwise return false.
     *
     * @date   2015-10-15
     * @since  0.2.0
     *
     * @return mixed
     */
    public function get_template() {
        $ancestor = $this->get_ancestor();

        return $this === $ancestor
            ? $this->template
            : $ancestor->get_template();
    }

    /**
     * This function lets the developer to set the template to be used to render a page.
     *
     * @date  2015-10-15
     * @since 0.2.0
     *
     * @param string $template Template name.
     *
     * @return bool
     */
    public function set_template( $template ) {
        if ( ! $template ) {
            return false;
        }

        $ancestor = $this->get_ancestor();

        if ( $this === $ancestor ) {
            $this->template = $template;

            return true;
        }

        $ancestor->set_template( $template );

        return true;
    }

    /**
     * This function checks whether data exists in cache (if cache is enabled)
     * and returns the data or runs the function and returns its return data.
     *
     * @date  2016-01-29
     * @since 0.3.1
     *
     * @param string      $m     Method/function name.
     * @param string|null $class Class name.
     *
     * @return mixed
     * @throws \ReflectionException If the class or method does not exist.
     */
    private function run_function( $m, $class = null ) {
        $cached = $this->get_cached( $m );

        if ( is_null( $class ) ) {
            $class = $this->class_name;
        }

        if ( $cached ) {
            return $cached;
        }

        $reflection = new \ReflectionMethod( $class, $m );

        $data = $reflection->isStatic()
            ? call_user_func( $class . '::' . $m )
            : call_user_func( [ $this, $m ] );

        $subs = null;
        if ( isset( $this->called_subs ) ) {
            $subs = $this->called_subs;
        }

        $this->maybe_cache( $m, $data, $subs );

        // Unset called submodels for this run
        $this->called_subs = null;

        return $data;
    }

    /**
     * This function checks if the function is defined as cacheable and returns the cache if it exists.
     *
     * @date  2016-01-29
     * @since 0.3.1
     *
     * @param string $m Method/function name.
     *
     * @return mixed|false
     * @throws \Exception
     */
    private function get_cached( $m ) {

        if ( ! dustpress()->get_setting( 'cache' ) ) {
            return false;
        }

        $args       = $this->get_args();
        $this->hash = $this->generate_cache_key( $this->class_name, $args, $m );

        $cached = get_transient( $this->hash );

        if ( false === $cached ) {
            return false;
        }

        // Run stored submodel calls
        if ( isset( $cached->subs ) && is_array( $cached->subs ) ) {
            foreach ( $cached->subs as $sub_data ) {
                // Run submodel without caching it.
                $this->bind_sub( $sub_data['class_name'], $sub_data['args'], false );
            }
        }

        return $cached->data;
    }

    /**
     * This function stores data sets to transient cache if it is enabled
     * and indexes cache keys for model-function-pairs.
     *
     * @date  2016-01-29
     * @since 0.3.1
     *
     * @param string $m    Method/function name.
     * @param mixed  $data Data to cache.
     * @param array  $subs Submodels to cache.
     */
    private function maybe_cache( $m, $data, $subs ) {

        // Check whether cache is enabled and model has ttl-settings.
        if ( ! dustpress()->get_setting( 'cache' ) || ! $this->is_cacheable_function( $m ) ) {
            return;
        }

        // If no hash key exists, bail out
        if ( empty( $this->hash ) ) {
            return;
        }

        // Extend data with submodels
        $to_cache = (object) [
            'data' => $data,
            'subs' => $subs,
        ];

        set_transient( $this->hash, $to_cache, $this->ttl[ $m ] );

        // Index key for cache clearing
        $index      = $this->generate_cache_key( $this->class_name, $m );
        $hash_index = get_transient( $index );

        if ( ! is_array( $hash_index ) ) {
            $hash_index = [];
        }

        // Set the data hash key to the index array of this model function
        if ( ! in_array( $this->hash, $hash_index, true ) ) {
            $hash_index[] = $this->hash;
        }

        // Store transient for 30 days
        set_transient( $index, $hash_index, 30 * DAY_IN_SECONDS );
    }

    /**
     * Checks whether the function is to be cached.
     *
     * @param string $m Method/function name.
     *
     * @return bool
     */
    private function is_cacheable_function( $m ) {
        // No caching set
        if ( ! empty( $this->ttl ) && ! is_array( $this->ttl ) ) {
            return false;
        }
        if ( ! empty( $this->ttl ) && is_array( $this->ttl ) ) {
            foreach ( $this->ttl as $key => $val ) {
                if ( $m === $key ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * This functions returns true if asked private or protected functions is
     * allowed to be run via the run wrapper.
     *
     * @date  2015-12-17
     * @since 0.3.0
     *
     * @param string $function Function name.
     *
     * @return bool
     * @throws \ReflectionException
     */
    private function is_function_allowed( $function ) {
        if ( ! defined( 'DOING_AJAX' ) ) {
            $reflection = new \ReflectionMethod( $this, $function );

            return $reflection->isPublic();
        }

        return isset( $this->api ) && is_array( $this->api ) && in_array( $function, $this->api, true );
    }

    /**
     * This functions creates a cache key hash from parameters.
     *
     * @date  2015-12-17
     * @since 0.3.0
     *
     * @return string Cache key
     */
    private function generate_cache_key() {
        $args = func_get_args();
        $seed = '';

        foreach ( $args as $arg ) {
            $seed .= serialize( $arg );
        }

        $seed = apply_filters( 'dustpress/cache/seed', $seed );

        return sha1( $seed );
    }

    /**
     * This function runs a restricted function if it exists in the allowed functions
     * and returns whatever the wanted function returns.
     *
     * @date  2015-12-17
     * @since 0.3.0
     *
     * @param string $function Function name.
     *
     * @return mixed
     * @throws \ReflectionException If the class or method does not exist.
     */
    public function run_restricted( $function ) {
        if ( $this->is_function_allowed( $function ) ) {
            return $this->run_function( $function );
        }

        return (object) [
            'error' => 'Wanted function does not exist in the allowed functions list.',
        ];
    }

    /**
     * Rename current model's data block. Probably for template changing purposes.
     *
     * @date  2016-09-14
     * @since 1.2.0
     *
     * @param string $name New name for the model.
     *
     * @return mixed
     */
    protected function rename_model( $name ) {
        $original = $this->class_name;

        if ( $original === $name ) {
            return;
        }

        $this->class_name = $name;

        if ( isset( $this->data[ $original ] ) ) {
            $this->data[ $name ] = $this->data[ $original ];
            unset( $this->data[ $original ] );
        } elseif ( ! isset( $this->data[ $name ] ) ) {
            $this->data[ $name ] = (object) [];
        }
    }

    /**
     * A recursive array search.
     *
     * @param mixed   $needle
     * @param array   $haystack
     * @param boolean $strict
     *
     * @return boolean
     */
    protected function in_array_r( $needle, $haystack, $strict = true ) {
        foreach ( $haystack as $item ) {
            if (
                (
                $strict
                    ? $item === $needle
                    : $item == $needle
                ) ||
                (
                    is_array( $item ) &&
                    $this->in_array_r( $needle, $item, $strict )
                )
            ) {
                return true;
            }
        }

        return false;
    }

    public function terminate() {
        $this->terminated = true;
    }

    public function get_terminated() {
        return $this->terminated;
    }
}
