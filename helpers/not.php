<?php

namespace DustPress;

class Not extends \Dust\Helper\Comparison {
    public function isValid( $key, $value ) : bool {
        return $key != $value;
    }
}

$this->dust->helpers['not'] = new Not();
