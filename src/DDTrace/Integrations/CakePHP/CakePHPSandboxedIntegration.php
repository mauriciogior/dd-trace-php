<?php

namespace DDTrace\Integrations\CakePHP;

use CakeRequest;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use Router;

class CakePHPSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'cakephp';

    public $appName;
    public $rootSpan;

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return self::NOT_AVAILABLE;
        }

        $integration = $this;

        // CakePHP v2.x - we don't need to check for v3 since it does not have \Dispatcher or \ShellDispatcher
        $initCakeV2 = function () use ($integration) {
            // Since "Dispatcher" and "App" are common names, check for a CakePHP signature before loading
            if (!defined('CAKE_CORE_INCLUDE_PATH')) {
                return false;
            }

            $scope = GlobalTracer::get()->getRootScope();
            if (!$scope) {
                return false;
            }
            $integration->appName = \ddtrace_config_app_name(CakePHPSandboxedIntegration::NAME);
            $integration->rootSpan = $scope->getSpan();
            $integration->rootSpan->setIntegration($integration);
            $integration->rootSpan->setTraceAnalyticsCandidate();
            $integration->rootSpan->setTag(Tag::SERVICE_NAME, $integration->appName);
            if ('cli' === PHP_SAPI) {
                $integration->rootSpan->overwriteOperationName('cakephp.console');
                $integration->rootSpan->setTag(
                    Tag::RESOURCE_NAME,
                    !empty($_SERVER['argv'][1]) ? 'cake_console ' . $_SERVER['argv'][1] : 'cake_console'
                );
            } else {
                $integration->rootSpan->overwriteOperationName('cakephp.request');
            }

            \dd_trace_method('Controller', 'invokeAction', function (SpanData $span, array $args) use ($integration) {
                $span->name = $span->resource = 'Controller.invokeAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->appName;

                $request = $args[0];
                if (!$request instanceof CakeRequest) {
                    return;
                }

                $integration->rootSpan->setTag(
                    Tag::RESOURCE_NAME,
                    $_SERVER['REQUEST_METHOD'] . ' ' . $this->name . 'Controller@' . $request->params['action']
                );
                $integration->rootSpan->setTag(Tag::HTTP_URL, Router::url($request->here, true));
                $integration->rootSpan->setTag('cakephp.route.controller', $request->params['controller']);
                $integration->rootSpan->setTag('cakephp.route.action', $request->params['action']);
                if (isset($request->params['plugin'])) {
                    $integration->rootSpan->setTag('cakephp.plugin', $request->params['plugin']);
                }
            });

            // This only traces the default exception renderer
            // Remove this when error tracking is added
            // Other possible places to trace
            // - ErrorHandler::handleException()
            // - Controller::appError()
            // - Exception.handler
            // - Exception.renderer
            \dd_trace_method('ExceptionRenderer', '__construct', [
                'instrument_when_limited' => 1,
                'posthook' => function (SpanData $span, array $args) use ($integration) {
                    $integration->rootSpan->setError($args[0]);
                    return false;
                },
            ]);

            // Create a trace span for every template rendered
            \dd_trace_method('View', 'render', function (SpanData $span) use ($integration) {
                $span->name = 'cakephp.view';
                $span->type = Type::WEB_SERVLET;
                $file = $this->viewPath . '/' . $this->view . $this->ext;
                $span->resource = $file;
                $span->meta = ['cakephp.view' => $file];
                $span->service = $integration->appName;
                $integration->addIntegrationInfo($span);
            });

            return false;
        };

        if ('cli' === PHP_SAPI) {
            // CLI bootstrap
            //\dd_trace_method('ShellDispatcher', '__construct', $initCakeV2);
            // Workaround until we fix request_init_hook for non-autoloaded projects
            \dd_trace_method('App', 'init', $initCakeV2);
        } else {
            // Web bootstrap
            \dd_trace_method('Dispatcher', '__construct', $initCakeV2);
        }

        return SandboxedIntegration::LOADED;
    }
}
