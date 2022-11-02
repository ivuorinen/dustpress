<?php

namespace DustPress;

class Unset_Helper extends Helper {
    public function init() : \Dust\Evaluate\Chunk {
        // Let's find the root of the data tree to store our variable there
        $root = $this->find_root( $this->context );

        // Key is a mandatory parameter
        if ( ! isset( $this->params->key ) ) {
            return $this->chunk->write( 'DustPress unset helper error: No key specified.' );
        }

        $key = $this->params->key;

        // It also must be a string
        if ( ! is_string( $key ) ) {
            return $this->chunk->write( 'DustPress unset helper error: Key is not a string.' );
        }

        if ( is_array( $root->head->value ) ) {
            unset( $root->head->value[ $key ] );
        }
        elseif ( is_object( $root->head->value ) ) {
            unset( $root->head->value->{$key} );
        }

        return $this->chunk;
    }

    // Recursive function to find the root of the data tree
    private function find_root( $ctx ) {
        return isset( $ctx->parent )
            ? $this->find_root( $ctx->parent )
            : $ctx;
    }
}

$this->add_helper( 'unset', new Unset_Helper() );
