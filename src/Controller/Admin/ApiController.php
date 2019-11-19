<?php
namespace App\Controller\Admin;

use App\Entity;
use App\Form\EntityFormManager;
use App\Http\Response;
use App\Http\ServerRequest;
use Azura\Config;
use Azura\Session\Flash;
use Psr\Http\Message\ResponseInterface;

class ApiController extends AbstractAdminCrudController
{
    /**
     * @param Config $config
     * @param EntityFormManager $formManager
     */
    public function __construct(
        Config $config,
        EntityFormManager $formManager
    ) {
        $form = $formManager->getForm(Entity\ApiKey::class, $config->get('forms/api_key'));

        parent::__construct($form);
        $this->csrf_namespace = 'admin_api';
    }

    public function indexAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $records = $this->em->createQuery(/** @lang DQL */ 'SELECT 
            a, u FROM App\Entity\ApiKey a JOIN a.user u')
            ->getArrayResult();

        return $request->getView()->renderToResponse($response, 'admin/api/index', [
            'records' => $records,
            'csrf' => $request->getCsrf()->generate($this->csrf_namespace),
        ]);
    }

    public function editAction(ServerRequest $request, Response $response, $id): ResponseInterface
    {
        if (false !== $this->_doEdit($request, $id)) {
            $request->getFlash()->addMessage(__('API Key updated.'), Flash::SUCCESS);
            return $response->withRedirect($request->getRouter()->named('admin:api:index'));
        }

        return $request->getView()->renderToResponse($response, 'system/form_page', [
            'form' => $this->form,
            'render_mode' => 'edit',
            'title' => __('Edit API Key'),
        ]);
    }

    public function deleteAction(ServerRequest $request, Response $response, $id, $csrf): ResponseInterface
    {
        $this->_doDelete($request, $id, $csrf);

        $request->getFlash()->addMessage(__('API Key deleted.'), Flash::SUCCESS);
        return $response->withRedirect($request->getRouter()->named('admin:api:index'));
    }
}
