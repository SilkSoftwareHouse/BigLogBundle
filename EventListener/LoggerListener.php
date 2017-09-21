<?php

namespace Silksh\BigLogBundle\EventListener;


use Symfony\Component\Cache\Adapter\AdapterInterface as CacheAdapter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LoggerListener implements EventSubscriberInterface
{
    private $ip;
    private $start;
    private $storage;
    private $uri;
    private $agent;

    private function makeKey()
    {
        $start = (int)$this->start;
        $rest = crc32($this->ip.$this->uri.rand());
        return sprintf("http_access_%08x_%08x", $start, $rest);
    }

    public function __construct()
    {
        $this->start = microtime(true);
    }

    public function setStorage(CacheAdapter $storage)
    {
        $this->storage = $storage;
    }

    public function requestStart(GetResponseEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }
        $request = $event->getRequest();
        $this->uri = $request->getUri();
        $this->ip = $request->getClientIp();
        $this->agent = $request->headers->get('User-Agent');
    }

    public function requestEnd(PostResponseEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }
        $toStore = [
            'start' => $this->start,
            'stop' => microtime(true),
            'uri' => $this->uri,
            'ip'  => $this->ip,
            'agent'  => $this->agent,
        ];
        $json = json_encode($toStore);
        $key = $this->makeKey();
        $item = $this->storage->getItem($key);
        $item->set($json);
        $status = $this->storage->save($item);
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => 'requestStart',
            KernelEvents::TERMINATE => 'requestEnd',
        ];
    }
}
