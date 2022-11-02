<?php

namespace DustPress;

class Strtodate extends Helper {
    public function output() : string {
        $value  = $this->params->value;
        $format = $this->params->format ?? get_option( 'date_format' );
        $now    = $this->params->now;

        return date_i18n( $format, strtotime( $value, $now ) );
    }
}

$this->add_helper( 'strtodate', new Strtodate() );
