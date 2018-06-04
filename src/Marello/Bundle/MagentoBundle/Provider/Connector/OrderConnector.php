<?php

namespace Marello\Bundle\MagentoBundle\Provider\Connector;

class OrderConnector extends AbstractMagentoConnector
{
    const IMPORT_JOB_NAME = 'mage_order_import';
    const TYPE            = 'order';

    /**
     * {@inheritdoc}
     */
    public function getLabel()
    {
        return 'marello.magento.connector.order.label';
    }

    /**
     * {@inheritdoc}
     */
    public function getImportEntityFQCN()
    {
        return self::MARELLO_ORDER_TYPE;
    }

    /**
     * {@inheritdoc}
     */
    public function getImportJobName()
    {
        return self::IMPORT_JOB_NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function getType()
    {
        return self::TYPE;
    }

    /**
     * {@inheritdoc}
     */
    protected function getConnectorSource()
    {
        return $this->transport->getOrders();
    }

    /**
     * {@inheritdoc}
     */
    public function supportsForceSync()
    {
        return true;
    }
}
