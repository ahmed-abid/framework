<?php
/**
 * Go! OOP&AOP PHP framework
 *
 * @copyright     Copyright 2012, Lissachenko Alexander <lisachenko.it@gmail.com>
 * @license       http://www.opensource.org/licenses/mit-license.php The MIT License
 */

namespace Go\Core;

use Go\Instrument\ClassLoading\AopComposerLoader;
use Go\Instrument\ClassLoading\SourceTransformingLoader;
use Go\Instrument\CleanableMemory;
use Go\Instrument\PathResolver;
use Go\Instrument\Transformer\SourceTransformer;
use Go\Instrument\Transformer\WeavingTransformer;
use Go\Instrument\Transformer\CachingTransformer;
use Go\Instrument\Transformer\FilterInjectorTransformer;
use Go\Instrument\Transformer\MagicConstantTransformer;

use TokenReflection;

/**
 * Whether or not we have a modern PHP
 */
define('IS_MODERN_PHP', version_compare(PHP_VERSION, '5.4.0') >= 0);


/**
 * Abstract aspect kernel is used to prepare an application to work with aspects.
 */
abstract class AspectKernel
{

    /**
     * Version of kernel
     */
    const VERSION = '0.4.3';

    /**
     * Kernel options
     *
     * @var array
     */
    protected $options = array();

    /**
     * Single instance of kernel
     *
     * @var null|static
     */
    protected static $instance = null;

    /**
     * Default class name for container, can be redefined in children
     *
     * @var string
     */
    protected static $containerClass = 'Go\Core\GoAspectContainer';

    /**
     * Aspect container instance
     *
     * @var null|AspectContainer
     */
    protected $container = null;

    /**
     * Protected constructor is used to prevent direct creation, but allows customization if needed
     */
    protected function __construct() {}

    /**
     * Returns the single instance of kernel
     *
     * @return AspectKernel
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * Init the kernel and make adjustments
     *
     * @param array $options Associative array of options for kernel
     */
    public function init(array $options = array())
    {
        $this->options = $this->normalizeOptions($options);
        define('AOP_CACHE_DIR', $this->options['cacheDir']);

        /** @var $container AspectContainer */
        $container = $this->container = new $this->options['containerClass'];
        $container->set('kernel', $this);
        $container->set('kernel.interceptFunctions', $this->options['interceptFunctions']);

        $sourceLoaderFilter = new SourceTransformingLoader();
        $sourceLoaderFilter->register();

        foreach ($this->registerTransformers($sourceLoaderFilter) as $sourceTransformer) {
            $sourceLoaderFilter->addTransformer($sourceTransformer);
        }

        // Register kernel resources in the container for debug mode
        if ($this->options['debug']) {
            $this->addKernelResourcesToContainer($container);
        }

        AopComposerLoader::init();

        // Register all AOP configuration in the container
        $this->configureAop($container);
    }

    /**
     * Returns an aspect container
     *
     * @return null|AspectContainer
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Returns list of kernel options
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Returns default options for kernel. Available options:
     *
     *   debug    - boolean Determines whether or not kernel is in debug mode
     *   appDir   - string Path to the application root directory.
     *   cacheDir - string Path to the cache directory where compiled classes will be stored
     *   includePaths - array Whitelist of directories where aspects should be applied. Empty for everywhere.
     *   excludePaths - array Blacklist of directories or files where aspects shouldn't be applied.
     *   interceptFunctions - boolean Enable support for interception of global functions (experimental)
     *
     * @return array
     */
    protected function getDefaultOptions()
    {
        return array(
            'debug'     => false,
            'appDir'    => __DIR__ . '/../../../../../../',
            'cacheDir'  => null,

            'interceptFunctions' => false,
            'prebuiltCache'      => false,
            'includePaths'       => array(),
            'excludePaths'       => array(),
            'containerClass'     => static::$containerClass,
        );
    }


    /**
     * Normalizes options for the kernel
     *
     * @param array $options List of options
     *
     * @return array
     */
    protected function normalizeOptions(array $options)
    {
        $options = array_replace($this->getDefaultOptions(), $options);

        $options['appDir']   = PathResolver::realpath($options['appDir']);
        $options['cacheDir'] = PathResolver::realpath($options['cacheDir']);
        $options['includePaths'] = PathResolver::realpath($options['includePaths']);
        $options['excludePaths'] = PathResolver::realpath($options['excludePaths']);

        return $options;
    }

    /**
     * Configure an AspectContainer with advisors, aspects and pointcuts
     *
     * @param AspectContainer $container
     *
     * @return void
     */
    abstract protected function configureAop(AspectContainer $container);

    /**
     * Returns list of source transformers, that will be applied to the PHP source
     *
     * @param SourceTransformingLoader $sourceLoader Instance of source loader for information
     *
     * @return array|SourceTransformer[]
     */
    protected function registerTransformers(SourceTransformingLoader $sourceLoader)
    {
        $filterInjector   = new FilterInjectorTransformer($this->options, $sourceLoader->getId());
        $magicTransformer = new MagicConstantTransformer($this);
        $aspectKernel     = $this;

        $sourceTransformers = function () use ($filterInjector, $magicTransformer, $aspectKernel) {
            return array(
                $filterInjector,
                $magicTransformer,
                new WeavingTransformer(
                    $aspectKernel,
                    new TokenReflection\Broker(
                        new CleanableMemory()
                    ),
                    $aspectKernel->getContainer()->get('aspect.advice_matcher')
                )
            );
        };

        return array(
            new CachingTransformer($this, $sourceTransformers)
        );
    }

    /**
     * Add resources for kernel
     *
     * @param AspectContainer $container
     */
    protected function addKernelResourcesToContainer(AspectContainer $container)
    {
        $trace    = debug_backtrace();
        $refClass = new \ReflectionObject($this);

        $container->addResource($trace[1]['file']);
        $container->addResource($refClass->getFileName());
    }
}
