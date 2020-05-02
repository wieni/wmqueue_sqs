<?php

namespace Drupal\aws_sqs\Queue;

use Aws\AwsClientInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\ReliableQueueInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Amazon queue.
 */
class AwsSqsQueue implements ReliableQueueInterface {

  use StringTranslationTrait;
  /**
   * The name of the queue this instance is working with.
   *
   * @var string
   */
  protected $claimTimeout;

  /**
   * SqsClient provided by AWS as interface to SQS.
   *
   * @var \Aws\AwsClientInterface
   */
  protected $client;

  /**
   * Queue name.
   *
   * @var string
   */
  protected $name;

  /**
   * Unique identifier for queue.
   *
   * @var string
   */
  protected $queueUrl;

  /**
   * Wait time for queue.
   *
   * @var string
   */
  protected $waitTimeSeconds;

  /**
   * Logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * AwsSqsQueue constructor.
   *
   * @param string $name
   *   Queue name.
   * @param \Aws\AwsClientInterface $client
   *   AwsClientInterface.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Logger service.
   */
  public function __construct($name, AwsClientInterface $client, LoggerChannelInterface $logger) {
    $this->name = $name;
    $this->client = $client;
    $this->logger = $logger;

    // Ensure the the queue exists and that we have a queue URL so that we
    // aren't checking for the queueUrl everywhere.
    $this->createQueue();
  }

  /**
   * Send an item to the AWS Queue.
   *
   * Careful, you can only store data up to 64kb.
   *
   *  @todo Add link to documentation here. I think this info is out of date.
   *    I believe now you can store more. But you get charged as if it's an
   *    additional request.
   *
   * Invokes SqsClient::sendMessage().
   *  http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_sendMessage
   *
   * @param string $data
   *   Caller should be sending serialized data. If an item retreived from the
   *   queueis being re-submitted to the queue (if is_object($item)
   *   && $item->data && item->item_id), only $item->data will be stored.
   *
   * @return bool
   *   Return true is item created otherwise false.
   */
  public function createItem($data) {

    // Check to see if someone is trying to save an item originally retrieved
    // from the queue. If so, this really should have been submitted as
    // $item->data, not $item. Reformat this so we don't save metadata or
    // confuse item_ids downstream.
    if (is_object($data) && property_exists($data, 'data') && property_exists($data, 'item_id')) {
      $text = $this->t('Do not re-queue whole items retrieved from the SQS queue. This included metadata, like the item_id. Pass $item->data to createItem() as a parameter, rather than passing the entire $item. $item->data is being saved. The rest is being ignored.');
      $data = $data->data;
      $this->logger->error($text);
    }

    // @todo Add a check here for message size? Log it?
    // Create a new message object.
    $result = $this->client->sendMessage([
      'QueueUrl'    => $this->queueUrl,
      'MessageBody' => $data,
    ]);

    return (bool) $result;
  }

  /**
   * Return the amount of items in the queue.
   *
   * Invokes SqsClient::getQueueAttributes().
   *  http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_getQueueAttributes.
   *
   * @return int
   *   Approximate Number of messages in the aws queue. Returns FALSE if SQS is
   *   not available.
   */
  public function numberOfItems() {
    // Request attributes of queue from AWS. The response is returned as a
    // Guzzle resource model object:
    // http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Guzzle.Service.Resource.Model.html
    $args = [
      'QueueUrl' => $this->queueUrl,
      'AttributeNames' => ['ApproximateNumberOfMessages'],
    ];
    $response = $this->client->getQueueAttributes($args);

    $attributes = $response->get('Attributes');
    if (!empty($attributes['ApproximateNumberOfMessages'])) {
      $return = $attributes['ApproximateNumberOfMessages'];
    }
    else {
      $return = FALSE;
    }

    return $return;
  }

  /**
   * Fetch a single item from the AWS SQS queue.
   *
   * Invokes SqsClient::receiveMessage().
   *  http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_receiveMessage
   *  http://docs.aws.amazon.com/aws-sdk-php-2/guide/latest/service-sqs.html#receiving-messages.
   *
   * @param int $lease_time
   *   Drupal's "lease time" is the same as AWS's "Visibility Timeout". It's the
   *   amount of time for which an item is being claimed. If a user passes in a
   *   value for $lease_time here, override the default claimTimeout.
   *
   * @return bool|object
   *   On success we return an item object. If the queue is unable to claim an
   *   item it returns false. This implies a best effort to retrieve an item
   *   and either the queue is empty or there is some other non-recoverable
   *   problem.
   */
  public function claimItem($lease_time = 0) {
    // This is important to support blocking calls to the queue system.
    $waitTimeSeconds = $this->getWaitTimeSeconds();
    $claimTimeout = ($lease_time) ? $lease_time : $this->getClaimTimeout();
    // If our given claimTimeout is smaller than the allowed waiting seconds
    // set the waitTimeSeconds to this value. This is to avoid a long call when
    // the worker that called claimItem only has a finite amount of time to wait
    // for an item
    // if $waitTimeSeconds is set to 0, it will never use the blocking
    // logic (which is intended)
    if ($claimTimeout < $waitTimeSeconds) {
      $waitTimeSeconds = $claimTimeout;
    }

    // Fetch the queue item.
    // @todo See usage of $lease_time. Should we use lease_time or other timeout below?
    // $message = $this->manager->receiveMessage($this->queue,$lease_time,true);
    // Retrieve item from AWS. See documentation about method and response here:
    $response = $this->client->receiveMessage([
      'QueueUrl' => $this->queueUrl,
      'MaxNumberOfMessages' => 1,
      'VisibilityTimeout' => $claimTimeout,
      'WaitTimeSeconds' => $waitTimeSeconds,
    ]);

    // @todo Add error handling, in case service becomes unavailable.
    $item = new \stdClass();
    $messageBody = $response->toArray()['Messages']['0'];
    $item->data = $messageBody['Body'];
    $item->item_id = $messageBody['ReceiptHandle'];
    if (!empty($item->item_id)) {
      return $item;
    }
    return FALSE;
  }

  /**
   * Release claim on item in the queue.
   *
   * In AWS lingo, you release a claim on an item in the queue by "terminating
   * its visibility timeout". (Similarly, you can extend the amount of time for
   * which an item is claimed by extending its visibility timeout. The maximum
   * visibility timeout for any item in any queue is 12 hours, including all
   * extensions.)
   *
   * Invokes SqsClient::ChangeMessageVisibility().
   *  http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_changeMessageVisibility
   *  http://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/AboutVT.html
   *
   * @param object $item
   *   Item retrieved from queue. This property is required: $item->item_id.
   *
   * @return bool
   *   TRUE for success.
   */
  public function releaseItem($item) {
    $result = $this->client->changeMessageVisibility([
      'QueueUrl' => $this->queueUrl,
      'ReceiptHandle' => $item->item_id,
      'VisibilityTimeout' => 0,
    ]);

    // If $result is the type of object we expect, everything went okay.
    // (Typically SqsClient would have thrown an error before here if anything
    // went wrong. This check is really just for good measure.)
    return self::isGuzzleServiceResourceModel($result);
  }

  /**
   * Deletes an item from the queue with deleteMessage method.
   *
   * Invokes SqsClient::deleteMessage().
   *  http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_deleteMessage.
   *
   * @param object $item
   *   The item to be deleted.
   *
   * @throws \Exception
   */
  public function deleteItem($item) {
    if (!isset($item->item_id)) {
      throw new \Exception("An item that needs to be deleted requires a handle ID");
    }

    $this->client->deleteMessage([
      'QueueUrl' => $this->queueUrl,
      'ReceiptHandle' => $item->item_id,
    ]);
  }

  /**
   * Create the Amazon Queue.
   *
   * Store queueUrl when queue is created. This is the queue's unique
   * identifier.
   *
   * Invokes SqsClient::createQueue().
   *  http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_createQueue
   */
  public function createQueue() {
    $result = $this->client->createQueue(['QueueName' => $this->name]);
    $queueUrl = $result->get('QueueUrl');
    $this->setQueueUrl($queueUrl);
  }

  /**
   * Deletes an SQS queue.
   *
   * Invokes SqsClient::deleteQueue().
   *  http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_deleteQueue.
   */
  public function deleteQueue() {
    $this->client->deleteQueue(['QueueUrl' => $this->queueUrl]);
  }

  /**
   * Check whether an object is an instance of Guzzle\Service\Resource\Model.
   *
   * @param object $object
   *   Object to test if Guzzle\Service\Resource\Model.
   *
   * @return bool
   *   Return true/false.
   */
  protected static function isGuzzleServiceResourceModel($object) {
    return (is_object($object) && get_class($object) == 'Guzzle\Service\Resource\Model') ? TRUE : FALSE;
  }

  /**
   * PHPs native serialize() isn't very portable.
   *
   * This extend this class and support other serialization formats.
   *
   * Something other than PHP can potentially process the data in the queue.
   *
   * As per discussion here: https://drupal.org/node/1956190).
   *
   * @param array $data
   *   Data to serialize.
   *
   * @return string
   *   Return serialized string.
   *
   * @todo: Depend on the Drupal serialization module for this.
   */
  protected static function serialize(array $data) {
    return serialize($data);
  }

  /**
   * PHPs native serialize() isn't very portable.
   *
   * This extend this class and support other serialization formats.
   *
   * Something other than PHP can potentially process the data in the queue.
   *
   * As per discussion here: https://drupal.org/node/1956190).
   *
   * @param string $data
   *   Data to un-serialize.
   *
   * @return mixed
   *   Return un-serialize data.
   *
   * @todo: Depend on the Drupal serialization module for this.
   */
  protected static function unserialize($data) {
    return unserialize($data);
  }

  /**
   * Getters and setters.
   */

  /**
   * Get claim timeout.
   *
   * @return string
   *   Return claim timeout.
   */
  public function getClaimTimeout() {
    return $this->claimTimeout;
  }

  /**
   * Set claim timeout.
   *
   * @param string $timeout
   *   Timeout.
   */
  public function setClaimTimeout($timeout) {
    $this->claimTimeout = $timeout;
  }

  /**
   * Get sqs client.
   *
   * @return \Aws\AwsClientInterface
   *   AwsClientInterface client object.
   */
  public function getClient() {
    return $this->client;
  }

  /**
   * Set client.
   *
   * @param \Aws\AwsClientInterface $client
   *   Sqs client.
   */
  public function setClient(AwsClientInterface $client) {
    $this->client = $client;
  }

  /**
   * Get queue name.
   *
   * @return string
   *   Return queue name.
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Get queue url.
   *
   * @return string
   *   Return queue url.
   */
  protected function getQueueUrl() {
    return $this->queueUrl;
  }

  /**
   * Set queue url.
   *
   * @param string $queueUrl
   *   Queue url.
   */
  protected function setQueueUrl($queueUrl) {
    $this->queueUrl = $queueUrl;
  }

  /**
   * Get wait time.
   *
   * @return string
   *   Return wait time of queue.
   */
  public function getWaitTimeSeconds() {
    return $this->waitTimeSeconds;
  }

  /**
   * Set wait time of queue.
   *
   * @param string $seconds
   *   Time in seconds.
   */
  public function setWaitTimeSeconds($seconds) {
    $this->waitTimeSeconds = $seconds;
  }

}
