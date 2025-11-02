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
use WPSPCORE\Base\BaseInstances;

class Blade extends BaseInstances {

	public $factory   = null;
	public $container = null;
	public $viewPaths = null;
	public $cachePath = null;

	public function afterConstruct() {
		$this->viewPaths = $this->extraParams['view_paths'];
		$this->cachePath = $this->extraParams['cache_path'];
		$this->container = new Container();
		$this->factory   = $this->createFactory();

		$this->shareVariables();
	}

	/*
	 *
	 */

	public function set($name, $value) {
		$this->{$name} = $value;
		return $this;
	}

	/**
	 * Create view factory instance.
	 *
	 * @return Factory
	 */
	private function createFactory() {
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

	/**
	 * Register custom blade directives.
	 *
	 * @param BladeCompiler $bladeCompiler
	 */
	private function registerDirectives($bladeCompiler) {
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


	/**
	 * Share variables to all views.
	 * @return void
	 */
	private function shareVariables() {
		// Share custom variables to all views.
		$shareVariables = [];
		$shareClass     = '\\' . $this->funcs->_getRootNamespace() . '\\app\\View\\Share';
		$shareVariables = array_merge($shareVariables, $shareClass::instance()->variables());
		global $notice;
		$shareVariables = array_merge($shareVariables, ['notice' => $notice]);
		$this->getFactory()->share($shareVariables);

		// Share current view name to all views.
		$this->getFactory()->composer('*', function($view) {
			$view->with('current_view_name', $view->getName());
		});
	}

	/*
	 *
	 */

	/**
	 * Get view factory instance.
	 *
	 * @return Factory
	 */
	public function getFactory() {
		return $this->factory;
	}

	/**
	 * Render template.
	 *
	 * @param string $string
	 * @param array  $data
	 * @param bool   $deleteCachedView
	 *
	 * @return string
	 */
	public function render($string, $data = [], $deleteCachedView = true) {
		$prevContainerInstance = Container::getInstance();
		Container::setInstance($this->container);

		$component = new class($string) extends Component {

			protected string $template;

			public function __construct($template) {
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

		$view = $this->factory->make($resolvedView, $data);

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

}