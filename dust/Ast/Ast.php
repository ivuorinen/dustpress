<?php

namespace Dust\Ast;

class Ast {
    /**
     * @var int
     */
    public int $offset;

    /**
     * @param $offset
     *
     * @constructor
     */
    public function __construct( $offset ) {
        $this->offset = $offset;
    }

}
