<?php
/**
 * This file contains DustPress Helper class.
 */

namespace DustPress;

/**
 * Class Helper
 *
 * @package DustPress
 */
class Helper {
    /**
     * Chunk object.
     *
     * @var \Dust\Evaluate\Chunk
     */
    protected \Dust\Evaluate\Chunk $chunk;
    /**
     * Context object.
     *
     * @var \Dust\Evaluate\Context
     */
    protected \Dust\Evaluate\Context $context;
    /**
     * Bodies to evaluate.
     *
     * @var \Dust\Evaluate\Bodies
     */
    protected \Dust\Evaluate\Bodies $bodies;
    /**
     * Parameters.
     *
     * @var \Dust\Evaluate\Parameters
     */
    protected \Dust\Evaluate\Parameters $params;

    /**
     * Invoice Helper
     *
     * @param \Dust\Evaluate\Chunk      $chunk   Chunk object.
     * @param \Dust\Evaluate\Context    $context Context object.
     * @param \Dust\Evaluate\Bodies     $bodies  Bodies to evaluate.
     * @param \Dust\Evaluate\Parameters $params  Parameters object.
     *
     * @return \Dust\Evaluate\Chunk|null
     */
    public function __invoke(
        \Dust\Evaluate\Chunk $chunk,
        \Dust\Evaluate\Context $context,
        \Dust\Evaluate\Bodies $bodies,
        \Dust\Evaluate\Parameters $params
    ) : ?\Dust\Evaluate\Chunk {
        $this->chunk   = $chunk;
        $this->context = $context;
        $this->bodies  = $bodies;
        $this->params  = $params;

        if ( isset( $this->bodies->dummy ) && method_exists( $this, 'prerun' ) ) {
            $this->prerun();

            return null;
        }

        if ( ! isset( $this->bodies->dummy ) && method_exists( $this, 'init' ) ) {
            return $this->init();
        }
        if ( ! isset( $this->bodies->dummy ) && method_exists( $this, 'output' ) ) {
            return $this->chunk->write( $this->output() );
        }

        return null;
    }
}
