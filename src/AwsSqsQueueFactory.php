<?php

namespace Drupal\aws_sqs;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Aws\Sqs\SqsClient;
use Symfony\Component\Serializer\Serializer;

/**
 * Class AwsSqsQueueFactory.
 */
class AwsSqsQueueFactory {

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
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, Serializer $serializer) {
    $this->config = $config_factory->get('aws_sqs.settings');
    $this->logger = $logger_factory->get('aws_sqs');
    $this->serializer = $serializer;
  }

  /**
   * Constructs a new queue object for a given name.
   *
   * @param string $name
   *   The name of the SQS queue to use.
   *
   * @return \Drupal\aws_sqs\AwsSqsQueue
   *   Return AwsSqsQueue service.
   */
  public function get($name) {
    $client = new SqsClient([
      'credentials' => [
        'key'    => $this->config->get('aws_sqs_aws_key'),
        'secret' => $this->config->get('aws_sqs_aws_secret'),
      ],
      'region' => $this->config->get('aws_sqs_region'),
      'version' => $this->config->get('aws_sqs_version'),
    ]);

    $queue = new AwsSqsQueue($name, $client, $this->logger);
    $queue->setSerializer($this->serializer);
    $queue->setClaimTimeout($this->config->get('aws_sqs_claimtimeout'));
    $queue->setWaitTimeSeconds($this->config->get('aws_sqs_waittimeseconds'));

    return $queue;
  }

}
