<?php

declare( strict_types = 1 );

namespace Northrook;

use Latte\Engine;
use Latte\Extension;
use Latte\Loader as LoaderInterface;
use Latte\Loaders\FileLoader;
use Northrook\Core\Exception\InvalidTypeException;
use Northrook\Latte\TemplateChainLoader;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Stopwatch\Stopwatch;
use Closure, Throwable, LogicException;
use function array_map, file_exists, in_array, is_object, spl_object_id;

/**
 * @method  render( string $template, object|array $parameters, null|string $block = null )
 * @method  static render( string $template, object|array $parameters, null|string $block = null )
 */
class Latte
{
    private static Latte $environment;

    private readonly TemplateChainLoader $templateLoader;
    private Engine                       $engine;

    private array $globalVariables = [];

    /** @var Extension[] */
    private array $extensions = [];

    /** @var callable[] */
    private array $postprocessors = [];

    protected LoaderInterface | Closure $loader;

    public function __construct(
        protected string           $projectDirectory,
        protected string           $cacheDirectory,
        protected ?Stopwatch       $stopwatch = null,
        protected readonly ?Logger $logger = null,
        public bool                $autoRefresh = true,
    ) {
        $this->stopwatch      ??= new Stopwatch( true );
        $this->templateLoader = new TemplateChainLoader( $this->projectDirectory );
        $this->setStaticAccessor();
    }

    public function __call( string $method, array $arguments ) {
        return match ( $method ) {
            'render' => $this->templateToString( ... $arguments ),
        };
    }

    public static function __callStatic( string $method, array $arguments ) {

        if ( !isset( static::$environment ) ) {
            throw new LogicException(
                'The Latte environment has not been instantiated yet.',
            );
        }

        return match ( $method ) {
            'render' => static::$environment->templateToString( ... $arguments ),
        };
    }

    final public function templateToString(
        string         $template,
        object | array $parameters = [],
        ?string        $block = null,
    ) : string {

        $content = $this->engine()->renderToString(
            $this->templateLoader->load( $template ),
            $this->global( $parameters ),
            $block,
        );

        return $this->postProcessing( $content );
    }


    final protected function postProcessing( string $string ) : string {

        foreach ( $this->postprocessors as $postprocessor ) {
            $string = (string) $postprocessor( $string );
        }

        return $string;
    }


    final protected function engine() : Engine {
        return $this->engine ??= $this->startEngine();
    }

    private function startEngine() : Engine {

        $this->stopwatch->start( 'latte.engine', 'Templating' );

        if ( !file_exists( $this->cacheDirectory ) ) {
            $this->filesystem()->mkdir( $this->cacheDirectory );
        }

        // Initialize the Engine.
        $this->engine = new Engine();

        // Add all registered extensions to the Engine.
        array_map( [ $this->engine, 'addExtension' ], $this->extensions );

        $this->engine->setTempDirectory( $this->cacheDirectory )
                     ->setAutoRefresh( $this->autoRefresh )
                     ->setLoader( $this->loader() );

        $this->logger?->info(
            'Started Latte Engine {id}.',
            [
                'id'     => spl_object_id( $this->engine ),
                'engine' => $this->engine,
            ],
        );

        return $this->engine;
    }

    final protected function loader() : ?LoaderInterface {
        if ( !isset( $this->loader ) ) {
            return $this->loader = new FileLoader();
        }

        if ( $this->loader instanceof Closure ) {
            try {
                $this->loader = $this->loader->__invoke();
            }
            catch ( Throwable $exception ) {
                throw new InvalidTypeException(
                    message : $this::class . ' could not use provided Loader. The passed Closure is not a valid ' . LoaderInterface::class,
                    value   : $this->loader->__invoke(), previous : $exception,
                );
            }
        }

        return $this->loader;
    }

    final public function setLoader( LoaderInterface | Closure $loader ) : self {
        $this->loader = $loader;
        return $this;
    }

    public function addGlobalVariable( string $key, mixed $value ) : self {
        $this->globalVariables[ $key ] = $value;

        return $this;
    }

    /**
     * Add {@see Extension}s.
     *
     * @param Extension  ...$extension
     *
     * @return $this
     */
    final public function addExtension( Extension ...$extension ) : static {

        foreach ( $extension as $addExtension ) {
            if ( in_array( $addExtension, $this->extensions, true ) ) {
                $this->logger?->warning(
                    $this::class . '->addExtension tried to add an already existing extension. Please ensure your config files; you likely have a duplicate call somewhere.',
                );
                continue;
            }
            $this->extensions[] = $addExtension;
        }

        return $this;
    }


    public function addPostprocessor( Closure | callable ...$templateParser ) : self {
        foreach ( $templateParser as $templatePostprocessor ) {
            $this->postprocessors[] = $templatePostprocessor;
        }
        return $this;
    }

    /**
     * Add a directory path to a `templates` directory.
     *
     * - You can set a template priority, higher means it will be checked earlier in the chain.
     * - Setting priority:true sets the highest possible priority
     *
     * @param string    $path
     * @param bool|int  $priority
     *
     * @return $this
     */
    final public function addTemplateDirectory( string $path, bool | int $priority = false ) : static {
        $this->templateLoader->add( $path, $priority );
        return $this;
    }

    final public function clearTemplateCache() : bool {
        try {
            $this->filesystem()->remove( $this->cacheDirectory );
        }
        catch ( IOException $exception ) {
            $this->logger?->error( $exception->getMessage() );
            return false;
        }

        return true;
    }

    /**
     * Adds {@see Latte::$globalVariables} to all templates.
     *
     * - {@see $globalVariables} are not available when using Latte `templateType` objects.
     *
     * @param object|array  $parameters
     *
     * @return object|array
     */
    private function global( object | array $parameters ) : object | array {
        if ( is_object( $parameters ) ) {
            return $parameters;
        }

        return $this->globalVariables + $parameters;
    }

    private function setStaticAccessor() : void {
        if ( isset( static::$environment ) ) {
            throw new LogicException(
                'The Latte environment is a Singleton, and cannot be instantiated twice.',
            );
        }
        $this::$environment ??= $this;
    }

    final protected function filesystem() : Filesystem {
        static $filesystem;
        return $filesystem ??= new Filesystem();
    }
}