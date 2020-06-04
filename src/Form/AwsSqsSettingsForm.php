<?php

namespace Drupal\wmqueue_sqs\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\Core\Utility\LinkGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class AwsSqsSettingsForm.
 */
class AwsSqsSettingsForm extends ConfigFormBase
{
    /**
     * Config factory object.
     *
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    protected $configFactory;

    /**
     * State Interface.
     *
     * @var \Drupal\Core\State\StateInterface
     */
    protected $stateInterface;

    /**
     * Link generator service.
     *
     * @var \Drupal\Core\Utility\LinkGenerator
     */
    protected $linkGenerator;

    /**
     * Class construct.
     *
     * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
     *   Config factory service.
     * @param \Drupal\Core\State\StateInterface $stateInterface
     *   State interface service.
     * @param \Drupal\Core\Utility\LinkGenerator $linkGenerator
     *   Link generator service.
     */
    public function __construct(
        ConfigFactoryInterface $configFactory,
        StateInterface $stateInterface,
        LinkGenerator $linkGenerator
    ) {
        $this->configFactory = $configFactory;
        $this->stateInterface = $stateInterface;
        $this->linkGenerator = $linkGenerator;
        parent::__construct($configFactory);
    }

    /**
     * Factory method for dependency injection container.
     *
     * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
     *   Container.
     *
     * @return static
     *   Return static.
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('config.factory'),
            $container->get('state'),
            $container->get('link_generator')
        );
    }

    public function getFormId()
    {
        return 'wmqueue_sqs_settings';
    }

    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $default_queue = $this->stateInterface->get('queue_default');
        $config = $this->config('wmqueue_sqs.settings');

        $aws_credentials_url = Url::fromUri('http://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSGettingStartedGuide/AWSCredentials.html');
        $form['credentials'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('AWS credentials'),
            '#description' => $this->t('Follow the instructions to set up your AWS credentials @here.',
                [
                    '@here' => $this->linkGenerator->generate($this->t('here'), $aws_credentials_url),
                ]),
        ];
        $form['credentials']['wmqueue_sqs_aws_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Access Key ID'),
            '#default_value' => $config->get('wmqueue_sqs_aws_key'),
            '#required' => true,
            '#description' => $this->t('Amazon Web Services Key.'),
        ];
        $form['credentials']['wmqueue_sqs_aws_secret'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Secret Access Key'),
            '#default_value' => $config->get('wmqueue_sqs_aws_secret'),
            '#required' => true,
            '#description' => $this->t('Amazon Web Services Secret Key.'),
        ];
        $long_polling_url = Url::fromUri('http://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/sqs-long-polling.html#sqs-long-polling-query-api');
        $seconds = range(0, 20);
        $t_args = [
            '@more' => $this->linkGenerator->generate($this->t('Read more about long polling here.'),
                $long_polling_url),
        ];
        $form['wmqueue_sqs_waittimeseconds'] = [
            '#type' => 'select',
            '#title' => $this->t('Wait Time'),
            '#default_value' => $config->get('wmqueue_sqs_waittimeseconds'),
            '#options' => $seconds,
            '#description' => $this->t(
                'How long do you want to stay connected to AWS waiting for a response (seconds)? If a queue
        is empty, the connection will stay open for up to 20 seconds. If something arrives in the queue, it is
        returned as soon as it is received. AWS SQS charges per request. Long connections that stay open waiting for
        data to arrive are cheaper than polling SQS constantly to check for data. Long polling can also consume more
        resources on your server (think about the difference between running a task every minute that takes a second
        to complete versus running a task every minute that stays connected for up to 20 seconds every time waiting for
        jobs to come in). @more', $t_args),
        ];
        $visibility_timeout_url = Url::fromUri('http://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/AboutVT.html');
        $t_args = [
            '@more' => $this->linkGenerator->generate($this->t('Read more about visibility timeouts here.'),
                $visibility_timeout_url),
        ];
        $form['wmqueue_sqs_claimtimeout'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Claim Timeout / Visibility Timeout'),
            '#default_value' => $config->get('wmqueue_sqs_claimtimeout'),
            '#size' => 15,
            '#description' => $this->t(
                'When an item is claimed from the queue by a worker, how long should the item be hidden from
        other workers (seconds)? Note: If the item is not deleted before the end of this time, it will become visible
        to other workers and available to be claimed again. Note also: 12 hours (43,200 seconds) is the maximum amount
        of time for which an item can be claimed. @more', $t_args),
        ];

        $form['wmqueue_sqs_region'] = [
            '#type' => 'select',
            '#title' => $this->t('AWS Queue Region'),
            '#default_value' => $config->get('wmqueue_sqs_region'),
            '#options' => [
                'us-east-1' => $this->t('US East (N. Virginia)'),
                'us-east-2' => $this->t('US East (Ohio)'),
                'us-west-1' => $this->t('US West (N. California)'),
                'us-west-2' => $this->t('US West (Oregon)'),
                'ap-southeast-1' => $this->t('Asia Pacific (Singapore)'),
                'ap-northeast-1' => $this->t('Asia Pacific (Tokyo)'),
                'sa-east-1' => $this->t('South America (São Paulo)'),
                'af-south-1' => $this->t('Africa (Cape Town)'),
                'ap-east-1' => $this->t('Asia Pacific (Hong Kong)'),
                'ap-south-1' => $this->t('Asia Pacific (Mumbai)'),
                'ap-northeast-3' => $this->t('Asia Pacific (Osaka-Local)'),
                'ap-northeast-2' => $this->t('Asia Pacific (Seoul)'),
                'ap-southeast-2' => $this->t('Asia Pacific (Sydney)'),
                'ca-central-1' => $this->t('Canada (Central)'),
                'cn-north-1' => $this->t('China (Beijing)'),
                'cn-northwest-1' => $this->t('China (Ningxia)'),
                'eu-central-1' => $this->t('Europe (Frankfurt)'),
                'eu-west-1' => $this->t('Europe (Ireland)'),
                'eu-west-2' => $this->t('Europe (London)'),
                'eu-west-3' => $this->t('Europe (Paris)'),
                'eu-south-1' => $this->t('Europe (Milan)'),
                'eu-north-1' => $this->t('Europe (Stockholm)'),
                'me-south-1' => $this->t('Middle East (Bahrain)'),
                'us-gov-east-1' => $this->t('AWS GovCloud (US-East)'),
                'us-gov-west-1' => $this->t('AWS GovCloud (US)'),
            ],
            '#required' => true,
            '#description' => $this->t('AWS Region where to store the Queue. The list of AWS Regions can be found <a href="@aws_regions_list" target="_blank">here</a>',
                [
                    '@aws_regions_list' => 'https://docs.aws.amazon.com/general/latest/gr/sqs-service.html',
                ]),
        ];

        $form['version'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Version'),
            '#default_value' => $config->get('wmqueue_sqs_version'),
            '#required' => true,
            '#description' => $this->t("Amazon Web Services Version. 'latest' recommended"),
        ];

        if (!$default_queue) {
            $default_queue = 'queue.database';
        }

        $form['queue_default_class'] = [
            '#title' => $this->t('Default Queue'),
            '#markup' => $this
                ->t("The default queue class is <strong>@default_queue</strong>. Add <code>\$settings['queue_default'] = 'wmqueue_sqs.queue_factory'</code> in settings.php to replace AWS SQS as default queue for Drupal system.",
                    ['@default_queue' => $default_queue]),
        ];

        return parent::buildForm($form, $form_state);
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $config = $this->config('wmqueue_sqs.settings');
        $config->set('wmqueue_sqs_aws_key', $form_state->getValue('wmqueue_sqs_aws_key'))
            ->save();
        $config->set('wmqueue_sqs_aws_secret', $form_state->getValue('wmqueue_sqs_aws_secret'))
            ->save();
        $config->set('wmqueue_sqs_waittimeseconds', $form_state->getValue('wmqueue_sqs_waittimeseconds'))
            ->save();
        $config->set('wmqueue_sqs_claimtimeout', $form_state->getValue('wmqueue_sqs_claimtimeout'))
            ->save();
        $config->set('wmqueue_sqs_region', $form_state->getValue('wmqueue_sqs_region'))
            ->save();
        $config->set('wmqueue_sqs_version', $form_state->getValue('version'))
            ->save();

        $config->save();
        parent::submitForm($form, $form_state);
    }

    protected function getEditableConfigNames()
    {
        return ['wmqueue_sqs.settings'];
    }
}
