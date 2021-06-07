<?php

namespace Sentry\Laravel;

use Exception;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\QueueManager;
use Laravel\Octane\Octane;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStopping as OctaneWorkerStopping;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\State\Scope;

class EventHandler
{
    /**
     * Map event handlers to events.
     *
     * @var array
     */
    protected static $eventHandlerMap = [
        'router.matched' => 'routerMatched',                         // Until Laravel 5.1
        'Illuminate\Routing\Events\RouteMatched' => 'routeMatched',  // Since Laravel 5.2

        'illuminate.query' => 'query',                                 // Until Laravel 5.1
        'Illuminate\Database\Events\QueryExecuted' => 'queryExecuted', // Since Laravel 5.2

        'illuminate.log' => 'log',                                // Until Laravel 5.3
        'Illuminate\Log\Events\MessageLogged' => 'messageLogged', // Since Laravel 5.4

        'Illuminate\Console\Events\CommandStarting' => 'commandStarting', // Since Laravel 5.5
        'Illuminate\Console\Events\CommandFinished' => 'commandFinished', // Since Laravel 5.5
    ];

    /**
     * Map authentication event handlers to events.
     *
     * @var array
     */
    protected static $authEventHandlerMap = [
        'Illuminate\Auth\Events\Authenticated' => 'authenticated', // Since Laravel 5.3
    ];

    /**
     * Map queue event handlers to events.
     *
     * @var array
     */
    protected static $queueEventHandlerMap = [
        'Illuminate\Queue\Events\JobProcessing' => 'queueJobProcessing', // Since Laravel 5.2
        'Illuminate\Queue\Events\JobProcessed' => 'queueJobProcessed', // Since Laravel 5.2
        'Illuminate\Queue\Events\JobExceptionOccurred' => 'queueJobExceptionOccurred', // Since Laravel 5.2
        'Illuminate\Queue\Events\WorkerStopping' => 'queueWorkerStopping', // Since Laravel 5.2
    ];

    /**
     * Map queue event handlers to events.
     *
     * @var array
     */
    protected static $octaneEventHandlerMap = [
        'Laravel\Octane\Events\RequestReceived' => 'octaneRequestReceived', //Handle an incoming request
        //'Laravel\Octane\Events\RequestHandled' => '', //Unclear if this is needed
        'Laravel\Octane\Events\RequestTerminated' => 'octaneRequestTerminated', //"Shut down" the application after a request
        //'Laravel\Octane\Events\WorkerStarting' => '', //Unclear if this is needed
        'Laravel\Octane\Events\WorkerErrorOccurred' => 'octaneWorkerErrorOccurred', //Error within worker
        'Laravel\Octane\Events\WorkerStopping' => 'octaneWorkerStopping', //Terminate the worker.
        'Laravel\Octane\Events\TaskReceived' => 'octaneTaskReceived', //Start a Concurrent Task
        'Laravel\Octane\Events\TaskTerminated' => 'octaneTaskTerminated', //End a Concurrent Task
        'Laravel\Octane\Events\TickReceived' => 'octaneTickReceived', //Handle an incoming tick.
        'Laravel\Octane\Events\TickTerminated' => 'octaneTickTerminated' //Handle an incoming tick.
    ];

    /**
     * The Laravel container.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    private $container;

    /**
     * Indicates if we should we add SQL queries to the breadcrumbs.
     *
     * @var bool
     */
    private $recordSqlQueries;

    /**
     * Indicates if we should we add query bindings to the breadcrumbs.
     *
     * @var bool
     */
    private $recordSqlBindings;

    /**
     * Indicates if we should we add Laravel logs to the breadcrumbs.
     *
     * @var bool
     */
    private $recordLaravelLogs;

    /**
     * Indicates if we should we add queue info to the breadcrumbs.
     *
     * @var bool
     */
    private $recordQueueInfo;

    /**
     * Indicates if we should we add command info to the breadcrumbs.
     *
     * @var bool
     */
    private $recordCommandInfo;

    /**
     * Indicates if we should we add tick info to the breadcrumbs.
     *
     * @var bool
     */
    private $recordOctaneTickInfo;

    /**
     * Indicates if we should we add task info to the breadcrumbs.
     *
     * @var bool
     */
    private $recordOctaneTaskInfo;

    /**
     * Indicates if we pushed a scope for the queue.
     *
     * @var bool
     */
    private $pushedQueueScope = false;

    /**
     * Indicates if we pushed a scope for Octane.
     *
     * @var bool
     */
    private $pushedOctaneScope = false;

    /**
     * EventHandler constructor.
     *
     * @param \Illuminate\Contracts\Container\Container $container
     * @param array                                     $config
     */
    public function __construct(Container $container, array $config)
    {
        $this->container = $container;

        $this->recordSqlQueries = ($config['breadcrumbs.sql_queries'] ?? $config['breadcrumbs']['sql_queries'] ?? true) === true;
        $this->recordSqlBindings = ($config['breadcrumbs.sql_bindings'] ?? $config['breadcrumbs']['sql_bindings'] ?? false) === true;
        $this->recordLaravelLogs = ($config['breadcrumbs.logs'] ?? $config['breadcrumbs']['logs'] ?? true) === true;
        $this->recordQueueInfo = ($config['breadcrumbs.queue_info'] ?? $config['breadcrumbs']['queue_info'] ?? true) === true;
        $this->recordCommandInfo = ($config['breadcrumbs.command_info'] ?? $config['breadcrumbs']['command_info'] ?? true) === true;
        $this->recordOctaneTickInfo = ($config['breadcrumbs.octane_tick_info'] ?? $config['breadcrumbs']['octane_tick_info'] ?? true) === true;
        $this->recordOctaneTaskInfo = ($config['breadcrumbs.octane_task_info'] ?? $config['breadcrumbs']['octane_task_info'] ?? true) === true;
    }

    /**
     * Attach all event handlers.
     */
    public function subscribe(): void
    {
        /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
        try {
            $dispatcher = $this->container->make(Dispatcher::class);

            foreach (static::$eventHandlerMap as $eventName => $handler) {
                $dispatcher->listen($eventName, [$this, $handler]);
            }
        } catch (BindingResolutionException $e) {
            // If we cannot resolve the event dispatcher we also cannot listen to events
        }
    }

    /**
     * Attach all authentication event handlers.
     */
    public function subscribeAuthEvents(): void
    {
        /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
        try {
            $dispatcher = $this->container->make(Dispatcher::class);

            foreach (static::$authEventHandlerMap as $eventName => $handler) {
                $dispatcher->listen($eventName, [$this, $handler]);
            }
        } catch (BindingResolutionException $e) {
            // If we cannot resolve the event dispatcher we also cannot listen to events
        }
    }

    /**
     * Attach all queue event handlers.
     *
     * @param \Laravel\Octane\Octane $queue
     */
    public function subscribeOctaneEvents(Octane $queue): void
    {
        /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
        try {
            $dispatcher = $this->container->make(Dispatcher::class);

            foreach (static::$octaneEventHandlerMap as $eventName => $handler) {
                $dispatcher->listen($eventName, [$this, $handler]);
            }
        } catch (BindingResolutionException $e) {
            // If we cannot resolve the event dispatcher we also cannot listen to events
        }
    }

    /**
     * Attach all queue event handlers.
     *
     * @param \Illuminate\Queue\QueueManager $queue
     */
    public function subscribeQueueEvents(QueueManager $queue): void
    {
        $queue->looping(function () {
            $this->cleanupScopeForQueuedJob();
            $this->afterQueuedJob();
        });

        /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
        try {
            $dispatcher = $this->container->make(Dispatcher::class);

            foreach (static::$queueEventHandlerMap as $eventName => $handler) {
                $dispatcher->listen($eventName, [$this, $handler]);
            }
        } catch (BindingResolutionException $e) {
            // If we cannot resolve the event dispatcher we also cannot listen to events
        }
    }

    /**
     * Pass through the event and capture any errors.
     *
     * @param string $method
     * @param array  $arguments
     */
    public function __call($method, $arguments)
    {
        $handlerMethod = "{$method}Handler";

        if (!method_exists($this, $handlerMethod)) {
            throw new RuntimeException("Missing event handler: {$handlerMethod}");
        }

        try {
            call_user_func_array([$this, $handlerMethod], $arguments);
        } catch (Exception $exception) {
            // Ignore
        }
    }

    /**
     * Until Laravel 5.1
     *
     * @param Route $route
     */
    protected function routerMatchedHandler(Route $route)
    {
        $routeName = Integration::extractNameForRoute($route) ?? '<unlabeled transaction>';

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_NAVIGATION,
            'route',
            $routeName
        ));

        Integration::setTransaction($routeName);
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Routing\Events\RouteMatched $match
     */
    protected function routeMatchedHandler(RouteMatched $match)
    {
        $this->routerMatchedHandler($match->route);
    }

    /**
     * Until Laravel 5.1
     *
     * @param string $query
     * @param array  $bindings
     * @param int    $time
     * @param string $connectionName
     */
    protected function queryHandler($query, $bindings, $time, $connectionName)
    {
        if (!$this->recordSqlQueries) {
            return;
        }

        $this->addQueryBreadcrumb($query, $bindings, $time, $connectionName);
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Database\Events\QueryExecuted $query
     */
    protected function queryExecutedHandler(QueryExecuted $query)
    {
        if (!$this->recordSqlQueries) {
            return;
        }

        $this->addQueryBreadcrumb($query->sql, $query->bindings, $query->time, $query->connectionName);
    }

    /**
     * Helper to add an query breadcrumb.
     *
     * @param string     $query
     * @param array      $bindings
     * @param float|null $time
     * @param string     $connectionName
     */
    private function addQueryBreadcrumb($query, $bindings, $time, $connectionName)
    {
        $data = ['connectionName' => $connectionName];

        if ($time !== null) {
            $data['executionTimeMs'] = $time;
        }

        if ($this->recordSqlBindings) {
            $data['bindings'] = $bindings;
        }

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'sql.query',
            $query,
            $data
        ));
    }

    /**
     * Until Laravel 5.3
     *
     * @param string     $level
     * @param string     $message
     * @param array|null $context
     */
    protected function logHandler($level, $message, $context)
    {
        $this->addLogBreadcrumb($level, $message, is_array($context) ? $context : []);
    }

    /**
     * Since Laravel 5.4
     *
     * @param \Illuminate\Log\Events\MessageLogged $logEntry
     */
    protected function messageLoggedHandler(MessageLogged $logEntry)
    {
        $this->addLogBreadcrumb($logEntry->level, $logEntry->message, $logEntry->context);
    }

    /**
     * Helper to add an log breadcrumb.
     *
     * @param string      $level   Log level. May be any standard.
     * @param string|null $message Log message.
     * @param array       $context Log context.
     */
    private function addLogBreadcrumb(string $level, ?string $message, array $context = []): void
    {
        if (!$this->recordLaravelLogs) {
            return;
        }

        // A log message with `null` as value will not be recorded by Laravel
        // however empty strings are logged so we mimick that behaviour to
        // check for `null` to stay consistent with how Laravel logs it
        if ($message === null) {
            return;
        }

        Integration::addBreadcrumb(new Breadcrumb(
            $this->logLevelToBreadcrumbLevel($level),
            Breadcrumb::TYPE_DEFAULT,
            'log.' . $level,
            $message,
            $context
        ));
    }

    /**
     * Translates common log levels to Sentry breadcrumb levels.
     *
     * @param string $level Log level. Maybe any standard.
     *
     * @return string Breadcrumb level.
     */
    protected function logLevelToBreadcrumbLevel(string $level): string
    {
        switch (strtolower($level)) {
            case 'debug':
                return Breadcrumb::LEVEL_DEBUG;
            case 'warning':
                return Breadcrumb::LEVEL_WARNING;
            case 'error':
                return Breadcrumb::LEVEL_ERROR;
            case 'critical':
            case 'alert':
            case 'emergency':
                return Breadcrumb::LEVEL_FATAL;
            case 'info':
            case 'notice':
            default:
                return Breadcrumb::LEVEL_INFO;
        }
    }

    /**
     * Since Laravel 5.3
     *
     * @param \Illuminate\Auth\Events\Authenticated $event
     */
    protected function authenticatedHandler(Authenticated $event)
    {
        $userData = [
            'id' => $event->user->getAuthIdentifier(),
        ];

        try {
            /** @var \Illuminate\Http\Request $request */
            $request = $this->container->make('request');

            if ($request instanceof Request) {
                $ipAddress = $request->ip();

                if ($ipAddress !== null) {
                    $userData['ip_address'] = $ipAddress;
                }
            }
        } catch (BindingResolutionException $e) {
            // If there is no request bound we cannot get the IP address from it
        }

        Integration::configureScope(static function (Scope $scope) use ($userData): void {
            $scope->setUser($userData);
        });
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Queue\Events\JobProcessing $event
     */
    protected function queueJobProcessingHandler(JobProcessing $event)
    {
        $this->prepareScopeForQueuedJob();

        if (!$this->recordQueueInfo) {
            return;
        }

        $job = [
            'job' => $event->job->getName(),
            'queue' => $event->job->getQueue(),
            'attempts' => $event->job->attempts(),
            'connection' => $event->connectionName,
        ];

        // Resolve name exists only from Laravel 5.3+
        if (method_exists($event->job, 'resolveName')) {
            $job['resolved'] = $event->job->resolveName();
        }

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'queue.job',
            'Processing queue job',
            $job
        ));
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Queue\Events\JobExceptionOccurred $event
     */
    protected function queueJobExceptionOccurredHandler(JobExceptionOccurred $event)
    {
        $this->afterQueuedJob();
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Queue\Events\JobProcessed $event
     */
    protected function queueJobProcessedHandler(JobProcessed $event)
    {
        $this->afterQueuedJob();
    }

    /**
     * Since Laravel 5.2
     *
     * @param \Illuminate\Queue\Events\WorkerStopping $event
     */
    protected function queueWorkerStoppingHandler(WorkerStopping $event)
    {
        // Flush any and all events that were possibly generated by queue jobs
        Integration::flushEvents();
    }

    /**
     * Since Laravel 5.5
     *
     * @param \Illuminate\Console\Events\CommandStarting $event
     */
    protected function commandStartingHandler(CommandStarting $event)
    {
        if ($event->command) {
            Integration::configureScope(static function (Scope $scope) use ($event): void {
                $scope->setTag('command', $event->command);
            });

            if (!$this->recordCommandInfo) {
                return;
            }

            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'artisan.command',
                'Starting Artisan command: ' . $event->command,
                method_exists($event->input, '__toString') ? [
                    'input' => (string)$event->input,
                ] : []
            ));
        }
    }

    /**
     * Since Laravel 5.5
     *
     * @param \Illuminate\Console\Events\CommandFinished $event
     */
    protected function commandFinishedHandler(CommandFinished $event)
    {
        if ($this->recordCommandInfo) {
            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'artisan.command',
                'Finished Artisan command: ' . $event->command,
                array_merge([
                    'exit' => $event->exitCode,
                ], method_exists($event->input, '__toString') ? [
                    'input' => (string)$event->input,
                ] : [])
            ));
        }

        Integration::configureScope(static function (Scope $scope): void {
            $scope->setTag('command', '');
        });

        // Flush any and all events that were possibly generated by the command
        Integration::flushEvents();
    }

    private function afterQueuedJob(): void
    {
        // Flush any and all events that were possibly generated by queue jobs
        Integration::flushEvents();
    }

    private function prepareScopeForQueuedJob(): void
    {
        $this->cleanupScopeForQueuedJob();

        SentrySdk::getCurrentHub()->pushScope();

        $this->pushedQueueScope = true;

        // When a job starts, we want to make sure the scope is cleared of breadcrumbs
        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) {
            $scope->clearBreadcrumbs();
        });
    }

    private function cleanupScopeForQueuedJob(): void
    {
        if (!$this->pushedQueueScope) {
            return;
        }

        SentrySdk::getCurrentHub()->popScope();

        $this->pushedQueueScope = false;
    }

    /**
     * Octane Request Received
     *
     * @param \Laravel\Octane\Events\RequestReceived $event
     */
    protected function octaneRequestReceivedHandler(
        RequestReceived $event
    ) {
        $this->prepareScopeForOctane();

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'octane.request.received',
            'Octane Request Received',
            [] //Metadata
        ));
    }

    /**
     * @param \Laravel\Octane\Events\RequestTerminated $event
     */
    protected function octaneRequestTerminatedHandler(
        RequestTerminated $event
    ) {
        $this->octaneTerminated();
    }

    /**
     * @param \Laravel\Octane\Events\WorkerErrorOccurred $event
     */
    protected function octaneWorkerErrorOccurredHandler(WorkerErrorOccurred $event)
    {
        $this->octaneTerminated();
    }

    /**
     * @param \Laravel\Octane\Events\WorkerStopping $event
     */
    protected function octaneWorkerStoppingHandler(WorkerStopping $event)
    {
        // Flush any and all events that were possibly generated by octane workers
        Integration::flushEvents();
    }

    /**
     * @param \Laravel\Octane\Events\TaskReceived $event
     */
    protected function octaneTaskReceivedHandler(
        TaskReceived $event
    ) {
        $this->prepareScopeForOctane();

        if (!$this->recordOctaneTaskInfo) {
            return;
        }

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'octane.task.received',
            'Octane Task Received',
            [] //Metadata
        ));
    }

    /**
     * @param \Laravel\Octane\Events\TaskTerminated $event
     */
    protected function octaneTaskTerminatedHandler(
        TaskTerminated $event
    ) {
        $this->octaneTerminated();
    }

    /**
     * @param \Laravel\Octane\Events\TickReceived $event
     */
    protected function octaneTickReceivedHandler(
        TickReceived $event
    ) {
        $this->prepareScopeForOctane();

        if (!$this->recordOctaneTickInfo) {
            return;
        }

        Integration::addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'octane.tick.received',
            'Octane Tick Received',
            [] //Metadata
        ));
    }

    /**
     * @param \Laravel\Octane\Events\TickTerminated $event
     */
    protected function octaneTickTerminatedHandler(
        TickTerminated $event
    ) {
        $this->octaneTerminated();
    }

    private function octaneTerminated(): void
    {
        // Flush any and all events that were possibly generated by queue jobs
        Integration::flushEvents();
    }

    private function prepareScopeForOctane(): void
    {
        $this->cleanupScopeForOctane();

        SentrySdk::getCurrentHub()->pushScope();

        $this->pushedOctaneScope = true;

        // When a job starts, we want to make sure the scope is cleared of breadcrumbs
        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) {
            $scope->clearBreadcrumbs();
        });
    }

    private function cleanupScopeForOctane(): void
    {
        if (!$this->pushedOctaneScope) {
            return;
        }

        SentrySdk::getCurrentHub()->popScope();

        $this->pushedOctaneScope = false;
    }
}
