<?php

namespace Dust {

    use Dust\Evaluate\Evaluator;
    use Dust\Parse\Parser;

    class Dust implements \Serializable {
        public const FILE_EXTENSION = '.dust';

        /**
         * @var ?\Dust\Parse\Parser
         */
        public ?Parser $parser = null;

        /**
         * @var ?\Dust\Evaluate\Evaluator
         */
        public ?Evaluator $evaluator = null;

        /**
         * @var Ast\Body[]
         */
        public array $templates = [];

        /**
         * Stores found template paths for faster template loading.
         *
         * @var array
         */
        protected static array $dustFileCache = [];

        /**
         * @var array
         */
        public array $filters = [];

        /**
         * @var array
         */
        public array $helpers = [];

        /**
         * @var array
         */
        public array $automaticFilters = [];

        /**
         * @var array
         */
        public array $includedDirectories = [];

        /**
         * @var object
         */
        public object $autoloaderOverride;

        /**
         * @param \Dust\Parse\Parser|null       $parser
         * @param \Dust\Evaluate\Evaluator|null $evaluator
         */
        public function __construct( Parser $parser = null, Evaluator $evaluator = null ) {
            if ( $parser === null ) {
                $parser = new Parser();
            }

            if ( $evaluator === null ) {
                $evaluator = new Evaluate\Evaluator( $this );
            }

            $this->parser    = $parser;
            $this->evaluator = $evaluator;

            $this->filters = [
                "s"  => new Filter\SuppressEscape(),
                "h"  => new Filter\HtmlEscape(),
                "j"  => new Filter\JavaScriptEscape(),
                "u"  => new Filter\EncodeUri(),
                "uc" => new Filter\EncodeUriComponent(),
                "js" => new Filter\JsonEncode(),
                "jp" => new Filter\JsonDecode(),
            ];
            $this->helpers = [
                "select"      => new Helper\Select(),
                "math"        => new Helper\Math(),
                "eq"          => new Helper\Eq(),
                "any"         => new Helper\Any(),
                "none"        => new Helper\None(),
                "first"       => new Helper\First(),
                "last"        => new Helper\Last(),
                "if"          => new Helper\IfHelper(),
                "lt"          => new Helper\Lt(),
                "lte"         => new Helper\Lte(),
                "ne"          => new Helper\Ne(),
                "gt"          => new Helper\Gt(),
                "gte"         => new Helper\Gte(),
                "default"     => new Helper\DefaultHelper(),
                "sep"         => new Helper\Sep(),
                "size"        => new Helper\Size(),
                "contextDump" => new Helper\ContextDump(),
            ];

            $this->automaticFilters = [
                $this->filters['h'],
            ];
        }

        /**
         * @param string      $source
         * @param string|null $name
         *
         * @return \Dust\Ast\Body|null
         * @throws \Dust\Parse\ParseException
         */
        public function compile( string $source, string $name = null ) {
            $parsed = $this->parser->parse( $source );
            if ( $name != null ) {
                $this->register( $name, $parsed );
            }

            return $parsed;
        }

        /**
         * @param      $source
         * @param null $name
         *
         * @return callable
         */
        public function compileFn( $source, $name = null ) : callable {
            $parsed = $this->compile( $source, $name );

            return fn($context) => $this->renderTemplate( $parsed, $context );
        }

        /**
         * @param      $path
         * @param null $basePath
         *
         * @return null|string
         */
        public function resolveAbsoluteDustFilePath( $path, $basePath = null ) : ?string {
            //add extension if necessary
            if ( substr_compare( $path, self::FILE_EXTENSION, - 5, 5 ) !== 0 ) {
                $path .= self::FILE_EXTENSION;
            }

            //try the current path
            $possible = realpath( $path );

            if ( $possible !== false ) {
                return $possible;
            }

            // Populate cache when run the first time.
            if ( empty( static::$dustFileCache ) ) {
                foreach ( $this->includedDirectories as $directory ) {
                    foreach (
                        new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory,
                            \RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file
                    ) {
                        static::$dustFileCache[] = $file;
                    }
                }
            }

            // Loop through the cache.
            foreach ( static::$dustFileCache as $file ) {
                if ( substr_compare( $file, "/" . $path, strlen( $file ) - strlen( "/" . $path ),
                        strlen( "/" . $path ) ) === 0 ) {
                    return (string) $file;
                }
            }

            return null;
        }

        /**
         * @param string $path
         * @param string $basePath
         *
         * @return \Dust\Ast\Body|null
         */
        public function compileFile( $path, $basePath = null ) : ?Ast\Body {
            //resolve absolute path
            $absolutePath = $this->resolveAbsoluteDustFilePath( $path, $basePath );

            if ( $absolutePath == null ) {
                return null;
            }
            //just compile w/ the path as the name
            $compiled           = $this->compile( file_get_contents( $absolutePath ), $absolutePath );
            $compiled->filePath = $absolutePath;

            return $compiled;
        }

        /**
         * Register
         *
         * @param string         $name     Name.
         * @param \Dust\Ast\Body $template Template.
         */
        public function register( string $name, Ast\Body $template ) : void {
            $this->templates[ $name ] = $template;
        }

        /**
         * @param string      $name
         * @param string|null $basePath
         *
         * @return Ast\Body|null
         */
        public function loadTemplate( string $name, string $basePath = null ) {
            // if there is an override, use it instead
            if ( $this->autoloaderOverride != null ) {
                return $this->autoloaderOverride->__invoke( $name );
            }

            // is it there w/ the normal name?
            if ( ! isset( $this->templates[ $name ] ) ) {
                // what if I used the resolve file version of the name
                $name = $this->resolveAbsoluteDustFilePath( $name, $basePath );

                // if name is null, then it's not around
                if ( $name == null ) {
                    return null;
                }

                // if name is null and not in the templates array, put it there automatically
                if ( ! isset( $this->templates[ $name ] ) ) {
                    $this->compileFile( $name, $basePath );
                }
            }

            return $this->templates[ $name ];
        }

        /**
         * @param string $name
         * @param array  $context
         *
         * @return string
         */
        public function render( string $name, array $context = [] ) {
            return $this->renderTemplate( $this->loadTemplate( $name ), $context );
        }

        /**
         * @param \Dust\Ast\Body $template
         * @param array          $context
         *
         * @return string
         */
        public function renderTemplate( Ast\Body $template, array $context = [] ) {
            return $this->evaluator->evaluate( $template, $context );
        }

        /**
         * @return string
         */
        public function serialize() : string {
            return serialize( $this->templates );
        }

        /**
         * @param string $data
         */
        public function unserialize( $data ) {
            $this->templates = unserialize( $data );
        }
    }
}
