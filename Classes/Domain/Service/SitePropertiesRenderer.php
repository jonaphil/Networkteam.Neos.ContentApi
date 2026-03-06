<?php
namespace Networkteam\Neos\ContentApi\Domain\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Rector\ContentRepository90\Legacy\LegacyContextStub;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Security\Exception;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Domain\Service\FusionService;

/**
 * @Flow\Scope("singleton")
 */
class SitePropertiesRenderer
{

    /**
     * @Flow\Inject
     * @var FusionService
     */
    protected $fusionService;

	/**
	 * @param Site $site
	 * @param ControllerContext $controllerContext
	 * @param string $workspaceName
	 * @param array $dimensionValues
	 * @return array
	 * @throws Exception
	 */
    public function renderSiteProperties(Site $site, ControllerContext $controllerContext, string $workspaceName = 'live', array $dimensionValues = []): array
    {
        /** @var LegacyContextStub $contentContext */
        $contentContext = new LegacyContextStub([
            'workspaceName' => $workspaceName,
            'currentSite' => $site,
			'dimensions' => $dimensionValues
        ]);
        // TODO 9.0 migration: !! ContentContext::getCurrentSiteNode() is removed in Neos 9.0. Use Subgraph and traverse up to "Neos.Neos:Site" node.

        $siteNode = $contentContext->getCurrentSiteNode();
        $runtime = $this->fusionService->createRuntime($siteNode, $controllerContext);
        $runtime->pushContextArray([
            'site' => $siteNode,
        ]);
        $siteContentProperties = (array)$runtime->render('contentApi/site');
        $runtime->popContext();

        return $siteContentProperties;
    }
}
