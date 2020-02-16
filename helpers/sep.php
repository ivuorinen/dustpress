<?php

namespace DustPress;

class Sep extends Helper {
    public function init() {
        $end = 1;
        if ( isset( $this->params->end ) ) {
            $end = $this->params->end;
        }

        $start = 0;
        if ( isset( $this->params->start ) ) {
            $start = $this->params->start;
        }

        $iter_count = $this->context->get( '$iter' );

        if ( $iter_count === null ) {
            $this->chunk->setError( 'Sep must be inside an array' );
        }
        $len = $this->context->get( '$len' );

        return $iter_count >= $start && $iter_count < $len - $end
            ? $this->chunk->render( $this->bodies->block, $this->context )
            : $this->chunk;
    }
}

$this->dust->helpers['sep'] = new Sep();
