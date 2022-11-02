<?php

namespace Dust\Evaluate {

    use Dust\Ast;

    class Bodies implements \ArrayAccess {
        private \Dust\Ast\Section $section;

        /**
         * @var \Dust\Ast\Body
         */
        public Ast\Body $block;

        /**
         * @param \Dust\Ast\Section $section
         */
        public function __construct( Ast\Section $section ) {
            $this->section = $section;
            $this->block   = $section->body;
        }

        /**
         * @param mixed $offset
         *
         * @return bool
         */
        public function offsetExists( $offset ) : bool {
            return $this[ $offset ] !== null;
        }

        /**
         * @param mixed $offset
         *
         * @return null
         */
        public function offsetGet( $offset ) {
            for (
                $i = 0;
                $i < ( is_countable( $this->section->bodies ) ? count( $this->section->bodies ) : 0 ); $i ++
            ) {
                if ( $this->section->bodies[ $i ]->key == $offset ) {
                    return $this->section->bodies[ $i ]->body;
                }
            }

            return null;
        }

        /**
         * @param mixed $offset
         * @param mixed $value
         *
         * @throws \Dust\Evaluate\EvaluateException
         */
        public function offsetSet( $offset, $value ) {
            throw new EvaluateException( $this->section, 'Unsupported set on bodies' );
        }

        /**
         * @param mixed $offset
         *
         * @throws \Dust\Evaluate\EvaluateException
         */
        public function offsetUnset( $offset ) {
            throw new EvaluateException( $this->section, 'Unsupported unset on bodies' );
        }

    }
}
