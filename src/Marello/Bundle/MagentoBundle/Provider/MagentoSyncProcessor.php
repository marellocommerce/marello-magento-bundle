<?php

namespace Marello\Bundle\MagentoBundle\Provider;

use Oro\Bundle\IntegrationBundle\Provider\AbstractSyncProcessor;
use Oro\Bundle\IntegrationBundle\Provider\ConnectorInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use Doctrine\Common\Persistence\ManagerRegistry;

use Oro\Bundle\IntegrationBundle\Entity\Channel as Integration;
use Oro\Bundle\ImportExportBundle\Processor\ProcessorRegistry;
use Oro\Bundle\IntegrationBundle\ImportExport\Job\Executor;
use Oro\Bundle\IntegrationBundle\Logger\LoggerStrategy;
use Oro\Bundle\IntegrationBundle\Manager\TypesRegistry;
use Oro\Bundle\IntegrationBundle\Provider\SyncProcessor;
use Oro\Bundle\IntegrationBundle\Entity\Repository\ChannelRepository;
use Oro\Bundle\IntegrationBundle\Entity\Status;
use Oro\Bundle\IntegrationBundle\Event\SyncEvent;
use Oro\Bundle\ImportExportBundle\Context\ContextInterface;

use Marello\Bundle\MagentoBundle\Entity\MagentoTransport;
use Marello\Bundle\MagentoBundle\Provider\Connector\TwoWaySyncConnectorInterface;

class MagentoSyncProcessor extends SyncProcessor
{
    const SYNCED_TO = 'initialSyncedTo';
    const SKIP_STATUS = 'skip';
    const INTERVAL = 'initialSyncInterval';
    const INCREMENTAL_INTERVAL = 'incrementalInterval';
    const START_SYNC_DATE = 'start_sync_date';

    /** @var array|null */
    protected $bundleConfiguration;

    /** @var string */
    protected $channelClassName;

    /**
     * @param ManagerRegistry $doctrineRegistry
     * @param ProcessorRegistry $processorRegistry
     * @param Executor $jobExecutor
     * @param TypesRegistry $registry
     * @param EventDispatcherInterface $eventDispatcher
     * @param LoggerStrategy $logger
     * @param array $bundleConfiguration
     */
    public function __construct(
        ManagerRegistry $doctrineRegistry,
        ProcessorRegistry $processorRegistry,
        Executor $jobExecutor,
        TypesRegistry $registry,
        EventDispatcherInterface $eventDispatcher,
        LoggerStrategy $logger = null,
        array $bundleConfiguration = null
    ) {
        parent::__construct(
            $doctrineRegistry,
            $processorRegistry,
            $jobExecutor,
            $registry,
            $eventDispatcher,
            $logger
        );

        $this->bundleConfiguration = $bundleConfiguration;
    }

    /**
     * @param string $channelClassName
     */
    public function setChannelClassName($channelClassName)
    {
        $this->channelClassName = $channelClassName;
    }

    /**
     * @return \DateInterval
     */
    protected function getSyncInterval()
    {
        if (empty($this->bundleConfiguration['sync_settings']['import_step_interval'])) {
            throw new \InvalidArgumentException('Option "import_step_interval" is missing');
        }

        $syncInterval = $this->bundleConfiguration['sync_settings']['import_step_interval'];
        $interval = \DateInterval::createFromDateString($syncInterval);

        return $interval;
    }

    /**
     * {@inheritdoc}
     */
    protected function processConnectors(Integration $integration, array $parameters = [], callable $callback = null)
    {
        // Pass interval to connectors for further filters creation
        $interval = $this->getSyncInterval();
        $parameters[self::INCREMENTAL_INTERVAL] = $interval;

        // Collect initial connectors
        $connectors = $this->getTypesOfConnectorsToProcess($integration, $callback);

        /** @var \DateTime[] $connectorsSyncedTo */
        $connectorsSyncedTo = [];
        foreach ($connectors as $connector) {
            $connectorsSyncedTo[$connector] = $this->getConnectorSyncedTo($integration, $connector);
        }

        $processedConnectorStatuses = [];
        $isSuccess = true;

        foreach ($connectors as $connector) {
            $this->logger->info(
                sprintf(
                    'Syncing connector %s starting %s interval %s',
                    $connector,
                    $connectorsSyncedTo[$connector]->format('Y-m-d H:i:s'),
                    $interval->format('%d days')
                )
            );

            try {
                $realConnector = $this->getRealConnector($integration, $connector);
                if (!$this->isConnectorAllowed($realConnector, $integration, $processedConnectorStatuses)) {
                    continue;
                }
                // Pass synced to for further filters creation
                $parameters = array_merge(
                    $parameters,
                    [self::SYNCED_TO => clone $connectorsSyncedTo[$connector]]
                );

                $status = $this->processIntegrationConnector(
                    $integration,
                    $realConnector,
                    $parameters
                );
                // Move sync date into future by interval value
                $connectorsSyncedTo[$connector] = $this->getIncrementalSyncedTo(
                    $connectorsSyncedTo[$connector],
                    $interval
                );
                $isSuccess = $isSuccess && $this->isIntegrationConnectorProcessSuccess($status);

                if ($isSuccess) {
                    // Save synced to date for connector
                    $syncedTo = $connectorsSyncedTo[$connector];
                    $this->updateSyncedTo($integration, $connector, $syncedTo);
                }
            } catch (\Exception $e) {
                $isSuccess = false;
                $this->logger->critical($e->getMessage(), ['exception' => $e]);
            }
        }

        return $isSuccess;
    }

    /**
     * @param $syncedTo
     * @param $interval
     * @return mixed
     */
    protected function getIncrementalSyncedTo($syncedTo, $interval)
    {
        $syncedTo->add($interval);
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        if ($syncedTo > $now) {
            return $now;
        }

        return $syncedTo;
    }

    /**
     * @param Integration $integration
     * @param string $connector
     * @return \DateTime
     */
    protected function getConnectorSyncedTo(Integration $integration, $connector)
    {
        $latestSyncedTo = $this->getSyncedTo($integration, $connector);
        if ($latestSyncedTo === false) {
            return clone $this->getInitialSyncStartDate($integration);
        }

        return clone $latestSyncedTo;
    }

    /**
     * @param Integration $integration
     * @return \DateTime
     */
    protected function getInitialSyncStartDate(Integration $integration)
    {
        if ($this->isInitialSyncStarted($integration)) {
            /** @var MagentoTransport $transport */
            $transport = $integration->getTransport();

            return $transport->getInitialSyncStartDate();
        } else {
            return new \DateTime('now', new \DateTimeZone('UTC'));
        }
    }

    /**
     * @param Integration $integration
     * @return bool
     */
    protected function isInitialSyncStarted(Integration $integration)
    {
        /** @var MagentoTransport $transport */
        $transport = $integration->getTransport();

        return (bool)$transport->getInitialSyncStartDate();
    }

    /**
     * @param Integration $integration
     * @param string $connector
     * @return bool|\DateTime
     */
    protected function getSyncedTo(Integration $integration, $connector)
    {
        $lastStatus = $this->getLastStatusForConnector($integration, $connector, Status::STATUS_COMPLETED);
        if ($lastStatus) {
            $statusData = $lastStatus->getData();
            if (!empty($statusData[static::SYNCED_TO])) {
                return \DateTime::createFromFormat(
                    \DateTime::ISO8601,
                    $statusData[static::SYNCED_TO],
                    new \DateTimeZone('UTC')
                );
            }
        }

        return false;
    }

    /**
     * @param Integration $integration
     * @param string $connector
     * @param int|null $code
     * @return null|Status
     */
    protected function getLastStatusForConnector(Integration $integration, $connector, $code = null)
    {
        $status = $this->getChannelRepository()->getLastStatusForConnector($integration, $connector, $code);
        if ($status) {
            $statusData = $status->getData();
            if (!empty($statusData[self::SKIP_STATUS])) {
                return null;
            }
        }

        return $status;
    }

    /**
     * @return ChannelRepository
     */
    protected function getChannelRepository()
    {
        if (!$this->channelClassName) {
            throw new \InvalidArgumentException('Channel class option is missing');
        }

        return $this->doctrineRegistry->getRepository($this->channelClassName);
    }

    /**
     * @param Integration $integration
     * @param string $connector
     * @param \DateTime $syncedTo
     */
    protected function updateSyncedTo(Integration $integration, $connector, \DateTime $syncedTo)
    {
        $formattedSyncedTo = $syncedTo->format(\DateTime::ISO8601);

        $lastStatus = $this->getLastStatusForConnector($integration, $connector, Status::STATUS_COMPLETED);
        $statusData = $lastStatus->getData();
        $statusData[self::SYNCED_TO] = $formattedSyncedTo;
        $lastStatus->setData($statusData);

        $this->addConnectorStatusAndFlush($integration, $lastStatus);
    }


    /**
     * Process integration connector
     *
     * @param Integration        $integration Integration object
     * @param ConnectorInterface $connector   Connector object
     * @param array              $parameters  Connector additional parameters
     *
     * @return Status
     */
    protected function processIntegrationConnector(
        Integration $integration,
        ConnectorInterface $connector,
        array $parameters = []
    ) {
        $importResult = parent::processIntegrationConnector($integration, $connector, $parameters);

        if (!$integration->getSynchronizationSettings()->offsetGetOr('isTwoWaySyncEnabled', false)
            || !$connector instanceof TwoWaySyncConnectorInterface) {
            $this->logger->debug(sprintf('None 2 way sync "%s" connector', $connector->getType()));
            return $importResult;
        }

        try {
            $this->logger->notice(sprintf('Start (export) processing "%s" connector', $connector->getType()));

            $entityName = $connector instanceof TwoWaySyncConnectorInterface
                ? $connector->getExportEntityFQCN() : $connector->getImportEntityFQCN();

            $processorAliases = $this->processorRegistry->getProcessorAliasesByEntity(
                ProcessorRegistry::TYPE_EXPORT,
                $entityName
            );
        } catch (\Exception $exception) {
            // log and continue
            $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            $status = $this->createConnectorStatus($connector)
                ->setCode(Status::STATUS_FAILED)
                ->setMessage($exception->getMessage());
            $this->addConnectorStatusAndFlush($integration, $status);

            return $status;
        }

        $configuration = [
            ProcessorRegistry::TYPE_EXPORT =>
                array_merge(
                    [
                        'processorAlias' => reset($processorAliases),
                        'entityName'     => $entityName,
                        'channel'        => $integration->getId(),
                        'channelType'    => $integration->getType(),
                    ],
                    $parameters
                ),
        ];

        $this->processExport($integration, $connector, $configuration);

        return $importResult;
    }

    /**
     * @param Integration        $integration
     * @param ConnectorInterface $connector
     * @param array              $configuration
     *
     * @return Status
     */
    private function processExport(Integration $integration, ConnectorInterface $connector, array $configuration)
    {
        $exportJobName = $connector->getExportJobName();

        $syncBeforeEvent = $this->dispatchSyncEvent(SyncEvent::SYNC_BEFORE, $exportJobName, $configuration);

        $configuration = $syncBeforeEvent->getConfiguration();
        $jobResult = $this->jobExecutor->executeJob(ProcessorRegistry::TYPE_EXPORT, $exportJobName, $configuration);

        $this->dispatchSyncEvent(SyncEvent::SYNC_AFTER, $exportJobName, $configuration, $jobResult);

        $context = $jobResult->getContext();
        $connectorData = $errors = [];
        if ($context) {
            $connectorData = $context->getValue(ConnectorInterface::CONTEXT_CONNECTOR_DATA_KEY);
            $errors = $context->getErrors();
        }
        $exceptions = $jobResult->getFailureExceptions();
        $isSuccess = $jobResult->isSuccessful() && empty($exceptions);

        $status = $this->createConnectorStatus($connector);
        $status->setData((array)$connectorData);

        $message = $this->formatExportResultMessage($context);
        $this->logger->info($message);

        if ($isSuccess) {
            if ($errors) {
                $warningsText = 'Some entities were skipped due to warnings:' . PHP_EOL;
                $warningsText .= implode($errors, PHP_EOL);

                $message .= PHP_EOL . $warningsText;
            }

            $status->setCode(Status::STATUS_COMPLETED)->setMessage($message);
        } else {
            $this->logger->error('Errors were occurred:');
            $exceptions = implode(PHP_EOL, $exceptions);

            $this->logger->error($exceptions);
            $status->setCode(Status::STATUS_FAILED)->setMessage($exceptions);
        }

        $this->addConnectorStatusAndFlush($integration, $status);

        return $status;
    }

    /**
     * {@inheritdoc}
     */
    protected function formatExportResultMessage(ContextInterface $context = null)
    {
        return sprintf(
            '[%s] %s',
            strtoupper(ProcessorRegistry::TYPE_EXPORT),
            AbstractSyncProcessor::formatResultMessage($context)
        );
    }
}
