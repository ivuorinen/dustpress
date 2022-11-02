<?php

namespace DustPress;

class Sep extends Helper {
    public function init() {
        $end   = $this->params->end ?? 1;
        $start = $this->params->start ?? 0;

        $iterationCount = $this->context->get( '$iter' );

        if ( $iterationCount === null ) {
            $this->chunk->setError( 'Sep must be inside an array' );
        }

        $len = $this->context->get( '$len' );

        return $iterationCount >= $start && $iterationCount < $len - $end
            ? $this->chunk->render( $this->bodies->block, $this->context )
            : $this->chunk;
    }
}

$this->dust->helpers['sep'] = new Sep();
