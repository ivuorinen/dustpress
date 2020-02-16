<?php

namespace DustPress;

class S extends Helper {
    public function output() {
        if ( ! isset( $this->params->s ) ) {
            return __( 'Helper missing parameter "s".' );
        }

        if ( ! isset( $this->params->td ) ) {
            if ( isset( $this->params->x ) ) {
                return _x(
                    $this->params->s,
                    $this->params->x
                );
            }

            return __( $this->params->s );
        }
        if ( isset( $this->params->x ) ) {
            return _x(
                $this->params->s,
                $this->params->x,
                $this->params->td
            );
        }

        return __( $this->params->s, $this->params->td );
    }
}

$this->add_helper( 's', new S() );
