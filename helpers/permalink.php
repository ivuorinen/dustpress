<?php

namespace DustPress;

class Permalink extends Helper {
    public function output() {
        return isset( $this->params->id )
            ? get_permalink( $this->params->id )
            : get_permalink();
    }
}

$this->dust->helpers['permalink'] = new Permalink();
