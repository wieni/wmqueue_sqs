<?php

namespace Drupal\wmqueue_sqs;

use Aws\Exception\AwsException;
use Aws\Sqs\SqsClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Class AwsSqsQueueFactory.
 */
class AwsSqsQueueFactory
{
    /**
     * Config factory service.
     *
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    protected $config;

    /**
     * Logger Service.
     *
     * @var \Drupal\Core\Logger\LoggerChannelInterface
     */
    protected $logger;

    /**
     * Serializer service.
     *
     * @var \Symfony\Component\Serializer\Serializer
     */
    protected $serializer;

    /**
     * Constructs a AwsSqsQueue object.
     *
     * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
     *   Config service.
     * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
     *   Logger service.
     * @param \Symfony\Component\Serializer\Serializer $serializer
     *   Serializer service.
     */
    public function __construct(
        ConfigFactoryInterface $config_factory,
        LoggerChannelFactoryInterface $logger_factory,
        Serializer $serializer
    ) {
        $this->config = $config_factory->get('wmqueue_sqs.settings');
        $this->logger = $logger_factory->get('wmqueue_sqs');
        $this->serializer = $serializer;
    }

    /**
     * Constructs a new queue object for a given name.
     *
     * @param string $name
     *   The name of the SQS queue to use.
     *
     * @return \Drupal\wmqueue_sqs\AwsSqsQueue
     *   Return AwsSqsQueue service.
     */
    public function get($name)
    {
        $client = new SqsClient([
            'credentials' => [
                'key' => $this->config->get('wmqueue_sqs_aws_key'),
                'secret' => $this->config->get('wmqueue_sqs_aws_secret'),
            ],
            'region' => $this->config->get('wmqueue_sqs_region'),
            'version' => $this->config->get('wmqueue_sqs_version'),
        ]);

        try {
            $queue = new AwsSqsQueue($name, $client, $this->logger, $this->config->get('wmqueue_sqs_prefix'));
            $queue->setSerializer($this->serializer);
            $queue->setClaimTimeout($this->config->get('wmqueue_sqs_claimtimeout'));
            $queue->setWaitTimeSeconds($this->config->get('wmqueue_sqs_waittimeseconds'));
        } catch (AwsException $exception) {
            if ($exception->getAwsErrorCode() == 'AWS.SimpleQueueService.QueueDeletedRecently') {
                // Wait for 60 sec to create queue again.
                // https://docs.aws.amazon.com/AWSSimpleQueueService/latest/APIReference/API_CreateQueue.html
                sleep(60);
                $queue = $this->get($name);
            } else {
                $this->logger->error($exception->getMessage());
            }
        }
        return $queue;
    }
}
