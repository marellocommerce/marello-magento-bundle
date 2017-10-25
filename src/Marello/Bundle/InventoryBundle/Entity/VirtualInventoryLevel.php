<?php

namespace Marello\Bundle\InventoryBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

use Oro\Bundle\EntityConfigBundle\Metadata\Annotation as Oro;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\OrganizationBundle\Entity\OrganizationAwareInterface;
use Oro\Bundle\OrganizationBundle\Entity\OrganizationInterface;

use Marello\Bundle\ProductBundle\Entity\Product;
use Marello\Bundle\SalesBundle\Entity\SalesChannelGroup;
use Marello\Bundle\CoreBundle\Model\EntityCreatedUpdatedAtTrait;

/**
 * @ORM\Entity()
 * @ORM\Table(name="marello_vrtl_inventory_level",
 *       uniqueConstraints={
 *          @ORM\UniqueConstraint(columns={"product_id", "channel_group_id"})
 *      }
 * )
 * @Oro\Config(
 *      defaultValues={
 *          "dataaudit"={
 *              "auditable"=true
 *          },
 *          "entity"={
 *              "icon"="fa-list-alt"
 *          },
 *          "security"={
 *              "type"="ACL",
 *              "group_name"=""
 *          },
 *          "ownership"={
 *              "owner_type"="ORGANIZATION",
 *              "owner_field_name"="organization",
 *              "owner_column_name"="organization_id"
 *          }
 *      }
 * )
 * @ORM\HasLifecycleCallbacks()
 */
class VirtualInventoryLevel implements OrganizationAwareInterface
{
    use EntityCreatedUpdatedAtTrait;

    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(name="id", type="integer")
     * @Oro\ConfigField(
     *      defaultValues={
     *          "importexport"={
     *              "excluded"=true
     *          }
     *      }
     * )
     *
     * @var int
     */
    protected $id;

    /**
     * @ORM\ManyToOne(targetEntity="Marello\Bundle\ProductBundle\Entity\Product")
     * @ORM\JoinColumn(name="product_id", referencedColumnName="id")
     * @Oro\ConfigField(
     *      defaultValues={
     *          "entity"={
     *              "label"="marello.product.entity_label"
     *          }
     *      }
     * )
     *
     * @var Product
     */
    protected $product;

    /**
     * @ORM\ManyToOne(targetEntity="Marello\Bundle\SalesBundle\Entity\SalesChannelGroup")
     * @ORM\JoinColumn(name="channel_group_id", referencedColumnName="id", nullable=false)
     * @Oro\ConfigField(
     *      defaultValues={
     *          "entity"={
     *              "label"="marello.sales.saleschannelgroup.entity_label"
     *          }
     *      }
     * )
     *
     * @var SalesChannelGroup
     */
    protected $salesChannelGroup;

    /**
     * @ORM\Column(name="inventory", type="integer")
     * @Oro\ConfigField(
     *      defaultValues={
     *          "entity"={
     *              "label"="marello.inventory.label"
     *          }
     *      }
     * )
     *
     * @var int
     */
    protected $inventory;

    /**
     * @var Organization
     *
     * @ORM\ManyToOne(targetEntity="Oro\Bundle\OrganizationBundle\Entity\Organization")
     * @ORM\JoinColumn(name="organization_id", referencedColumnName="id", onDelete="SET NULL")
     */
    protected $organization;

    /**
     * VirtualInventoryLevel constructor.
     * @param Product $product
     * @param SalesChannelGroup $group
     * @param null $inventory
     */
    public function __construct(Product $product, SalesChannelGroup $group, $inventory = null)
    {
        $this->product = $product;
        $this->salesChannelGroup = $group;
        $this->inventory = $inventory;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return Product
     */
    public function getProduct()
    {
        return $this->product;
    }

    /**
     * @param Product $product
     */
    public function setProduct($product)
    {
        $this->product = $product;
    }

    /**
     * @return SalesChannelGroup
     */
    public function getSalesChannelGroup()
    {
        return $this->salesChannelGroup;
    }

    /**
     * @param SalesChannelGroup $salesChannelGroup
     */
    public function setSalesChannelGroup(SalesChannelGroup $salesChannelGroup)
    {
        $this->salesChannelGroup = $salesChannelGroup;
    }

    /**
     * @return int
     */
    public function getInventory()
    {
        return $this->inventory;
    }

    /**
     * @param int $inventory
     */
    public function setInventory($inventory)
    {
        $this->inventory = $inventory;
    }

    /**
     * @return Organization
     */
    public function getOrganization()
    {
        return $this->organization;
    }

    /**
     * @param OrganizationInterface $organization
     */
    public function setOrganization(OrganizationInterface $organization)
    {
        $this->organization = $organization;
    }
}
