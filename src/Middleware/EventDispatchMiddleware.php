<?php

namespace EightPoints\Bundle\GuzzleBundle\Middleware;

use EightPoints\Bundle\GuzzleBundle\Events\PostTransactionEvent;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use EightPoints\Bundle\GuzzleBundle\Events\GuzzleEvents;
use EightPoints\Bundle\GuzzleBundle\Events\PreTransactionEvent;

/**
 * Dispatches an Event using the Symfony Event Dispatcher.
 * Dispatches a PRE_TRANSACTION event, before the transaction is sent
 * Dispatches a POST_TRANSACTION event, when the remote hosts responds.
 */
class EventDispatchMiddleware
{
    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface */
    private $eventDispatcher;

    /** @var string */
    private $serviceName;

    /**
     * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
     * @param string $serviceName
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, string $serviceName)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->serviceName = $serviceName;
    }

    /**
     * @return \Closure
     */
    public function dispatchEvent() : \Closure
    {
        return function (callable $handler) {

            return function (
                RequestInterface $request,
                array $options
            ) use ($handler) {
                // Create the Pre Transaction event.
                $preTransactionEvent = new PreTransactionEvent($request, $this->serviceName);

                // Dispatch it through the symfony Dispatcher.
                $this->eventDispatcher->dispatch($preTransactionEvent, GuzzleEvents::PRE_TRANSACTION);

                // Continue the handler chain.
                $promise = $handler($preTransactionEvent->getTransaction(), $options);

                // Handle the response form the server.
                return $promise->then(
                    function (ResponseInterface $response) {
                        // Create the Post Transaction event.
                        $postTransactionEvent = new PostTransactionEvent($response, $this->serviceName);

                        // Dispatch the event on the symfony event dispatcher.
                        $this->eventDispatcher->dispatch($postTransactionEvent, GuzzleEvents::POST_TRANSACTION);

                        // Continue down the chain.
                        return $postTransactionEvent->getTransaction();
                    },
                    function (Exception $reason) {
                        // Get the response. The response in a RequestException can be null too.
                        $response = $reason instanceof RequestException ? $reason->getResponse() : null;

                        // Create the Post Transaction event.
                        $postTransactionEvent = new PostTransactionEvent($response, $this->serviceName);

                        // Dispatch the event on the symfony event dispatcher.
                        $this->eventDispatcher->dispatch($postTransactionEvent, GuzzleEvents::POST_TRANSACTION);

                        // Continue down the chain.
                        return \GuzzleHttp\Promise\rejection_for($reason);
                    }
                );
            };
        };
    }
}
