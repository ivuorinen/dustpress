<?php

namespace DustPress;

class Strtodate extends Helper {
    public function output() {
        $value = $this->params->value;
        $now   = $this->params->now;

        $format = isset( $this->params->format )
            ? $this->params->format
            : get_option( 'date_format' );

        return date_i18n( $format, strtotime( $value, $now ) );
    }
}

$this->add_helper( 'strtodate', new Strtodate() );
