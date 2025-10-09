<?php

namespace WPSPCORE\View;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Fluent;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Component;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Throwable;

class Blade {

	public $mainPath;
	public $rootNamespace;
	public $prefixEnv;
	public $customProperties;

	/** @var \WPSPCORE\Funcs */
	public $funcs;

	public array  $viewPaths;
	public string $cachePath;

	public Container $container;
	public Factory   $instance;


	public static ?Blade $BLADE = null;

	public function __construct(
		$mainPath = null,
		$rootNamespace = null,
		$prefixEnv = null,
		$customProperties = [],
		array $viewPaths = [],
		string $cachePath = '/cached/views'
	) {
		$this->mainPath         = $mainPath;
		$this->rootNamespace    = $rootNamespace;
		$this->prefixEnv        = $prefixEnv;
		$this->customProperties = $customProperties;
		$this->funcs            = $customProperties['funcs'] ?? null;

		$this->viewPaths = $viewPaths;
		$this->cachePath = $cachePath;

		$this->container = new Container();
		$this->instance  = $this->createFactory();
	}

	public function view(): Factory {
		return $this->instance;
	}

	public function render(string $string, array $data = [], bool $deleteCachedView = true): string {
		$prevContainerInstance = Container::getInstance();
		Container::setInstance($this->container);

		$component = new class($string) extends Component {

			protected string $template;

			public function __construct(string $template) {
				$this->template = $template;
			}

			public function render(): string {
				return $this->template;
			}

		};

		$resolvedView = $component->resolveView();
		if (!is_string($resolvedView)) {
			return '';
		}

		$view = $this->instance->make($resolvedView, $data);

		try {
			$result = tap($view->render(), function() use ($view, $deleteCachedView, $prevContainerInstance) {
				if ($deleteCachedView) {
					unlink($view->getPath());
				}
				Container::setInstance($prevContainerInstance);
			});

		}
		catch (Throwable $e) {
			return '';
		}

		return is_string($result)
			? $result
			: '';
	}

	protected function createFactory(): Factory {
		$fs         = new Filesystem();
		$dispatcher = new Dispatcher($this->container);

		$viewResolver  = new EngineResolver();
		$bladeCompiler = new BladeCompiler($fs, $this->cachePath);

		$this->registerDirectives($bladeCompiler);

		$viewResolver->register('blade', function() use ($bladeCompiler) {
			return new CompilerEngine($bladeCompiler);
		});

		$viewFinder  = new FileViewFinder($fs, $this->viewPaths);
		$viewFactory = new Factory($viewResolver, $viewFinder, $dispatcher);
		$viewFactory->setContainer($this->container);
		$this->container->instance(\Illuminate\Contracts\View\Factory::class, $viewFactory);
		$this->container->instance(BladeCompiler::class, $bladeCompiler);
		$this->container->singleton('view', function() use ($viewFactory) {
			return $viewFactory;
		});

		$this->container->singleton('config', function() {
			$config                  = new Fluent();
			$config['view.compiled'] = $this->cachePath;

			return $config;
		});

		return $viewFactory;
	}

	protected function registerDirectives(BladeCompiler $bladeCompiler): void {
		// Định nghĩa @can
		$bladeCompiler->directive('can', function($expression) {
			$authFunction = '\\' . $this->funcs->_getRootNamespace() . '\Funcs';

			if (preg_match('/^["\']?([^"\']+)\|([^"\']+)["\']?$/iu', $expression, $matches)) {
				$guard      = "'" . trim($matches[1]) . "'";
				$permission = "'" . trim($matches[2]) . "'";
				return "<?php if($authFunction::auth($guard)->check() && $authFunction::auth($guard)->user()->can({$permission})): ?>";
			}

			return "<?php if($authFunction::auth()->check() && $authFunction::auth()->user()->can({$expression})): ?>";
		});

		// Định nghĩa @endcan
		$bladeCompiler->directive('endcan', function() {
			return '<?php endif; ?>';
		});
	}

}