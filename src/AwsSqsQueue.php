<?php

namespace Drupal\wmqueue_sqs;

use Aws\Sqs\SqsClient;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\ReliableQueueInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Serializer\Serializer;

/**
 * Amazon queue.
 */
class AwsSqsQueue implements ReliableQueueInterface
{
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
     * @var \Aws\Sqs\SqsClient
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
     * Queue prefix
     *
     * @var string
     */
    protected $prefix;

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
     * Serializer service.
     *
     * @var \Symfony\Component\Serializer\Serializer
     */
    protected $serializer;

    /**
     * AwsSqsQueue constructor.
     *
     * @param string $name
     *   Queue name.
     * @param \Aws\Sqs\SqsClient $client
     *   AwsClientInterface.
     * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
     *   Logger service.
     * @param string|null $prefix
     */
    public function __construct($name, SqsClient $client, LoggerChannelInterface $logger, ?string $prefix)
    {
        $this->name = $name;
        $this->client = $client;
        $this->logger = $logger;
        $this->prefix = $prefix ?: '';

        // Ensure the the queue exists and that we have a queue URL so that we
        // aren't checking for the queueUrl everywhere.
        $this->createQueue();
    }

    /**
     * Send an item to the AWS Queue.
     *
     * Careful, you can only store data up to 64kb.
     *
     * @param array|string|object $data
     *   Caller should be sending data in array.
     * @param bool $serialize
     *   (bool) Whether to serialize the data before sending, true by default.
     *
     * @return string|bool
     *   Return true is item created otherwise false.
     * @todo Add link to documentation here. I think this info is out of date.
     *    I believe now you can store more. But you get charged as if it's an
     *    additional request.
     *
     * Invokes SqsClient::sendMessage().
     *  http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_sendMessage
     *
     */
    public function createItem($data, $serialize = true)
    {
        // @todo Check if data size limit is 64kb (Validate, link to documentation).
        $result = $this->client->sendMessage([
            'QueueUrl' => $this->getQueueUrl(),
            'MessageBody' => $serialize ? $this->serialize($data) : $data,
        ]);

        return $result->get('MessageId') ?? false;
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
    public function numberOfItems()
    {
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
        } else {
            $return = 0;
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
     *   (optional) How long the processing is expected to take in seconds.
     *   0 by default.
     * @param bool $unserialize
     *   (bool) Whether to un-serialize the data before reading, true by default.
     *
     * @return bool|object
     *   On success we return an item object. If the queue is unable to claim an
     *   item it returns false.
     */
    public function claimItem($lease_time = 0, $unserialize = true)
    {
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

        // @todo Add error handling, in case service becomes unavailable.
        // Fetch the queue item.
        $response = $this->client->receiveMessage([
            'QueueUrl' => $this->queueUrl,
            'MaxNumberOfMessages' => 1,
            'VisibilityTimeout' => $claimTimeout,
            'WaitTimeSeconds' => $waitTimeSeconds,
        ]);

        // If the response does not contain 'Messages', return false.
        $messages = $response->get('Messages');
        if (!$messages) {
            return false;
        }

        $message = reset($messages);

        // If the item id is not set, return false.
        if (empty($message['MessageId'])) {
            return false;
        }

        // @todo Add error handling, in case service becomes unavailable.
        $item = new \stdClass();
        $item->data = $unserialize ? $this->unserialize($message['Body']) : $message['Body'];
        $item->reciept_handle = $message['ReceiptHandle'];
        $item->item_id = $message['MessageId'];
        if (!empty($item->reciept_handle)) {
            return $item;
        }
        return false;
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
    public function releaseItem($item)
    {
        $result = $this->client->changeMessageVisibility([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $item->reciept_handle,
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
    public function deleteItem($item)
    {
        if (!isset($item->item_id)) {
            throw new \Exception('An item that needs to be deleted requires a handle ID');
        }

        $this->client->deleteMessage([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $item->reciept_handle,
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
    public function createQueue()
    {
        if ($this->getQueueUrl()) {
            return;
        }
        $name = $this->name;
        if ($this->prefix) {
            $name = $this->prefix . '_' . $name;
        }

        // SQS has a limit of 80 chars
        $name = Unicode::truncate($name, 80);

        $result = $this->client->createQueue(['QueueName' => $name]);
        $queueUrl = $result->get('QueueUrl');
        $this->setQueueUrl($queueUrl);
    }

    /**
     * Deletes an SQS queue.
     *
     * Invokes SqsClient::deleteQueue().
     *  http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_deleteQueue.
     */
    public function deleteQueue()
    {
        $this->client->deleteQueue(['QueueUrl' => $this->queueUrl]);
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
    public function getClaimTimeout()
    {
        return $this->claimTimeout;
    }

    /**
     * Set claim timeout.
     *
     * @param string $timeout
     *   Timeout.
     */
    public function setClaimTimeout($timeout)
    {
        $this->claimTimeout = $timeout;
    }

    /**
     * Get sqs client.
     *
     * @return \Aws\Sqs\SqsClient
     *   AwsClientInterface client object.
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Set client.
     *
     * @param \Aws\Sqs\SqsClient $client
     *   Sqs client.
     */
    public function setClient(SqsClient $client)
    {
        $this->client = $client;
    }

    /**
     * Get queue name.
     *
     * @return string
     *   Return queue name.
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get wait time.
     *
     * @return string
     *   Return wait time of queue.
     */
    public function getWaitTimeSeconds()
    {
        return $this->waitTimeSeconds;
    }

    /**
     * Set wait time of queue.
     *
     * @param string $seconds
     *   Time in seconds.
     */
    public function setWaitTimeSeconds($seconds)
    {
        $this->waitTimeSeconds = $seconds;
    }

    /**
     * Set serializer.
     *
     * @param \Symfony\Component\Serializer\Serializer $serializer
     *   Serializer service.
     */
    public function setSerializer(Serializer $serializer)
    {
        $this->serializer = $serializer;
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
    protected static function isGuzzleServiceResourceModel($object)
    {
        return (is_object($object) && get_class($object) == 'Guzzle\Service\Resource\Model') ? true : false;
    }

    /**
     * Serialize data before sending to AWS SQS.
     *
     * @param array $data
     *   Data to serialize.
     * @param string $format
     *   Format to use for serialization of data.
     *
     * @return string
     *   Return serialized string.
     */
    protected function serialize(array $data, $format = 'json')
    {
        return $this->serializer->encode($data, $format);
    }

    /**
     * Unserialize data before sending to AWS SQS.
     *
     * @param string $data
     *   Data to un-serialize.
     * @param string $format
     *   Format in which data should be unserialized.
     *
     * @return mixed
     *   Return un-serialize data.
     */
    protected function unserialize($data, $format = 'json')
    {
        return $this->serializer->decode($data, $format);
    }

    /**
     * Get queue url.
     *
     * @return string
     *   Return queue url.
     */
    protected function getQueueUrl()
    {
        return $this->queueUrl;
    }

    /**
     * Set queue url.
     *
     * @param string $queueUrl
     *   Queue url.
     */
    protected function setQueueUrl($queueUrl)
    {
        $this->queueUrl = $queueUrl;
    }
}
