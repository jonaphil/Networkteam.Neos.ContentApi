<?php

namespace Networkteam\Neos\ContentApi\View;

use Neos\Flow\Annotations as Flow;
use GuzzleHttp\Psr7\Message;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\Domain\Model\RenderingMode;
use Neos\Neos\Domain\Repository\SiteRepository;
use Psr\Http\Message\StreamInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface as LegacyNodeInterface;
use Neos\ContentRepository\Domain\Projection\Content\TraversableNodeInterface;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Mvc\View\AbstractView;
use Neos\Flow\Security\Context;
use Neos\Fusion\Core\Runtime;
use Neos\Fusion\Exception\RuntimeException;
use Neos\Neos\Domain\Service\FusionService;
use Neos\Neos\Exception;
use Neos\Neos\View\FusionViewI18nTrait;
use Psr\Http\Message\ResponseInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Neos\Domain\Service\RenderingModeService;
use Neos\Fusion\Core\RuntimeFactory;
use Neos\Fusion\Core\FusionGlobals;

/**
 * A flexible Fusion view based on Neos FusionView (using the FusionService)
 */
class FusionView extends AbstractView
{
    use FusionViewI18nTrait;

    /**
     * @Flow\Inject
     * @var FusionService
     */
    protected $fusionService;

    /**
     * This contains the supported options, their default values, descriptions and types.
     *
     * @var array
     */
    protected $supportedOptions = [
        'extraContextVariables' => [[], 'Extra context variables to pass to the Fusion runtime', 'array'],
        // The following 2 are derived from Neos\Neos\View\FusionView
        'enableContentCache' => [
            null,
            'Flag to enable content caching inside Fusion (overriding the global setting).',
            'boolean'
        ],
        'renderingModeName' => [
            RenderingMode::FRONTEND,
            'Name of the user interface mode to use',
            'string'
        ]
    ];

    /**
     * The Fusion path to use for rendering the node given in "value", defaults to "page".
     *
     * @var string
     */
    protected $fusionPath = 'root';

    /**
     * @var Runtime
     */
    protected $fusionRuntime;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected RuntimeFactory $runtimeFactory;

    #[Flow\Inject]
    protected SiteRepository $siteRepository;

    #[Flow\Inject]
    protected RenderingModeService $renderingModeService;

    /**
     * Via {@see assign} request using the "request" key,
     * will be available also as Fusion global in the runtime.
     */
    protected ?ActionRequest $assignedActionRequest = null;

    /**
     * Renders the view
     *
     * @return StreamInterface|ResponseInterface The rendered view
     * @throws \Exception if no node is given
     * @throws \Throwable
     * @api
     */
    public function render(): StreamInterface|ResponseInterface
    {

        // TODO 9.0: use DimensionSpacePoint
//        $this->setFallbackRuleFromDimension($currentNode);

        $currentSiteNode = $this->getCurrentSiteNode();
        $fusionRuntime = $this->getFusionRuntime($currentSiteNode);

        $extraContextVariables = $this->variables['extraContextVariables'] ?? [];

        return $fusionRuntime->renderEntryPathWithContext(
            $this->getFusionPath(),
            array_merge($this->variables, $extraContextVariables)
        );
    }

    /**
     * Set the Fusion path to use for rendering the output
     *
     * @param string $fusionPath
     * @return void
     */
    public function setFusionPath($fusionPath)
    {
        $this->fusionPath = $fusionPath;
    }

    /**
     * Determines the Fusion path depending on the current controller and action
     *
     * @return string
     */
    protected function getFusionPath()
    {
        return $this->fusionPath;
    }

    /**
     * @return Node
     * @throws Exception
     */
    protected function getCurrentSiteNode(): Node
    {
        $currentNode = isset($this->variables['site']) ? $this->variables['site'] : null;
        if ($currentNode === null && $this->getCurrentNode() instanceof Node) {
            // TODO 9.0 use new CR to fetch SiteNode
            // fallback to Legacy node API
            /* @var $node LegacyNodeInterface */
            $node = $this->getCurrentNode();
            return $node->getContext()->getCurrentSiteNode();
        }
        if (!$currentNode instanceof Node) {
            throw new Exception('FusionView needs a variable \'site\' set with a Node object.', 1715164625);
        }
        return $currentNode;
    }

    /**
     * @return Node
     * @throws Exception
     */
    protected function getCurrentNode(): Node
    {
        $currentNode = isset($this->variables['node']) ? $this->variables['node'] : null;
        if (!$currentNode instanceof Node) {
            throw new Exception('FusionView needs a variable \'node\' set with a Node object.', 1715164626);
        }
        return $currentNode;
    }

    /**
     * @param Node $currentSiteNode
     * @return \Neos\Fusion\Core\Runtime
     */
    protected function getFusionRuntime(Node $currentSiteNode)
    {
        if ($this->fusionRuntime === null) {
            $site = $this->siteRepository->findSiteBySiteNode($currentSiteNode);
            $fusionConfiguration = $this->fusionService->createFusionConfigurationFromSite($site);

            $renderingMode = $this->renderingModeService->findByName($this->getOption('renderingModeName'));
            // TODO 9.0 rendering Mode?

            $fusionGlobals = FusionGlobals::fromArray(array_filter([
                'request' => $this->assignedActionRequest,
                'renderingMode' => $renderingMode
            ]));

            $this->fusionRuntime = $this->runtimeFactory->createFromConfiguration(
                $fusionConfiguration,
                $fusionGlobals
            );
            if (isset($this->options['enableContentCache']) && $this->options['enableContentCache'] !== null) {
                $this->fusionRuntime->setEnableContentCache($this->options['enableContentCache']);
            }
        }
        return $this->fusionRuntime;
    }

    /**
     * Clear the cached runtime instance on assignment of variables
     *
     * @param string $key
     * @param mixed $value
     */
    public function assign(string $key, mixed $value): self
    {
        if ($key === 'request') {
            // the request cannot be used as "normal" fusion variable and must be treated as FusionGlobal
            // to for example not cache it accidentally
            // additionally we need it for special request based handling in the view
            $this->assignedActionRequest = $value;
            return $this;
        }
        $this->fusionRuntime = null;
        return parent::assign($key, $value);
    }
}
