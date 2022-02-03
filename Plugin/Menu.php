<?php

// @todo Review core logic in vendor/magento/module-catalog/Plugin/Block/Topmenu.php to see if there are any improvemnts which can be made

namespace Vseager\SnowdogMenu\Plugin;

use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\DataObject;
use Magento\Framework\Data\Tree;
use Magento\Framework\Data\Tree\Node;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Block\Html\Topmenu;
use Snowdog\Menu\Api\MenuRepositoryInterface;
use Snowdog\Menu\Api\NodeRepositoryInterface;
use Snowdog\Menu\Model\NodeTypeProvider;

/**
 * Plugin for top menu block
 */
class Menu
{
    /**
     * @var CategoryFactory
     */
    private $_categoryFactory; 

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var MenuRepositoryInterface
     */
    private $menuRepository;

    /**
     * @var NodeRepositoryInterface
     */
    private $nodeRepository;

    /**
     * @var NodeTypeProvider
     */
    private $nodeTypeProvider;

    private $menu = null;

    private $levelCount = 0;

    /**
     * @var \Snowdog\Menu\Model\Menu\Node
     */
    private $nodes;

    /**
     * Initialize dependencies.
     *
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\UrlInterface $urlBuilder
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        CategoryFactory $categoryFactory,
		ProductRepository $productRepository,
        MenuRepositoryInterface $menuRepository,
        NodeRepositoryInterface $nodeRepository,
        NodeTypeProvider $nodeTypeProvider
    ) {
        $this->_categoryFactory = $categoryFactory;
        $this->_productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->urlBuilder = $urlBuilder;
        $this->menuRepository = $menuRepository;
        $this->nodeRepository = $nodeRepository;
        $this->nodeTypeProvider = $nodeTypeProvider;

        $this->setNodeData();
    }

    public function beforeGetHtml(
        Topmenu $subject,
        $outermostClass = '',
        $childrenWrapClass = '',
        $limit = 0
    ) {
        $menu = $subject->getMenu();

        /**@var Tree  */ 
        $tree = $menu->getTree();

        //loop top level nodes
        foreach($this->getNodes(0) as $node) {
            $menuNode = new Node(
                $this->getNodeAsArray($node),
                'id',
                $tree
            );
            $menu->addChild($menuNode);

            $this->addChildNodes($menuNode, $node, $tree, 1);
        }
    }

    protected function addChildNodes($parentNode, $parent, $tree, $level = 1, $categoryChildren = false)
    {
        if ($level < $this->getLevelCount()) {
            // Check next levels for child nodes
            $nodes = $this->getNodes($level, (int)$parent->getNodeId());

            if ($nodes) {
                foreach($nodes as $child) {

                    $childNode = new Node(
                        $this->getNodeAsArray($child),
                        'id',
                        $tree,
                        $parentNode
                    );

                    $parentNode->addChild($childNode);
                    $this->addChildNodes($childNode, $child, $tree, $level + 1);
                }
                $level++;
            }

            // If category_child node, then loop all subcategories, otherwise loop child menu nodes
            if ($parent->getType() == 'category_child') {
                $children = $this->getCategoryChildren($parent->getContent());

                if (count($children)) {
                    $_level = $level;
                    foreach ($children as $child) {

                        $childObj = new DataObject([
                            'type' => 'category_child',
                            'title' => $child->getName(),
                            'content' => $child->getId(),
                            'node_id' => $_level . '-' . $child->getId(),
                            'classes' => ''
                        ]);
    
                        $childNode = new Node(
                            $this->getNodeAsArray($childObj),
                            'id',
                            $tree,
                            $parentNode
                        );
    
                        $parentNode->addChild($childNode);
                        $this->addChildNodes($childNode, $childObj, $tree, $_level + 1, true);
                    }
                    $_level++;
                }
            }
        }
    }

    protected function getNodeAsArray($node)
    {
        return [
            'name' => $node->getTitle(),
            'id' => $node->getNodeId(),
            'url' => $this->getUrl($node),
            'has_active' => false,
            'is_active' => false,
            'class' => $node->getClasses()
        ];
    }

    protected function getUrl($node)
    {
        if (is_object($node)) {
            switch ($node->getType())
            {
                case 'category':
                case 'category_child':
                    $category = $this->_categoryFactory->create()->load($node->getContent()); //@todo update this to cater for all node types
                    $url = $category->getUrl();
                    break;
                case 'product':
                    $product = $this->_productRepository->getById($node->getContent());
                    $url = $product->getProductUrl();
                    break;
                case 'cms_page':
                    $url = $node->getContent() !== 'home' ? $node->getContent() : ''; // @todo use home URL from config
                    break;
                case 'custom_url':
                    return $node->getContent();
                default:
                    $url = '';
            }
    
            return $this->urlBuilder->getUrl($url);
        }
    }

    /**
     * @return \Snowdog\Menu\Model\Menu|null
     */
    private function getMenu()
    {
        if ($this->menu === null) {
            $identifier = 'main'; // @todo set in config
            $storeId = $this->storeManager->getStore()->getId();
            $this->menu = $this->menuRepository->get($identifier, $storeId);

            if (empty($this->menu->getData())) {
                $this->menu = $this->menuRepository->get($identifier, Store::DEFAULT_STORE_ID);
            }
        }

        return $this->menu;
    }

    private function getNodes($level = 0, int $parentId = 0)
    {
        if (empty($this->nodes)) {
            $this->setNodeData();
        }
        if (!isset($this->nodes[$level])) {
            return null;
        }

        if (!isset($this->nodes[$level][$parentId])) {
            return null;
        }
        
        return $this->nodes[$level][$parentId];
    }

    private function setNodeData()
    {
        $nodes = $this->nodeRepository->getByMenu($this->getMenu()->getId());

        $result = [];
        $types = [];
        foreach ($nodes as $node) {
            if (!$node->getIsActive()) {
                continue;
            }

            $level = $node->getLevel();
            $parent = $node->getParentId() ?: 0;
            if (!isset($result[$level])) {
                $result[$level] = [];
            }
            if (!isset($result[$level][$parent])) {
                $result[$level][$parent] = [];
            }
            $result[$level][$parent][] = $node;
            $type = $node->getType();
            if (!isset($types[$type])) {
                $types[$type] = [];
            }
            $types[$type][] = $node;
        }
        $this->nodes = $result;

        foreach ($types as $type => $nodes) {
            $this->nodeTypeProvider->prepareData($type, $nodes);
        }
    }

    private function getLevelCount()
    {
        if (empty($this->levelCount)) {
            $this->levelCount = count((array)$this->nodes);
        }
        return $this->levelCount;
    }

    public function getCategoryChildren($categoryId)
    {
        $category = $this->_categoryFactory->create()->load($categoryId);
        return $category->getCategories($categoryId);
    }

}
