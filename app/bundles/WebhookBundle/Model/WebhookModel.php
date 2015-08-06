<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\WebhookBundle\Model;

use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\WebhookBundle\Entity\Log;
use Mautic\WebhookBundle\Entity\Webhook;
use Mautic\WebhookBundle\Entity\Event;
use Joomla\Http\Http;
use Joomla\Http\Response;
use Mautic\WebhookBundle\Entity\WebhookQueue;
use Mautic\WebhookBundle\Event as Events;
use Mautic\WebhookBundle\WebhookEvents;
use OpenCloud\Common\Exceptions\DatabaseCreateError;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Class ReportModel
 */
class WebhookModel extends FormModel
{
    /**
     * {@inheritdoc}
     *
     * @param       $entity
     * @param       $formFactory
     * @param null  $action
     * @param array $options
     *
     * @return mixed
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function createForm ($entity, $formFactory, $action = null, $params = array())
    {
        if (!$entity instanceof Webhook) {
            throw new MethodNotAllowedHttpException (array('Webhook'));
        }

        if (!empty($action)) {
            $params['action']  = $action;
        }

        $params['events'] = $this->getEvents();

        return $formFactory->create('webhook', $entity, $params);
    }

    /**
     * {@inheritdoc}
     */
    public function getEntity($id = null)
    {
        if ($id === null) {
            return new Webhook();
        }

        return parent::getEntity($id);
    }

    /**
     * {@inheritdoc}
     *
     * @return \Mautic\WebhookBundle\Entity\WebhookRepository
     */
    public function getRepository ()
    {
        return $this->em->getRepository('MauticWebhookBundle:Webhook');
    }

    /**
     * Gets array of custom events from bundles subscribed MauticWehbhookBundle::WEBHOOK_ON_BUILD
     *
     * @return mixed
     */
    public function getEvents ()
    {
        static $events;

        if (empty($events)) {
            //build them
            $events = array();
            $event  = new Events\WebhookBuilderEvent($this->translator);
            $this->dispatcher->dispatch(WebhookEvents::WEBHOOK_ON_BUILD, $event);
            $events = $event->getEvents();
        }

        return $events;
    }

    /*
     * Get a list of webhooks by matching events
     *
     * @param $types array of event type constant
     *
     * @return array
     */
    public function getWebhooksByEventTypes(array $types)
    {
        $results = $this->getRepository()->getEntitiesByEventTypes($types);
        return $results;
    }

    /*
     * Takes an array of webhooks and adds them to the webhook queue so they can be processed.abstract
     *
     * Optionally returns an array of all the queue IDs created so they can be immediately executed.
     *
     * @param $webhooks array
     * @param $returnQueueEntities bool
     *
     * @return
     */
    public function QueueWebhooks($webhooks, $payload, $immediatelyExecuteWebhooks = false)
    {
        if (! count($webhooks)) {
            return;
        }

        $queueList = array();

        /** @var \Mautic\WebhookBundle\Entity\Webhook $webhook */
        foreach ($webhooks as $webhook)
        {
            $queueEntity = $this->queueWebhook($webhook, $payload);
            $queueList[] = $queueEntity;

            // add the queuelist and save everything
            $webhook->addQueues($queueList);
            $this->saveEntity($webhook);

            // reset to empty array
            $queueList = array();
        }

        if ($immediatelyExecuteWebhooks) {
            $this->processWebhooks($webhooks);
        }

        return;
    }

    /*
     * Creates a WebhookQueue entity, sets the date and returns the created entity
     *
     * @param  $webhook Webhook
     * @param  $payload json_encoded array as the payload
     *
     * @return WebhookQueue
     */
    public function queueWebhook(Webhook $webhook, $payload)
    {
        $queue = new WebhookQueue();
        $queue->setWebhook($webhook);
        $queue->setDateAdded(new \DateTime);
        $queue->setPayload($payload);

        return $queue;
    }

    /*
     * Execute a list of webhooks to their specified endpoints
     */
    public function processWebhooks($webhooks)
    {
        $http = new Http();
        /** @var \Mautic\WebhookBundle\Entity\Webhook $webhook */
        foreach ($webhooks as $webhook)
        {
            $payload = ($this->getWebhookPayload($webhook));
            $response = $http->post($webhook->getWebhookUrl(), json_encode($payload));
            $this->addLog($webhook, $response);
        }
    }

    /*
     * Add a log for the webhook response and save it
     */
    public function addLog(Webhook $webhook, Response $response)
    {
        $log = new Log();

        $log->setWebhook($webhook);
        $log->setStatusCode($response->code);
        $log->setDateAdded(new \DateTime());
        $webhook->addLog($log);

        $this->saveEntity($webhook);
    }

    /*
     * Get Qeueue Repository
     */
    public function getQueueRepository()
    {
        return $this->em->getRepository('MauticWebhookBundle:WebhookQueue');
    }

    /*
     *
     */
    public function getWebhookPayload($webhook)
    {
        $queues = $this->getWebhookQueues($webhook);

        $payload = array();

        /** @var \Mautic\WebhookBundle\Entity\WebhookQueue $queue */
        foreach ($queues as $queue) {
            $payload[] = json_decode($queue->getPayload());
        }

        return $payload;
    }

    /*
     * Get the queues and order by date so we get events in chronological order
     *
     * @return array
     */
    public function getWebhookQueues(Webhook $webhook, $start = 0, $limit = 1000)
    {
        /** @var \Mautic\WebhookBundle\Entity\WebhookQueueRepository $queueRepo */
        $queueRepo = $this->getQueueRepository();

        $queues = $queueRepo->getEntities(
            array(
                'iterator_mode' => true,
                'start' => $start,
                'limit' => $limit,
                'orderBy' => 'e.dateAdded', // e is the default prefix unless you define getTableAlias in your repo class,
                'filter' => array(
                    'force' => array(
                        array(
                            'column' => 'IDENTITY(e.webhook)',
                            'expr'   => 'eq',
                            'value'  => $webhook->getId()
                        )
                    )
                )
	        )
        );

        $queueList = array();

        // hydrates the returned list of queues
        while (($q = $queues->next()) !== false) {
            $queueList =  $q;
        }

        return $queueList;
    }
}