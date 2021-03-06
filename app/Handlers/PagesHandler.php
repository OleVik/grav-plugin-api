<?php
namespace GravApi\Handlers;

use Grav\Common\Filesystem\Folder;
use GravApi\Responses\Response;
use GravApi\Resources\PageResource;
use GravApi\Resources\PageCollectionResource;
use GravApi\Helpers\PageHelper;
use GravApi\Helpers\ArrayHelper;

/**
 * Class PagesHandler
 * @package GravApi\Handlers
 */
class PagesHandler extends BaseHandler
{
    public function getPages($request, $response, $args)
    {
        $collection = $this->grav['pages']->all();

        $resource = new PageCollectionResource($collection);

        return $response->withJson(
            $this->getFilteredResource($resource)
        );
    }

    public function getPage($request, $response, $args)
    {
        $route = "/{$request->getAttribute('page')}";
        $page = $this->grav['pages']->find($route);

        if (!$page) {
            return $response->withJson(Response::NotFound(), 404);
        }

        $resource = new PageResource($page);

        return $response->withJson(
            $this->getFilteredResource($resource)
        );
    }

    public function newPage($request, $response, $args)
    {
        $parsedBody = $request->getParsedBody();

        if ( empty($parsedBody['route']) ) {
            return $response->withJson(Response::BadRequest('You must provide a `route` field!'), 400);
        }

        $route = $parsedBody['route'];
        $existingPage = $this->grav['pages']->find($route);

        // if existingPage is a directory, we can still create a file, so check if isPage
        if ($existingPage && $existingPage->isPage()) {
            return $response->withJson(Response::ResourceExists(), 403);
        }

        $template = !empty($parsedBody['template']) ? $parsedBody['template'] : 'default';

        // Our helper is used to create a page in new directories
        $helper = new PageHelper($route, $template);

        try {
            // if existingPage evals true, it means a directory
            // already exists, we just need to save the file
            $page = $existingPage ?: $helper->getOrCreatePage();

            // Our Helper will set a template when creating a new page
            // but we set it here too in case we are using an existing dir 'page'
            $page->name($helper->getFilename());

            // Add frontmatter to our page
            if (!empty($parsedBody['header']) ) {
                if ( !is_array($parsedBody['header']) ) {
                    throw new \Exception("Field `header` must be valid JSON.", 1);
                }

                $page->header($parsedBody['header']);
            }

            // Add content to our page
            if (!empty($parsedBody['content']) ) {
                $page->content($parsedBody['content']);
            }

            // Save the page with the new header/content fields
            $page->save();

        } catch(\Exception $e) {
            // rollback
            $success = Folder::delete($helper->page->path());

            return $response->withJson(Response::BadRequest($e->getMessage()), 400);
        }

        // Use our resource to return the filtered page
        $resource = new PageResource($page);

        return $response->withJson(
            $this->getFilteredResource($resource)
        );
    }

    public function deletePage($request, $response, $args)
    {
        $route = "/{$request->getAttribute('page')}";
        $page = $this->grav['pages']->find($route);

        if (!$page || !$page->exists()) {
            return $response->withJson(Response::NotFound(), 404);
        }

        // if the requested route has non-modular children, we just delete the route's markdown file, keeping the directory
        if ( 0 < count($page->children()->nonModular()) ) {
            $page->file()->delete();
        } else {
            Folder::delete($page->path());

            // since this page has no children, we can clean up the unused directories too

            $child = $page;
            $parentRoute = dirname($page->route());
            // recursively check parent directories for files, and delete them if empty
            while($parentRoute !== '') {
                $parent = $this->grav['pages']->find($parentRoute);

                // if we hit the root, stop
                if ($parent === null) {
                    break;
                }

                // Get the parents children, minus the child we just deleted
                $filteredChildren = $parent->children()->remove($child);

                // if the parent directory exists, or has children, we should stop
                if( $parent->isPage() || 0 < count($filteredChildren->toArray()) )
                {
                    break;
                }

                // set this parent as the next child to delete
                $child = $parent;
                // delete the folder
                Folder::delete($parent->path());
                $parentRoute = dirname($parentRoute);
            }
        }

        return $response->withStatus(204);
    }

    public function updatePage($request, $response, $args)
    {
        $route = "/{$request->getAttribute('page')}";
        $page = $this->grav['pages']->find($route);

        if (!$page || !$page->exists()) {
            return $response->withJson(Response::NotFound(), 404);
        }

        $parsedBody = $request->getParsedBody();
        $template = $parsedBody['template'] ?: '';

        if ( empty($parsedBody['route']) ) {
            return $response->withJson(Response::BadRequest('You must provide a `route` field!'), 400);
        }

        // update the page content
        if ( !empty($parsedBody['content']) ) {
            $page->content($parsedBody['content']);
        }

        // create new helper for updating header and template
        $helper = new PageHelper($route, $template);

        // update the page header
        if ( !empty($parsedBody['header']) ) {

            $updatedHeader = ArrayHelper::merge(
                $page->header(),
                $parsedBody['header']
            );

            $page->header($updatedHeader);
        }

        // update the page template
        if ( !empty($parsedBody['template']) ) {

            // we need to trigger a fake 'move'
            // (i.e. to the same parent)
            // otherwise a new file will be made
            // instead of renaming the existing one
            $page->move($page->parent());

            // sets the file to use our new template
            $page->name($helper->getFilename());
        }

        // save the changes to the file
        // (this is when the 'move' would actually happen)
        $page->save();

        // Use our resource to return the updated page
        $resource = new PageResource($page);

        return $response->withJson(
            $this->getFilteredResource($resource)
        );
    }

    // Applies our config field filter to the resource and
    // returns the remaining data as JSON
    protected function getFilteredResource($resource) {
        $filter = null;

        if ( !empty($this->config->pages->get['fields']) ) {
            $filter = $this->config->pages->get['fields'];
        }

        return $resource->toJson($filter);
    }
}
