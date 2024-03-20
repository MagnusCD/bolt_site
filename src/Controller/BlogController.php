<?php

namespace App\Controller;

use Bolt\Common\Str;
use Bolt\Configuration\Content\ContentType;
use Bolt\Controller\Frontend\FrontendZoneInterface;
use Bolt\Controller\TwigAwareController;
use Bolt\Entity\Content;
use Bolt\Repository\ContentRepository;
use Bolt\Storage\Query;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class BlogController extends TwigAwareController implements FrontendZoneInterface
{
    /** @var Query */
    private $query;

    public function __construct(Query $query)
    {
        $this->query = $query;
    }

    #[Route(
        '/blog-posts',
        name: 'blog_posts',
        methods: ['GET', 'POST'],
        priority: 200
    )]


    public function blogPosts(Request $request, \Bolt\Configuration\Config $coreConfig): Response
    {

        ($coreConfig->get('general/sortfields/blogposts'));
        $contentTypeSlug = 'blog-posts';

        $contentType = ContentType::factory($contentTypeSlug, $this->config->get('contenttypes'));

            $params = $this->parseQueryParams($this->request, $contentType);
            $sort = $request->query->get('sort', 'date');

            $orderby = ($sort === 'date') ? 'datepublished' : (($sort === 'title') ? 'title' : null);

            if($orderby) {
                $params['order'] = $orderby;
            }

            /** @var Pagerfanta|null $content */
            $records = $this->query->getContent($contentTypeSlug, $params);
            $page = (int) $this->getFromRequest('page', '1');
            $amountPerPage = $contentType->get('listing_records');
            $records = $this->setRecords($records, $amountPerPage, $page);

            // Pass sorted records to Twig template
            return $this->render('blogposts.twig', [
                'records' => $records,
                'sort' => $sort // Pass the sort option to maintain selection in the form
            ]);
    }

    public function listing(ContentRepository $contentRepository, ?string $_locale = null): Response
    {
        if ($_locale === null && !$this->getFromRequest('_locale', null)) {
            $this->request->setLocale($this->defaultLocale);
        }

        $contentTypeSlug = 'blog-posts';
        $contentType = ContentType::factory($contentTypeSlug, $this->config->get('contenttypes'));

        // If the ContentType has 'viewless_listing' set to `true`, we throw a 404.
        if ($contentType->get('viewless_listing') === true) {
            throw new NotFoundHttpException('Content is not viewable');
        }

        // If the locale is the wrong locale
        if (!$this->validLocaleForContentType($contentType)) {
            return $this->redirectToDefaultLocale();
        }

        $page = (int) $this->getFromRequest('page', '1');
        $amountPerPage = $contentType->get('listing_records');
        $params = $this->parseQueryParams($this->request, $contentType);

        /** @var Content|Pagerfanta $content */
        $content = $this->query->getContent($contentTypeSlug, $params);

        // If we're foolishly trying to "list" a singleton, we're getting a single Content here
        if ($content instanceof Content) {
            $route = $content->getDefinition()->get('record_route');
            $controller = $this->container->get('router')->getRouteCollection()->get($route)->getDefault('_controller');

            $parameters = $this->request->attributes->all();
            $parameters['slugOrId'] = $content->getId();

            return $this->forward($controller, $parameters);
        }

        $records = $this->setRecords($content, $amountPerPage, $page);

        // Set canonical URL
        $this->canonical->setPath(
            'listing_locale',
            array_merge([
                'contentTypeSlug' => $contentType->get('slug'),
                '_locale' => $this->request->getLocale(),
            ], $params)
        );

        // Render
        $templates = $this->templateChooser->forListing($contentType);
        $this->twig->addGlobal('records', $records);

        $twigVars = [
            'records' => $records,
            $contentType->getSlug() => $records,
            'contenttype' => $contentType,
        ];

        return $this->render($templates, $twigVars);
    }







    private function parseQueryParams(Request $request, ContentType $contentType): array
    {
        if ($this->config->get('general/query_search')->get('enable', true) === false) {
            return [
                'order' => $contentType->get('order'),
                'status' => 'published',
            ];
        }

        $queryParams = collect($request->query->all());

        // Note, we're not including 'limit', 'printquery', 'returnsingle' or 'returnmultiple' on purpose
        $allowedParams = array_merge(
            $contentType['fields']->keys()->all(),
            $contentType['taxonomy']->all(),
            ['order', 'earliest', 'latest', 'offset', 'page', 'random', 'author', 'anyField', 'anything']
        );

        $params = $queryParams->mapWithKeys(function ($value, $key) use ($allowedParams) {
            // Ensure we don't have arrays, if we get something like `title[]=…` passed in.
            if (is_array($value)) {
                $value = current($value);
            }

            if (str::endsWith($key, '--like')) {
                $key = str::removeLast($key, '--like');
                $value = '%' . $value . '%';
            }

            return in_array($key, $allowedParams, true) ? [$key => $value] : [];
        })->toArray();

        if (!array_key_exists('order', $params)) {
            $params['order'] = $contentType->get('order');
        }

        // Ensure we only list things that are 'published'
        $params['status'] = 'published';

        if ($this->config->get('general/query_search')->get('ignore_empty', false) === true) {
            $params = array_filter($params, function ($param) {
                return !($param === '' | $param === '%%');
            });
        }

        return $params;
    }


    private function setRecords($content, int $amountPerPage, int $page): Pagerfanta
    {
        if ($content instanceof Pagerfanta) {
            $records = $content->setMaxPerPage($amountPerPage)
                ->setCurrentPage($page);
        } else {
            $records = new Pagerfanta(new ArrayAdapter([]));
        }

        return $records;
    }



    #[Route(
        '/subscription-posts',
        name: 'subscription_posts',
        methods: ['GET', 'POST'],
        priority: 250
    )]

    #[IsGranted('ROLE_USER_SUBSCRIBER')]
    public function subscriptionPosts (Request $request, \Bolt\Configuration\Config $coreConfig): Response
    {

        ($coreConfig->get('general/sortfields/blogposts'));
        $contentTypeSlug = 'blog-posts';

        $contentType = ContentType::factory($contentTypeSlug, $this->config->get('contenttypes'));

        $params = $this->parseQueryParams($this->request, $contentType);
        $sort = $request->query->get('sort', 'date');

        $orderby = ($sort === 'date') ? 'datepublished' : (($sort === 'title') ? 'title' : null);

        if($orderby) {
            $params['order'] = $orderby;
        }

        /** @var Pagerfanta|null $content */
        $records = $this->query->getContent($contentTypeSlug, $params);
        $page = (int) $this->getFromRequest('page', '1');
        $amountPerPage = $contentType->get('listing_records');
        $records = $this->setRecords($records, $amountPerPage, $page);

        // Pass sorted records to Twig template
        return $this->render('subscriptionposts.twig', [
            'records' => $records,
            'sort' => $sort // Pass the sort option to maintain selection in the form
        ]);
    }
}



