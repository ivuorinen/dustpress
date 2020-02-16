<?php

namespace DustPress;

class Unset_Helper extends Helper {
    public function init() {
        // Let's find the root of the data tree to store our variable there
        $root = $this->find_root( $this->context );

        // Key is a mandatory parameter
        if ( ! isset( $this->params->key ) ) {
            return $this->chunk->write( 'DustPress unset helper error: No key specified.' );
        }
        // It also must be a string
        if ( ! is_string( $this->params->key ) ) {
            return $this->chunk->write( 'DustPress unset helper error: Key is not a string.' );
        }

        $key = $this->params->key;

        if ( is_array( $root->head->value ) ) {
            unset( $root->head->value[ $key ] );
        } else if ( is_object( $root->head->value ) ) {
            unset( $root->head->value->{$key} );
        }

        return $this->chunk;
    }

    /**
     * Recursive function to find the root of the data tree
     *
     * @param object $ctx Context.
     *
     * @return mixed
     */
    private function find_root( $ctx ) {
        return isset( $ctx->parent )
            ? $this->find_root( $ctx->parent )
            : $ctx;
    }
}

$this->add_helper( 'unset', new Unset_Helper() );
