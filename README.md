## INTRODUCTION
AWS simple queue services defines a Queue Interface for Amazon SQS

## REQUIREMENTS
Set up your Amazon account and sign up for SQS.

        Instructions here:
        http://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSGettingStartedGuide/GettingSetUp.html

        - Create an Amazon account.
        - Creating a group, user, and granting that user access to SQS.
        - Get set up to submit requests to AWS SQS with PHP.
        
        You may also be interested in documentation on AWS SDK for PHP:
        http://docs.aws.amazon.com/aws-sdk-php-2/guide/latest/index.html

## INSTALLATION
Install module as usual.
https://www.drupal.org/docs/8/extending-drupal-8/installing-drupal-8-modules

## CONFIGURATION
Enter your AWS credentials.

        - Go here: /admin/config/system/aws-queue
        - Enter your creds

## EXAMPLE CODE
    $example_queue = Drupal::service("queue.awssqs")->get("example_queue");
    
    // Get some data
    $item = array('test', '1', '2', '3');
    
    // Add the data to the queue
    $example_queue->createItem($item);
    
    // Fetch the item from the queue
    $item = $example_queue->claimItem();

## REPLACE AWS SQS AS DEFAULT QUEUE FOR DRUPAL
The following values can be set in your settings.php file's 
$settings array to define which services are used for queues

 - queue_reliable_service_$name: 
    The container service to use for the reliable queue $name.
 - queue_service_$name: 
    The container service to use for the queue $name.
 - queue_default: 
    The container service to use by default for queues without overrides. 
    This defaults to 'queue.database'.
    
    Example :- 
    Add following code in settings.php.
    $settings['queue_default'] = 'aws_sqs.queue_factory'
