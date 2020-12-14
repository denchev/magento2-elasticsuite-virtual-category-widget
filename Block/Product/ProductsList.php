<?php
namespace Htmlpet\VirtualCategoryWidget\Block\Product;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Block\Product\Widget\Html\Pager;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\CatalogWidget\Model\Rule;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Url\EncoderInterface;
use Magento\Framework\View\LayoutFactory;
use Magento\Framework\View\LayoutInterface;
use Magento\Rule\Model\Condition\Combine;
use Magento\Rule\Model\Condition\Sql\Builder as SqlBuilder;
use Magento\Widget\Block\BlockInterface;
use Magento\Widget\Helper\Conditions;

use Magento\CatalogWidget\Block\Product\ProductsList as BaseProductsList;
use Smile\ElasticsuiteVirtualCategory\Model\Category\Filter\Provider;
use Smile\ElasticsuiteCatalog\Model\ResourceModel\Product\Fulltext\CollectionFactory as ElasticsuiteCollectionFactory;

class ProductsList extends BaseProductsList
{
    /**
     * Default value for products count that will be shown
     */
    const DEFAULT_PRODUCTS_COUNT = 10;

    /**
     * Name of request parameter for page number value
     *
     * @deprecated @see $this->getData('page_var_name')
     */
    const PAGE_VAR_NAME = 'np';

    /**
     * Default value for products per page
     */
    const DEFAULT_PRODUCTS_PER_PAGE = 5;

    /**
     * Default value whether show pager or not
     */
    const DEFAULT_SHOW_PAGER = false;

    /**
     * Instance of pager block
     *
     * @var Pager
     */
    protected $pager;

    /**
     * @var HttpContext
     */
    protected $httpContext;

    /**
     * Catalog product visibility
     *
     * @var Visibility
     */
    protected $catalogProductVisibility;

    /**
     * Product collection factory
     *
     * @var CollectionFactory
     */
    protected $productCollectionFactory;

    /**
     * @var SqlBuilder
     */
    protected $sqlBuilder;

    /**
     * @var Rule
     */
    protected $rule;

    /**
     * @var Conditions
     */
    protected $conditionsHelper;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * Json Serializer Instance
     *
     * @var Json
     */
    private $json;

    /**
     * @var LayoutFactory
     */
    private $layoutFactory;

    /**
     * @var EncoderInterface|null
     */
    private $urlEncoder;

    /**
     * @var RendererList
     */
    private $rendererListBlock;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    private $filterProvider;

    private $elasticsearchCollectionFactory;

    /**
     * @param Context $context
     * @param CollectionFactory $productCollectionFactory
     * @param Visibility $catalogProductVisibility
     * @param HttpContext $httpContext
     * @param SqlBuilder $sqlBuilder
     * @param Rule $rule
     * @param Conditions $conditionsHelper
     * @param array $data
     * @param Json|null $json
     * @param LayoutFactory|null $layoutFactory
     * @param EncoderInterface|null $urlEncoder
     * @param CategoryRepositoryInterface|null $categoryRepository
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        CollectionFactory $productCollectionFactory,
        Visibility $catalogProductVisibility,
        HttpContext $httpContext,
        SqlBuilder $sqlBuilder,
        Rule $rule,
        Conditions $conditionsHelper,
        array $data = [],
        Json $json = null,
        LayoutFactory $layoutFactory = null,
        EncoderInterface $urlEncoder = null,
        CategoryRepositoryInterface $categoryRepository = null,
        Provider $filterProvider,
        ElasticsuiteCollectionFactory $elasticsuiteCollectionFactory
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->catalogProductVisibility = $catalogProductVisibility;
        $this->httpContext = $httpContext;
        $this->sqlBuilder = $sqlBuilder;
        $this->rule = $rule;
        $this->conditionsHelper = $conditionsHelper;
        $this->json = $json ?: ObjectManager::getInstance()->get(Json::class);
        $this->layoutFactory = $layoutFactory ?: ObjectManager::getInstance()->get(LayoutFactory::class);
        $this->urlEncoder = $urlEncoder ?: ObjectManager::getInstance()->get(EncoderInterface::class);
        $this->categoryRepository = $categoryRepository ?? ObjectManager::getInstance()
                ->get(CategoryRepositoryInterface::class);
        parent::__construct(
            $context,
            $productCollectionFactory,
            $catalogProductVisibility,
            $httpContext,
            $sqlBuilder,
            $rule,
            $conditionsHelper,
            $data,
            $json = null,
            $layoutFactory = null,
            $urlEncoder = null,
            $categoryRepository = null
        );

        $this->filterProvider = $filterProvider;
        $this->elasticsearchCollectionFactory = $elasticsuiteCollectionFactory;
    }
    
    protected function getConditions()
    {
        $conditions = $this->getData('conditions_encoded')
            ? $this->getData('conditions_encoded')
            : $this->getData('conditions');

        if ($conditions) {
            $conditions = $this->conditionsHelper->decode($conditions);
        }

        $replaceConditionsCandidates = [];

        foreach ($conditions as $key => $condition) {
            if (!empty($condition['attribute'])) {
                if ($condition['attribute'] == 'category_ids') {
                    // Check if category is a virtual category
                    if (array_key_exists('value', $condition)) {
                        $categoryId = $condition['value'];
                        $category = $this->categoryRepository->get($categoryId, $this->_storeManager->getStore()->getId());
                        if ($category->getIsVirtualCategory()) {

                            // All products that belongs to the category
                            $products = [];
                            
                            $filterQuery = $this->getFilterQuery($category);
                            $productCollection = $this->elasticsearchCollectionFactory->create([]);
                            $productCollection->addQueryFilter($filterQuery);

                            foreach($productCollection as $product) {
                                $products[] = $product->getSku();
                            }
                            
                            $replaceConditionsCandidates[$key] = [
                                'type' => $condition['type'],
                                'attribute' => 'sku',
                                'operator' => '()',
                                'value' => join(',', $products)
                            ];
                        }
                    }
                }
            }
        }

        if (!empty($replaceConditionsCandidates)) {
            $conditions = array_replace($conditions, $replaceConditionsCandidates);
        }

        foreach ($conditions as $key => $condition) {
            if (!empty($condition['attribute'])) {
                if (in_array($condition['attribute'], ['special_from_date', 'special_to_date'])) {
                    $conditions[$key]['value'] = date('Y-m-d H:i:s', strtotime($condition['value']));
                }

                if ($condition['attribute'] == 'category_ids') {
                    $conditions[$key] = $this->updateAnchorCategoryConditions($condition);
                }
            }
        }

        $this->rule->loadPost(['conditions' => $conditions]);
        return $this->rule->getConditions();
    }

    /**
     * Update conditions if the category is an anchor category
     *
     * @param array $condition
     * @return array
     */
    private function updateAnchorCategoryConditions(array $condition): array
    {
        if (array_key_exists('value', $condition)) {
            $categoryId = $condition['value'];

            try {
                $category = $this->categoryRepository->get($categoryId, $this->_storeManager->getStore()->getId());
            } catch (NoSuchEntityException $e) {
                return $condition;
            }

            $children = $category->getIsAnchor() ? $category->getChildren(true) : [];
            if ($children) {
                $children = explode(',', $children);
                $condition['operator'] = "()";
                $condition['value'] = array_merge([$categoryId], $children);
            }
        }

        return $condition;
    }

    /**
     * Current category filter query.
     *
     * @return \Smile\ElasticsuiteCore\Search\Request\QueryInterface
     */
    private function getFilterQuery($category)
    {
        return $this->filterProvider->getQueryFilter($category);
    }
}