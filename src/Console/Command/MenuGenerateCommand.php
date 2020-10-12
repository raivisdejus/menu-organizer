<?php
/**
 * @category ScandiPWA
 * @package ScandiPWA\MenuOrganizer
 * @author Ivans Zuks <info@scandiweb.com>
 * @copyright Copyright (c) 2019 Scandiweb, Ltd (http://scandiweb.com)
 * Technodom_MenuOrganizer
 */
namespace ScandiPWA\MenuOrganizer\Console\Command;

use Exception;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use ScandiPWA\MenuOrganizer\Api\Data\MenuInterface;
use ScandiPWA\MenuOrganizer\Model\ItemFactory;
use ScandiPWA\MenuOrganizer\Model\Menu;
use ScandiPWA\MenuOrganizer\Model\MenuFactory;
use ScandiPWA\MenuOrganizer\Model\ResourceModel\Item as ItemResource;
use ScandiPWA\MenuOrganizer\Model\ResourceModel\Menu as MenuResource;
use ScandiPWA\MenuOrganizer\Model\ResourceModel\Menu\CollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class GenerateMenuCommand
 */
class MenuGenerateCommand extends Command
{
    const COMMAND = 'scandipwa:menu:generate';
    const COMMAND_DESCRIPTION = 'Generate menu items from categories';
    const COMMAND_CUT_CATEGORIES = 'cut';
    const COMMAND_MENU_NAME = 'name';
    const COMMAND_MENU_ID = 'id';

    protected $menuConfig = [
        MenuInterface::CSS_CLASS => 'Menu',
        MenuInterface::IS_ACTIVE => '1',
        'store_id' => [
            \Magento\Store\Model\Store::DEFAULT_STORE_ID
        ],
    ];
    protected $ignoredCategories = [];
    protected $ignoredCategoriesId = [];
    protected $itemIds = [];
    
    /**
     * @var MenuFactory
     */
    protected $menuFactory;

    /**
     * @var CollectionFactory
     */
    protected $menuCollectionFactory;
    
    /**
     * @var MenuResource
     */
    protected $menuResource;

    /**
     * @var CategoryCollectionFactory
     */
    protected $categoryCollectionFactory;

    /**
     * @var ItemFactory
     */
    protected $itemFactory;
    
    /**
     * @var ItemResource
     */
    protected $itemResource;

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName(self::COMMAND)
            ->setDescription(self::COMMAND_DESCRIPTION)
            ->addOption(
                self::COMMAND_MENU_NAME,
                null,
                InputOption::VALUE_OPTIONAL,
                __('Menu Name'),
                'Main Menu'
            )
            ->addOption(
                self::COMMAND_MENU_ID,
                null,
                InputOption::VALUE_OPTIONAL,
                __('Menu identifier'),
                'main-menu'
            )
            ->addOption(
                self::COMMAND_CUT_CATEGORIES,
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                __('Categories to not use in menu'),
                []
            );
        parent::configure();
    }

    /**
     * MenuGenerateCommand constructor.
     *
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param MenuFactory $menuFactory
     * @param MenuResource $menuResource
     * @param ItemFactory $itemFactory
     * @param ItemResource $itemResource
     * @param CollectionFactory $menuCollectionFactory
     * @param string|null $name
     */
    public function __construct(
        CategoryCollectionFactory $categoryCollectionFactory,
        MenuFactory $menuFactory,
        MenuResource $menuResource,
        ItemFactory $itemFactory,
        ItemResource $itemResource,
        CollectionFactory $menuCollectionFactory,
        string $name = null
    ) {
        parent::__construct($name);
        $this->menuFactory = $menuFactory;
        $this->menuResource = $menuResource;
        $this->menuCollectionFactory = $menuCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->itemFactory = $itemFactory;
        $this->itemResource = $itemResource;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @throws Exception
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->ignoredCategories = $input->getOption(self::COMMAND_CUT_CATEGORIES);
        $this->menuConfig[MenuInterface::TITLE] = $input->getOption(self::COMMAND_MENU_NAME);
        $this->menuConfig[MenuInterface::IDENTIFIER] = $input->getOption(self::COMMAND_MENU_ID);
        /** @var CategoryCollection $categoriesCollection */
        $categoriesCollection = $this->categoryCollectionFactory->create();
        $categoriesCollection->addAttributeToSelect('name');
        $categoriesCollection->addAttributeToSelect('url_path');
        $categoriesCollection->addAttributeToFilter('level', ['gt' => 1]);

        $menu = $this->createMenu();
        $this->removeAutogeneratedItems($menu);

        /** @var Category $category */
        foreach ($categoriesCollection as $category) {
            $this->createMenuItem($category, $menu);
        }

        $output->writeln('<info>Success</info>');
        
        return 0;
    }

    /**
     * Create new Menu
     *
     * @throws Exception
     * @return Menu
     */
    protected function createMenu()
    {
        $menu = $this->menuCollectionFactory->create()
            ->addFieldToFilter('identifier', $this->menuConfig['identifier'])
            ->getFirstItem();
        
        if ($menu->getId()) {
            $this->menuResource->load($menu, $menu->getId());
        }
        
        $menu->addData($this->menuConfig);
        $this->menuResource->save($menu);
        
        return $menu;
    }

    /**
     * Delete autogenerated menu items before creating new
     *
     * @param Menu $menu
     */
    protected function removeAutogeneratedItems(Menu $menu)
    {
        /** @var AdapterInterface $connection */
        $connection = $this->itemResource->getConnection();
        $connection->delete(
            $this->itemResource->getMainTable(),
            ['menu_id = ?' => $menu->getId()]
        );
    }

    /**
     * Get menu item parent category id
     *
     * @param Category $category
     * @return mixed
     */
    protected function getParentId(Category $category)
    {
        if (!isset($this->itemIds[$category->getParentId()])) {
            return '0';
        }

        return $this->itemIds[$category->getParentId()];
    }

    /**
     * Create menu item
     *
     * @param Category $category
     * @param Menu $menu
     * @throws Exception
     */
    protected function createMenuItem(Category $category, Menu $menu)
    {
        if (!$this->isCategoryAvailable($category)) {
            return;
        }

        $data = [
            'menu_id' => $menu->getId(),
            'title' => $category->getName(),
            'url_type' => '0',
            'url' => '/' . $category->getUrlPath(),
            'category_id' => $category->getId(),
            'parent_id' => $this->getParentId($category),
            'is_active' => '1'
        ];
        $item = $this->itemFactory->create();
        $item->addData($data);
        $this->itemResource->save($item);
        $this->itemIds[$category->getId()] = $item->getId();
    }

    /**
     * Check isn't category cut
     *
     * @param Category $category
     * @return bool
     */
    protected function isCategoryAvailable(Category $category)
    {
        if ($category->getLevel() > 4) {
            return false;
        }

        if (
            in_array($category->getName(), $this->ignoredCategories) || 
            in_array($category->getParentId(), $this->ignoredCategoriesId)
        ) {
            $this->ignoredCategoriesId[] = $category->getId();
            return false;
        }

        return true;
    }
}
