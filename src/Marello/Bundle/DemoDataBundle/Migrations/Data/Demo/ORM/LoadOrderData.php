<?php

namespace Marello\Bundle\DemoDataBundle\Migrations\Data\Demo\ORM;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use League\Csv\Reader;
use Marello\Bundle\AddressBundle\Entity\Address;
use Marello\Bundle\OrderBundle\Entity\Order;
use Marello\Bundle\OrderBundle\Entity\OrderItem;
use Marello\Bundle\ProductBundle\Entity\Product;

class LoadOrderData extends AbstractFixture implements DependentFixtureInterface
{
    /** flush manager count */
    const FLUSH_MAX = 25;

    /** @var ObjectManager $manager */
    protected $manager;

    /**
     * {@inheritdoc}
     */
    public function getDependencies()
    {
        return [
            'Marello\Bundle\DemoDataBundle\Migrations\Data\Demo\ORM\LoadSalesData',
            'Marello\Bundle\DemoDataBundle\Migrations\Data\Demo\ORM\LoadProductData',
            'Marello\Bundle\DemoDataBundle\Migrations\Data\Demo\ORM\LoadProductPricingData',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function load(ObjectManager $manager)
    {
        $this->manager = $manager;

        $orderData = Reader::createFromPath(__DIR__ . '/dictionaries/order_data.csv');
        $orderData->setDelimiter(';');
        $orderDataHeader = $orderData->fetchOne();
        $orderData       = $orderData->setOffset(1)->fetchAll();

        $orderItemData = Reader::createFromPath(__DIR__ . '/dictionaries/order_items.csv');
        $orderItemData->setDelimiter(',');
        $orderItemHeader = $orderItemData->fetchOne();
        $orderItemData   = $orderItemData->setOffset(1)->fetch();

        /** @var Order $order */
        $order = null;

        $createdOrders = 0;

        foreach ($orderItemData as $itemRow) {
            $itemRow = array_combine($orderItemHeader, $itemRow);

            if ($order && ($itemRow['order_number'] !== $order->getOrderNumber())) {
                /*
                 * Compute Order totals.
                 */
                $total = $tax = $grandTotal = 0;
                $order->getItems()->map(function (OrderItem $item) use (&$total, &$tax, &$grandTotal) {
                    $total += ($item->getQuantity() * $item->getPrice());
                    $tax += $item->getTax();
                    $grandTotal += $item->getTotalPrice();
                });

                $order
                    ->setSubtotal($total)
                    ->setTotalTax($tax)
                    ->setGrandTotal($grandTotal);

                $manager->persist($order);
                $createdOrders++;
                $this->setReference('marello_order_' . $order->getOrderNumber(), $order);

                if (!$createdOrders % self::FLUSH_MAX) {
                    $manager->flush();
                }

                $order = null;
            }

            if (!$order) {
                $orderRow = array_combine($orderDataHeader, current($orderData));

                $order = $this->createOrder($orderRow);
                $order->setOrderNumber($itemRow['order_number']);
                next($orderData);
            }

            $item = $this->createOrderItem($itemRow);
            $order->addItem($item);
        }

        $manager->flush();
    }

    /**
     * @param array $row
     *
     * @return Order
     */
    protected function createOrder($row)
    {
        $billing = new Address();
        $billing->setNamePrefix($row['title']);
        $billing->setFirstName($row['firstname']);
        $billing->setLastName($row['lastname']);
        $billing->setStreet($row['street_address']);
        $billing->setPostalCode($row['zipcode']);
        $billing->setCity($row['city']);
        $billing->setCountry(
            $this->manager
                ->getRepository('OroAddressBundle:Country')->find($row['country'])
        );
        $billing->setRegion(
            $this->manager
                ->getRepository('OroAddressBundle:Region')
                ->findOneBy(['combinedCode' => $row['country'] . '-' . $row['state']])
        );
        $billing->setPhone($row['telephone_number']);
        $billing->setEmail($row['email']);

        $shipping = clone $billing;

        $orderEntity = new Order($billing, $shipping);

        $channel = $this->getReference('marello_sales_channel_' . $row['channel']);
        $orderEntity->setSalesChannel($channel);

        if ($row['order_ref'] !== 'NULL') {
            $orderEntity->setOrderReference($row['order_ref']);
        }

        return $orderEntity;
    }

    /**
     * @param array $row
     *
     * @return OrderItem
     */
    protected function createOrderItem($row)
    {
        /** @var Product $product */
        $product = $this->manager
            ->getRepository('MarelloProductBundle:Product')
            ->findOneBy(['sku' => $row['sku']]);

        $itemEntity = new OrderItem();
        $itemEntity->setProduct($product);
        $itemEntity->setQuantity($row['qty']);
        $itemEntity->setPrice($row['price']);
        $itemEntity->setTotalPrice($row['total_price']);
        $itemEntity->setTax($row['tax']);

        return $itemEntity;
    }
}
