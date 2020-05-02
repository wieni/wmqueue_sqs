<?php

namespace Drupal\aws_sqs\Queue;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Aws\Sqs\SqsClient;

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
   * Constructs a AwsSqsQueue object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Logger service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->config = $config_factory->get('aws_sqs.settings');
    $this->logger = $logger_factory->get('aws_sqs');
  }

  /**
   * Constructs a new queue object for a given name.
   *
   * @param string $name
   *   The name of the SQS queue to use.
   *
   * @return \Drupal\aws_sqs\Queue\AwsSqsQueue
   *   Return AwsSqsQueue service.
   */
  public function get($name) {
    $client = new SqsClient([
      'credentials' => [
        'key'    => $this->config->get('aws_sqs_aws_key'),
        'secret' => $this->config->get('aws_sqs_aws_secret'),
      ],
      'region' => $this->config->get('aws_sqs_region', 'us-east-1'),
      'version' => $this->config->get('aws_sqs_version', 'latest'),
    ]);

    $queue = new AwsSqsQueue($name, $client, $this->logger);
    $queue->setClaimTimeout($this->config->get('aws_sqs_claimtimeout'));
    $queue->setWaitTimeSeconds($this->config->get('aws_sqs_waittimeseconds'));

    return $queue;
  }

}
