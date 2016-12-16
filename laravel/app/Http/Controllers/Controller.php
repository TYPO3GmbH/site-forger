<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use TYPO3Fluid\Fluid;

abstract class Controller extends BaseController
{
    use ValidatesRequests;

    /**
     * @var Fluid\View\TemplateView
     */
    protected $view;

    public function __construct()
    {
        $this->view = new Fluid\View\TemplateView();
        $paths = $this->view->getTemplatePaths();
        $paths->setTemplateRootPaths(array(
            base_path().'/resources/Private/Templates/',
        ));
        $paths->setLayoutRootPaths(array(
            base_path().'/resources/Private/Layouts/',
        ));
        $paths->setPartialRootPaths(array(
            base_path().'/resources/Private/Partials/',
        ));
        $fluidCaching = env('FLUID_CACHE');
        if ($fluidCaching) {
            /*
             * Set up fluid caches
             */
            $fluidCache = new Fluid\Core\Cache\SimpleFileCache(base_path().'/storage/app/Cache/Fluid');
            $this->view->setCache($fluidCache);
        }
        $this->view->assign('APP_ENV', 'L5 '.env('APP_ENV'));

        $context = $this->view->getRenderingContext();
        $context->setControllerName($this->determineControllerName());
        $this->view->setRenderingContext($context);

        $viewHelperResolver = $this->view->getViewHelperResolver();
        $viewHelperResolver->addNamespace('fl', 'FoT3\\FluidLaravel\\ViewHelpers');
        $viewHelperResolver->addNamespace('frg', 'App\\ViewHelpers');
    }

    /**
     * Determines the current controller name and passes it to Fluid.
     *
     * @return mixed
     */
    protected function determineControllerName()
    {
        $className = get_class($this);
        $namespaceParts = explode('\\', $className);
        $fullControllerName = array_pop($namespaceParts);

        return str_replace('Controller', '', $fullControllerName);
    }
}
