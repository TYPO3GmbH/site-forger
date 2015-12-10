<?php namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesCommands;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use TYPO3Fluid\Fluid as Fluid;

abstract class Controller extends BaseController
{
	use DispatchesCommands, ValidatesRequests;

    public function __construct()
    {
        $paths = new Fluid\View\TemplatePaths();
        $paths->setTemplateRootPaths(array(
            base_path() . '/resources/Private/Templates/'
        ));
        $paths->setLayoutRootPaths(array(
            base_path() . '/resources/Private/Layouts/'
        ));
        $paths->setPartialRootPaths(array(
            base_path() . '/resources/Private/Partials/'
        ));

        /**
         * Set up fluid caches
         */
        $fluidCache = new Fluid\Core\Cache\SimpleFileCache(base_path() . '/storage/app/Cache/Fluid');

        $this->view = new Fluid\View\TemplateView($paths, null, $fluidCache);
        $this->view->assign('APP_ENV', 'L5 ' . env('APP_ENV'));

        $context = $this->view->getRenderingContext();
        $context->setControllerName($this->determineControllerName());
        $this->view->setRenderingContext($context);

        $viewHelperResolver = $this->view->getViewHelperResolver();
        $viewHelperResolver->registerNamespace('fl', 'FoT3\\FluidLaravel\\ViewHelpers');
        $viewHelperResolver->registerNamespace('wmdb', 'App\\ViewHelpers');
        $this->view->setViewHelperResolver($viewHelperResolver);
    }

    /**
     * Determines the current controller name and passes it to Fluid
     *
     * @return mixed
     */
    protected function determineControllerName()
    {
        $className = get_class($this);
        $namespaceParts = explode('\\', $className);
        $fullControllerName = array_pop($namespaceParts);
        $controllerName = str_replace('Controller', '', $fullControllerName);
        return $controllerName;
    }
}
