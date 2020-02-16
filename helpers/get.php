<?php

namespace DustPress;

class Get extends Helper {
    /**
     * Get object value by key
     *
     * @return mixed|string
     */
    public function output() {
        if ( ! isset( $this->params->object ) ) {
            return 'DustPress get helper error: No object specified.';
        }
        $object = $this->params->object;

        if ( ! isset( $this->params->key ) ) {
            return 'DustPress get helper error: No key specified.';
        }
        $key = $this->params->key;

        if ( is_object( $object ) && isset( $object->{$key} ) ) {
            return $object->{$key};
        }

        if ( is_array( $object ) && isset( $object[ $key ] ) ) {
            return $object[ $key ];
        }
    }
}

$this->add_helper( 'get', new Get() );
