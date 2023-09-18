<?php

namespace diginov\sentrylogger\integrations;

use Craft;
use yii\base\Application;
use yii\base\Event;
use craft\web\View;
use Sentry\Tracing\Span;
use Sentry\Tracing\TransactionSource;
use function Sentry\addBreadcrumb;

class SentryTracer {

    private static $instance = null;

    private $requestSpan;
    /** @var array<Span> */
    private $spans = [];
    private $transaction;

    public static function getInstance()
    {
        if (self::$instance == null)
        {
            self::$instance = new static();
        }

        return self::$instance;
    }

    // The constructor is private
    // to prevent initiation with outer code.
    private function __construct()
    {
        $this->setup();
        $this->attachEventHandlers();
    }

    public function setup()
    {
        $request = Craft::$app->getRequest();

        // Setup context for the full transaction
        $transactionContext = new \Sentry\Tracing\TransactionContext();
        $transactionContext->setName('/'.$request->pathInfo);
        $transactionContext->setOp('http.server');

        $transactionContext->setSource(TransactionSource::url());

        // Start the transaction
        $this->transaction = \Sentry\startTransaction($transactionContext);

        // Set the current transaction as the current span so we can retrieve it later
        \Sentry\SentrySdk::getCurrentHub()->setSpan($this->transaction);
    }

    private function finish()
    {
        $this->requestSpan->finish();

        \Sentry\SentrySdk::getCurrentHub()->setSpan($this->transaction);

        // Finish the transaction, this submits the transaction and it's span to Sentry
        $this->transaction->finish();
    }

    private function attachEventHandlers(): void
    {
        Craft::$app->on(
            Application::EVENT_BEFORE_REQUEST,
            function(Event $e) {

                // Setup the context for the expensive operation span
                $spanContext = new \Sentry\Tracing\SpanContext();
                $spanContext->setOp('request');

                // Start the span
                $this->requestSpan = $this->transaction->startChild($spanContext);

                // Set the current span to the span we just started
                \Sentry\SentrySdk::getCurrentHub()->setSpan($this->requestSpan);
            }
        );

        Craft::$app->on(
            Application::EVENT_AFTER_REQUEST,
            fn(Event $e) => $this->finish()
        );

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            fn(Event $e) => $this->pushSpan('template.render.'.$e->template, '', $e)
        );

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_TEMPLATE,
            fn(Event $e) => $this->popSpan()
        );


        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            fn(Event $e) => $this->pushSpan('template.render-page.'.$e->template, '', $e)
        );

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            fn(Event $e) => $this->popSpan()
        );
    }

    private function pushSpan(string $operation, string $description, $e) {
        $parent = \Sentry\SentrySdk::getCurrentHub()->getSpan();

        $context = new \Sentry\Tracing\SpanContext();
        $context->setOp($operation);
        $context->setDescription($description);
        $span = $parent->startChild($context);

        // Set the current span to the span we just started
        \Sentry\SentrySdk::getCurrentHub()->setSpan($span);
        $this->spans[] = $span;
    }

    private function popSpan() {

        // \Sentry\SentrySdk::getCurrentHub()->getSpan()->finish();
        $span = array_pop($this->spans);
        $span->finish();

        if (empty($this->spans)) {
            \Sentry\SentrySdk::getCurrentHub()->setSpan($this->requestSpan);
            return;
        }

        \Sentry\SentrySdk::getCurrentHub()->setSpan(end($this->spans));
    }
}
