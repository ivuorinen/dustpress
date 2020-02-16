<?php

namespace DustPress;

class Helper {
    /**
     * Dust Chunk
     *
     * @var \Dust\Evaluate\Chunk
     */
    protected $chunk;
    /**
     * Dust Context
     *
     * @var \Dust\Evaluate\Context
     */
    protected $context;
    /**
     * Dust Bodies
     *
     * @var \Dust\Evaluate\Bodies
     */
    protected $bodies;
    /**
     * Dust Parameters
     *
     * @var \Dust\Evaluate\Parameters
     */
    protected $params;

    /**
     * Helper base class invoking method
     *
     * @param \Dust\Evaluate\Chunk      $chunk   Dust Chunk.
     * @param \Dust\Evaluate\Context    $context Dust Context.
     * @param \Dust\Evaluate\Bodies     $bodies  Dust Bodies.
     * @param \Dust\Evaluate\Parameters $params  Dust Parameters.
     *
     * @return \Dust\Evaluate\Chunk|void
     */
    public function __invoke(
        \Dust\Evaluate\Chunk $chunk,
        \Dust\Evaluate\Context $context,
        \Dust\Evaluate\Bodies $bodies,
        \Dust\Evaluate\Parameters $params
    ) {
        $this->chunk   = $chunk;
        $this->context = $context;
        $this->bodies  = $bodies;
        $this->params  = $params;

        if ( isset( $this->bodies->dummy ) && method_exists( $this, 'prerun' ) ) {
            $this->prerun();
        }
        if ( method_exists( $this, 'init' ) ) {
            return $this->init();
        }
        if ( method_exists( $this, 'output' ) ) {
            return $this->chunk->write( $this->output() );
        }
    }
}
