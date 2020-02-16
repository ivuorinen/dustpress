<?php

namespace DustPress;

class Not extends \Dust\Helper\Comparison {
    /**
     * Helper isValid works by checking values against each other.
     * They they are not the same, return true, if they are, return false.
     *
     * @param mixed $key   What to compare.
     * @param mixed $value What to compare against.
     *
     * @return bool
     */
    public function isValid( $key, $value ) {
        return $key != $value;
    }

}

$this->dust->helpers['not'] = new Not();
