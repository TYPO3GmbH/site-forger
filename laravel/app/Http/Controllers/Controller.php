<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use TYPO3Fluid\Fluid\Core\Cache\SimpleFileCache;
use TYPO3Fluid\Fluid\View\TemplateView;

/**
 * Class Controller.
 */
abstract class Controller extends BaseController
{
    use ValidatesRequests;

    /**
     * @var TemplateView
     */
    protected $view;

    /**
     * Controller constructor.
     */
    public function __construct()
    {

        $rootPaths['templates'] = [
            base_path().'/resources/Private/Templates/'
        ];
        $rootPaths['partials'] = [
            base_path().'/resources/Private/Partials/'
        ];
        $rootPaths['layouts'] = [
            base_path().'/resources/Private/Layouts/'
        ];
        if ((string)config('app.app.templateOverride') !== '') {
            $rootPaths['templates'][] = base_path().'/resources/' . config('app.app.templateOverride') . '/Templates/';
            $rootPaths['partials'][] = base_path().'/resources/' . config('app.app.templateOverride') . '/Partials/';
            $rootPaths['layouts'][] = base_path().'/resources/' . config('app.app.templateOverride') . '/Layouts/';
        }
        $this->view = new TemplateView();
        $paths = $this->view->getTemplatePaths();
        $paths->setTemplateRootPaths($rootPaths['templates']);
        $paths->setLayoutRootPaths($rootPaths['layouts']);
        $paths->setPartialRootPaths($rootPaths['partials']);
        $fluidCaching = env('FLUID_CACHE');
        if ($fluidCaching) {
            $fluidCache = new SimpleFileCache(base_path().'/storage/app/Cache/Fluid');
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
