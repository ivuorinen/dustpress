<?php

namespace DustPress;

class Get extends Helper {
    public function output() {
        if ( ! isset( $this->params->object ) ) {
            return 'DustPress get helper error: No object specified.';
        }
        if ( ! isset( $this->params->key ) ) {
            return 'DustPress get helper error: No key specified.';
        }

        $object = $this->params->object;
        $key    = $this->params->key;

        if ( is_object( $object ) && isset( $object->{$key} ) ) {
            return $object->{$key};
        }

        if ( is_array( $object ) && isset( $object[ $key ] ) ) {
            return $object[ $key ];
        }

        return '';
    }
}

$this->add_helper( 'get', new Get() );
