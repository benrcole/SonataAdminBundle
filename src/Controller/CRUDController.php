<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Controller;

use Doctrine\Inflector\InflectorFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Admin\BreadcrumbsBuilderInterface;
use Sonata\AdminBundle\Admin\FieldDescriptionCollection;
use Sonata\AdminBundle\Admin\Pool;
use Sonata\AdminBundle\Bridge\Exporter\AdminExporter;
use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\AdminBundle\Exception\LockException;
use Sonata\AdminBundle\Exception\ModelManagerException;
use Sonata\AdminBundle\Model\AuditManagerInterface;
use Sonata\AdminBundle\Templating\TemplateRegistryInterface;
use Sonata\AdminBundle\Util\AdminAclUserManagerInterface;
use Sonata\AdminBundle\Util\AdminObjectAclData;
use Sonata\AdminBundle\Util\AdminObjectAclManipulator;
use Sonata\Exporter\Exporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyPath;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * @phpstan-template T of object
 */
class CRUDController extends AbstractController
{
    /**
     * The related Admin class.
     *
     * @var AdminInterface
     *
     * @phpstan-var AdminInterface<T>
     */
    protected $admin;

    /**
     * The template registry of the related Admin class.
     *
     * @var TemplateRegistryInterface
     */
    private $templateRegistry;

    public static function getSubscribedServices(): array
    {
        return [
            'sonata.admin.pool' => Pool::class,
            'sonata.admin.breadcrumbs_builder' => BreadcrumbsBuilderInterface::class,
            'sonata.admin.audit.manager' => AuditManagerInterface::class,
            'sonata.admin.object.manipulator.acl.admin' => AdminObjectAclManipulator::class,
            'sonata.exporter.exporter' => '?'.Exporter::class,
            'sonata.admin.admin_exporter' => '?'.AdminExporter::class,
            'sonata.admin.security.acl_user_manager' => '?'.AdminAclUserManagerInterface::class,

            'logger' => '?'.LoggerInterface::class,
            'translator' => TranslatorInterface::class,
        ] + parent::getSubscribedServices();
    }

    /**
     * Renders a view while passing mandatory parameters on to the template.
     *
     * @param string               $view       The view name
     * @param array<string, mixed> $parameters An array of parameters to pass to the view
     *
     * @return Response A Response instance
     */
    public function renderWithExtraParams(string $view, array $parameters = [], ?Response $response = null): Response
    {
        return $this->render($view, $this->addRenderExtraParams($parameters), $response);
    }

    /**
     * List action.
     *
     * @throws AccessDeniedException If access is not granted
     */
    public function listAction(Request $request): Response
    {
        $this->admin->checkAccess('list');

        $preResponse = $this->preList($request);
        if (null !== $preResponse) {
            return $preResponse;
        }

        if ($listMode = $request->get('_list_mode')) {
            $this->admin->setListMode($listMode);
        }

        $datagrid = $this->admin->getDatagrid();
        $formView = $datagrid->getForm()->createView();

        // set the theme for the current Admin Form
        $this->setFormTheme($formView, $this->admin->getFilterTheme());

        $template = $this->templateRegistry->getTemplate('list');

        return $this->renderWithExtraParams($template, [
            'action' => 'list',
            'form' => $formView,
            'datagrid' => $datagrid,
            'csrf_token' => $this->getCsrfToken('sonata.batch'),
            'export_formats' => $this->has('sonata.admin.admin_exporter') ?
                $this->get('sonata.admin.admin_exporter')->getAvailableFormats($this->admin) :
                $this->admin->getExportFormats(),
        ], null);
    }

    /**
     * Execute a batch delete.
     *
     * @throws AccessDeniedException If access is not granted
     */
    public function batchActionDelete(ProxyQueryInterface $query): Response
    {
        $this->admin->checkAccess('batchDelete');

        $modelManager = $this->admin->getModelManager();

        try {
            $modelManager->batchDelete($this->admin->getClass(), $query);
            $this->addFlash(
                'sonata_flash_success',
                $this->trans('flash_batch_delete_success', [], 'SonataAdminBundle')
            );
        } catch (ModelManagerException $e) {
            $this->handleModelManagerException($e);
            $this->addFlash(
                'sonata_flash_error',
                $this->trans('flash_batch_delete_error', [], 'SonataAdminBundle')
            );
        }

        return $this->redirectToList();
    }

    /**
     * Delete action.
     *
     * @throws NotFoundHttpException If the object does not exist
     * @throws AccessDeniedException If access is not granted
     */
    public function deleteAction(Request $request): Response
    {
        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('delete', $object);

        $preResponse = $this->preDelete($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        if (Request::METHOD_DELETE === $request->getMethod()) {
            // check the csrf token
            $this->validateCsrfToken('sonata.delete');

            $objectName = $this->admin->toString($object);

            try {
                $this->admin->delete($object);

                if ($this->isXmlHttpRequest()) {
                    return $this->renderJson(['result' => 'ok'], Response::HTTP_OK, []);
                }

                $this->addFlash(
                    'sonata_flash_success',
                    $this->trans(
                        'flash_delete_success',
                        ['%name%' => $this->escapeHtml($objectName)],
                        'SonataAdminBundle'
                    )
                );
            } catch (ModelManagerException $e) {
                $this->handleModelManagerException($e);

                if ($this->isXmlHttpRequest()) {
                    return $this->renderJson(['result' => 'error'], Response::HTTP_OK, []);
                }

                $this->addFlash(
                    'sonata_flash_error',
                    $this->trans(
                        'flash_delete_error',
                        ['%name%' => $this->escapeHtml($objectName)],
                        'SonataAdminBundle'
                    )
                );
            }

            return $this->redirectTo($object);
        }

        $template = $this->templateRegistry->getTemplate('delete');

        return $this->renderWithExtraParams($template, [
            'object' => $object,
            'action' => 'delete',
            'csrf_token' => $this->getCsrfToken('sonata.delete'),
        ], null);
    }

    /**
     * Edit action.
     *
     * @throws NotFoundHttpException If the object does not exist
     * @throws AccessDeniedException If access is not granted
     */
    public function editAction(Request $request): Response
    {
        // the key used to lookup the template
        $templateKey = 'edit';

        $id = $request->get($this->admin->getIdParameter());
        $existingObject = $this->admin->getObject($id);

        if (!$existingObject) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->checkParentChildAssociation($request, $existingObject);

        $this->admin->checkAccess('edit', $existingObject);

        $preResponse = $this->preEdit($request, $existingObject);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($existingObject);
        $objectId = $this->admin->getNormalizedIdentifier($existingObject);

        $form = $this->admin->getForm();

        $form->setData($existingObject);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $isFormValid = $form->isValid();

            // persist if the form was valid and if in preview mode the preview was approved
            if ($isFormValid && (!$this->isInPreviewMode() || $this->isPreviewApproved())) {
                /** @phpstan-var T $submittedObject */
                $submittedObject = $form->getData();
                $this->admin->setSubject($submittedObject);

                try {
                    $existingObject = $this->admin->update($submittedObject);

                    if ($this->isXmlHttpRequest()) {
                        return $this->handleXmlHttpRequestSuccessResponse($request, $existingObject);
                    }

                    $this->addFlash(
                        'sonata_flash_success',
                        $this->trans(
                            'flash_edit_success',
                            ['%name%' => $this->escapeHtml($this->admin->toString($existingObject))],
                            'SonataAdminBundle'
                        )
                    );

                    // redirect to edit mode
                    return $this->redirectTo($existingObject);
                } catch (ModelManagerException $e) {
                    $this->handleModelManagerException($e);

                    $isFormValid = false;
                } catch (LockException $e) {
                    $this->addFlash('sonata_flash_error', $this->trans('flash_lock_error', [
                        '%name%' => $this->escapeHtml($this->admin->toString($existingObject)),
                        '%link_start%' => sprintf('<a href="%s">', $this->admin->generateObjectUrl('edit', $existingObject)),
                        '%link_end%' => '</a>',
                    ], 'SonataAdminBundle'));
                }
            }

            // show an error message if the form failed validation
            if (!$isFormValid) {
                if ($this->isXmlHttpRequest() && null !== ($response = $this->handleXmlHttpRequestErrorResponse($request, $form))) {
                    return $response;
                }

                $this->addFlash(
                    'sonata_flash_error',
                    $this->trans(
                        'flash_edit_error',
                        ['%name%' => $this->escapeHtml($this->admin->toString($existingObject))],
                        'SonataAdminBundle'
                    )
                );
            } elseif ($this->isPreviewRequested()) {
                // enable the preview template if the form was valid and preview was requested
                $templateKey = 'preview';
                $this->admin->getShow();
            }
        }

        $formView = $form->createView();
        // set the theme for the current Admin Form
        $this->setFormTheme($formView, $this->admin->getFormTheme());

        $template = $this->templateRegistry->getTemplate($templateKey);

        return $this->renderWithExtraParams($template, [
            'action' => 'edit',
            'form' => $formView,
            'object' => $existingObject,
            'objectId' => $objectId,
        ], null);
    }

    /**
     * Batch action.
     *
     * @throws NotFoundHttpException If the HTTP method is not POST
     * @throws \RuntimeException     If the batch action is not defined
     */
    public function batchAction(Request $request): Response
    {
        $restMethod = $request->getMethod();

        if (Request::METHOD_POST !== $restMethod) {
            throw $this->createNotFoundException(sprintf(
                'Invalid request method given "%s", %s expected',
                $restMethod,
                Request::METHOD_POST
            ));
        }

        // check the csrf token
        $this->validateCsrfToken('sonata.batch');

        $confirmation = $request->get('confirmation', false);

        $forwardedRequest = $request->duplicate();

        if ($data = json_decode((string) $request->get('data', ''), true)) {
            $action = $data['action'];
            $idx = (array) ($data['idx'] ?? []);
            $allElements = (bool) ($data['all_elements'] ?? false);
            $forwardedRequest->request->replace(array_merge($forwardedRequest->request->all(), $data));
        } else {
            $action = $forwardedRequest->request->get('action');
            /** @var InputBag|ParameterBag $bag */
            $bag = $request->request;
            if ($bag instanceof InputBag) {
                // symfony 5.1+
                $idx = $bag->all('idx');
            } else {
                $idx = (array) $bag->get('idx', []);
            }
            $allElements = $forwardedRequest->request->getBoolean('all_elements');

            $forwardedRequest->request->set('idx', $idx);
            $forwardedRequest->request->set('all_elements', $allElements);

            $data = $forwardedRequest->request->all();
            $data['all_elements'] = $allElements;

            unset($data['_sonata_csrf_token']);
        }

        $batchActions = $this->admin->getBatchActions();
        if (!\array_key_exists($action, $batchActions)) {
            throw new \RuntimeException(sprintf('The `%s` batch action is not defined', $action));
        }

        $camelizedAction = InflectorFactory::create()->build()->classify($action);
        $isRelevantAction = sprintf('batchAction%sIsRelevant', $camelizedAction);

        if (method_exists($this, $isRelevantAction)) {
            $nonRelevantMessage = $this->$isRelevantAction($idx, $allElements, $forwardedRequest);
        } else {
            $nonRelevantMessage = 0 !== \count($idx) || $allElements; // at least one item is selected
        }

        if (!$nonRelevantMessage) { // default non relevant message (if false of null)
            $nonRelevantMessage = 'flash_batch_empty';
        }

        $datagrid = $this->admin->getDatagrid();
        $datagrid->buildPager();

        if (true !== $nonRelevantMessage) {
            $this->addFlash(
                'sonata_flash_info',
                $this->trans($nonRelevantMessage, [], 'SonataAdminBundle')
            );

            return $this->redirectToList();
        }

        $askConfirmation = $batchActions[$action]['ask_confirmation'] ??
            true;

        if ($askConfirmation && 'ok' !== $confirmation) {
            $actionLabel = $batchActions[$action]['label'];
            $batchTranslationDomain = $batchActions[$action]['translation_domain'] ??
                $this->admin->getTranslationDomain();

            $formView = $datagrid->getForm()->createView();
            $this->setFormTheme($formView, $this->admin->getFilterTheme());

            $template = !empty($batchActions[$action]['template']) ?
                $batchActions[$action]['template'] :
                $this->templateRegistry->getTemplate('batch_confirmation');

            return $this->renderWithExtraParams($template, [
                'action' => 'list',
                'action_label' => $actionLabel,
                'batch_translation_domain' => $batchTranslationDomain,
                'datagrid' => $datagrid,
                'form' => $formView,
                'data' => $data,
                'csrf_token' => $this->getCsrfToken('sonata.batch'),
            ], null);
        }

        // execute the action, batchActionXxxxx
        $finalAction = sprintf('batchAction%s', $camelizedAction);
        if (!method_exists($this, $finalAction)) {
            throw new \RuntimeException(sprintf('A `%s::%s` method must be callable', static::class, $finalAction));
        }

        $query = $datagrid->getQuery();

        $query->setFirstResult(null);
        $query->setMaxResults(null);

        $this->admin->preBatchAction($action, $query, $idx, $allElements);

        if (\count($idx) > 0) {
            $this->admin->getModelManager()->addIdentifiersToQuery($this->admin->getClass(), $query, $idx);
        } elseif (!$allElements) {
            $this->addFlash(
                'sonata_flash_info',
                $this->trans('flash_batch_no_elements_processed', [], 'SonataAdminBundle')
            );

            return $this->redirectToList();
        }

        return $this->$finalAction($query, $forwardedRequest);
    }

    /**
     * Create action.
     *
     * @throws AccessDeniedException If access is not granted
     */
    public function createAction(Request $request): Response
    {
        // the key used to lookup the template
        $templateKey = 'edit';

        $this->admin->checkAccess('create');

        $class = new \ReflectionClass($this->admin->hasActiveSubClass() ? $this->admin->getActiveSubClass() : $this->admin->getClass());

        if ($class->isAbstract()) {
            return $this->renderWithExtraParams(
                '@SonataAdmin/CRUD/select_subclass.html.twig',
                [
                    'base_template' => $this->getBaseTemplate(),
                    'admin' => $this->admin,
                    'action' => 'create',
                ],
                null
            );
        }

        $newObject = $this->admin->getNewInstance();

        $preResponse = $this->preCreate($request, $newObject);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($newObject);

        $form = $this->admin->getForm();

        $form->setData($newObject);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $isFormValid = $form->isValid();

            // persist if the form was valid and if in preview mode the preview was approved
            if ($isFormValid && (!$this->isInPreviewMode() || $this->isPreviewApproved())) {
                /** @phpstan-var T $submittedObject */
                $submittedObject = $form->getData();
                $this->admin->setSubject($submittedObject);
                $this->admin->checkAccess('create', $submittedObject);

                try {
                    $newObject = $this->admin->create($submittedObject);

                    if ($this->isXmlHttpRequest()) {
                        return $this->handleXmlHttpRequestSuccessResponse($request, $newObject);
                    }

                    $this->addFlash(
                        'sonata_flash_success',
                        $this->trans(
                            'flash_create_success',
                            ['%name%' => $this->escapeHtml($this->admin->toString($newObject))],
                            'SonataAdminBundle'
                        )
                    );

                    // redirect to edit mode
                    return $this->redirectTo($newObject);
                } catch (ModelManagerException $e) {
                    $this->handleModelManagerException($e);

                    $isFormValid = false;
                }
            }

            // show an error message if the form failed validation
            if (!$isFormValid) {
                if ($this->isXmlHttpRequest() && null !== ($response = $this->handleXmlHttpRequestErrorResponse($request, $form))) {
                    return $response;
                }

                $this->addFlash(
                    'sonata_flash_error',
                    $this->trans(
                        'flash_create_error',
                        ['%name%' => $this->escapeHtml($this->admin->toString($newObject))],
                        'SonataAdminBundle'
                    )
                );
            } elseif ($this->isPreviewRequested()) {
                // pick the preview template if the form was valid and preview was requested
                $templateKey = 'preview';
                $this->admin->getShow();
            }
        }

        $formView = $form->createView();
        // set the theme for the current Admin Form
        $this->setFormTheme($formView, $this->admin->getFormTheme());

        $template = $this->templateRegistry->getTemplate($templateKey);

        return $this->renderWithExtraParams($template, [
            'action' => 'create',
            'form' => $formView,
            'object' => $newObject,
            'objectId' => null,
        ], null);
    }

    /**
     * Show action.
     *
     * @throws NotFoundHttpException If the object does not exist
     * @throws AccessDeniedException If access is not granted
     */
    public function showAction(Request $request): Response
    {
        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();
        \assert($fields instanceof FieldDescriptionCollection);

        $template = $this->templateRegistry->getTemplate('show');

        return $this->renderWithExtraParams($template, [
            'action' => 'show',
            'object' => $object,
            'elements' => $fields,
        ]);
    }

    /**
     * Show history revisions for object.
     *
     * @throws AccessDeniedException If access is not granted
     * @throws NotFoundHttpException If the object does not exist or the audit reader is not available
     */
    public function historyAction(Request $request): Response
    {
        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('history', $object);

        $manager = $this->get('sonata.admin.audit.manager');

        if (!$manager->hasReader($this->admin->getClass())) {
            throw $this->createNotFoundException(sprintf(
                'unable to find the audit reader for class : %s',
                $this->admin->getClass()
            ));
        }

        $reader = $manager->getReader($this->admin->getClass());

        $revisions = $reader->findRevisions($this->admin->getClass(), $id);

        $template = $this->templateRegistry->getTemplate('history');

        return $this->renderWithExtraParams($template, [
            'action' => 'history',
            'object' => $object,
            'revisions' => $revisions,
            'currentRevision' => $revisions ? current($revisions) : false,
        ], null);
    }

    /**
     * View history revision of object.
     *
     * @throws AccessDeniedException If access is not granted
     * @throws NotFoundHttpException If the object or revision does not exist or the audit reader is not available
     */
    public function historyViewRevisionAction(Request $request, string $revision): Response
    {
        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('historyViewRevision', $object);

        $manager = $this->get('sonata.admin.audit.manager');

        if (!$manager->hasReader($this->admin->getClass())) {
            throw $this->createNotFoundException(sprintf(
                'unable to find the audit reader for class : %s',
                $this->admin->getClass()
            ));
        }

        $reader = $manager->getReader($this->admin->getClass());

        // retrieve the revisioned object
        $object = $reader->find($this->admin->getClass(), $id, $revision);

        if (!$object) {
            throw $this->createNotFoundException(sprintf(
                'unable to find the targeted object `%s` from the revision `%s` with classname : `%s`',
                $id,
                $revision,
                $this->admin->getClass()
            ));
        }

        $this->admin->setSubject($object);

        $template = $this->templateRegistry->getTemplate('show');

        return $this->renderWithExtraParams($template, [
            'action' => 'show',
            'object' => $object,
            'elements' => $this->admin->getShow(),
        ], null);
    }

    /**
     * Compare history revisions of object.
     *
     * @throws AccessDeniedException If access is not granted
     * @throws NotFoundHttpException If the object or revision does not exist or the audit reader is not available
     */
    public function historyCompareRevisionsAction(Request $request, string $baseRevision, string $compareRevision): Response
    {
        $this->admin->checkAccess('historyCompareRevisions');

        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $manager = $this->get('sonata.admin.audit.manager');

        if (!$manager->hasReader($this->admin->getClass())) {
            throw $this->createNotFoundException(sprintf(
                'unable to find the audit reader for class : %s',
                $this->admin->getClass()
            ));
        }

        $reader = $manager->getReader($this->admin->getClass());

        // retrieve the base revision
        $baseObject = $reader->find($this->admin->getClass(), $id, $baseRevision);
        if (!$baseObject) {
            throw $this->createNotFoundException(
                sprintf(
                    'unable to find the targeted object `%s` from the revision `%s` with classname : `%s`',
                    $id,
                    $baseRevision,
                    $this->admin->getClass()
                )
            );
        }

        // retrieve the compare revision
        $compareObject = $reader->find($this->admin->getClass(), $id, $compareRevision);
        if (!$compareObject) {
            throw $this->createNotFoundException(
                sprintf(
                    'unable to find the targeted object `%s` from the revision `%s` with classname : `%s`',
                    $id,
                    $compareRevision,
                    $this->admin->getClass()
                )
            );
        }

        $this->admin->setSubject($baseObject);

        $template = $this->templateRegistry->getTemplate('show_compare');

        return $this->renderWithExtraParams($template, [
            'action' => 'show',
            'object' => $baseObject,
            'object_compare' => $compareObject,
            'elements' => $this->admin->getShow(),
        ], null);
    }

    /**
     * Export data to specified format.
     *
     * @throws AccessDeniedException If access is not granted
     * @throws \RuntimeException     If the export format is invalid
     */
    public function exportAction(Request $request): Response
    {
        $this->admin->checkAccess('export');

        $format = $request->get('format');

        $adminExporter = $this->get('sonata.admin.admin_exporter');
        $allowedExportFormats = $adminExporter->getAvailableFormats($this->admin);
        $filename = $adminExporter->getExportFilename($this->admin, $format);
        $exporter = $this->get('sonata.exporter.exporter');

        if (!\in_array($format, $allowedExportFormats, true)) {
            throw new \RuntimeException(sprintf(
                'Export in format `%s` is not allowed for class: `%s`. Allowed formats are: `%s`',
                $format,
                $this->admin->getClass(),
                implode(', ', $allowedExportFormats)
            ));
        }

        return $exporter->getResponse(
            $format,
            $filename,
            $this->admin->getDataSourceIterator()
        );
    }

    /**
     * Returns the Response object associated to the acl action.
     *
     * @throws AccessDeniedException If access is not granted
     * @throws NotFoundHttpException If the object does not exist or the ACL is not enabled
     */
    public function aclAction(Request $request): Response
    {
        if (!$this->admin->isAclEnabled()) {
            throw $this->createNotFoundException('ACL are not enabled for this admin');
        }

        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', $id));
        }

        $this->admin->checkAccess('acl', $object);

        $this->admin->setSubject($object);
        $aclUsers = $this->getAclUsers();
        $aclRoles = $this->getAclRoles();

        $adminObjectAclManipulator = $this->get('sonata.admin.object.manipulator.acl.admin');
        $adminObjectAclData = new AdminObjectAclData(
            $this->admin,
            $object,
            $aclUsers,
            $adminObjectAclManipulator->getMaskBuilderClass(),
            $aclRoles
        );

        $aclUsersForm = $adminObjectAclManipulator->createAclUsersForm($adminObjectAclData);
        $aclRolesForm = $adminObjectAclManipulator->createAclRolesForm($adminObjectAclData);

        if (Request::METHOD_POST === $request->getMethod()) {
            if ($request->request->has(AdminObjectAclManipulator::ACL_USERS_FORM_NAME)) {
                $form = $aclUsersForm;
                $updateMethod = 'updateAclUsers';
            } elseif ($request->request->has(AdminObjectAclManipulator::ACL_ROLES_FORM_NAME)) {
                $form = $aclRolesForm;
                $updateMethod = 'updateAclRoles';
            }

            if (isset($form, $updateMethod)) {
                $form->handleRequest($request);

                if ($form->isValid()) {
                    $adminObjectAclManipulator->$updateMethod($adminObjectAclData);
                    $this->addFlash(
                        'sonata_flash_success',
                        $this->trans('flash_acl_edit_success', [], 'SonataAdminBundle')
                    );

                    return new RedirectResponse($this->admin->generateObjectUrl('acl', $object));
                }
            }
        }

        $template = $this->templateRegistry->getTemplate('acl');

        return $this->renderWithExtraParams($template, [
            'action' => 'acl',
            'permissions' => $adminObjectAclData->getUserPermissions(),
            'object' => $object,
            'users' => $aclUsers,
            'roles' => $aclRoles,
            'aclUsersForm' => $aclUsersForm->createView(),
            'aclRolesForm' => $aclRolesForm->createView(),
        ], null);
    }

    public function getRequest(): Request
    {
        return $this->get('request_stack')->getCurrentRequest();
    }

    /**
     * Contextualize the admin class depends on the current request.
     *
     * @throws \InvalidArgumentException
     */
    final public function configureAdmin(Request $request): void
    {
        $adminCode = $request->get('_sonata_admin');

        if (null === $adminCode) {
            throw new \InvalidArgumentException(sprintf(
                'There is no `_sonata_admin` defined for the controller `%s` and the current route `%s`.',
                static::class,
                $request->get('_route')
            ));
        }

        try {
            $this->admin = $this->get('sonata.admin.pool')->getAdminByAdminCode($adminCode);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(sprintf(
                'Unable to find the admin class related to the current controller (%s).',
                static::class
            ));
        }

        if (!$this->admin->hasTemplateRegistry()) {
            throw new \RuntimeException(sprintf(
                'Unable to find the template registry related to the current admin (%s).',
                $this->admin->getCode()
            ));
        }

        $this->templateRegistry = $this->admin->getTemplateRegistry();

        $rootAdmin = $this->admin;

        while ($rootAdmin->isChild()) {
            $rootAdmin->setCurrentChild(true);
            $rootAdmin = $rootAdmin->getParent();
        }

        $rootAdmin->setRequest($request);

        if ($request->get('uniqid')) {
            $this->admin->setUniqid($request->get('uniqid'));
        }
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    protected function addRenderExtraParams(array $parameters = []): array
    {
        if (!$this->isXmlHttpRequest()) {
            $parameters['breadcrumbs_builder'] = $this->get('sonata.admin.breadcrumbs_builder');
        }

        $parameters['admin'] = $parameters['admin'] ?? $this->admin;
        $parameters['base_template'] = $parameters['base_template'] ?? $this->getBaseTemplate();

        return $parameters;
    }

    /**
     * Render JSON.
     *
     * @param mixed $data
     *
     * @return JsonResponse with json encoded data
     */
    protected function renderJson($data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    /**
     * Returns true if the request is a XMLHttpRequest.
     *
     * @return bool True if the request is an XMLHttpRequest, false otherwise
     */
    protected function isXmlHttpRequest(): bool
    {
        $request = $this->getRequest();

        return $request->isXmlHttpRequest() || $request->get('_xml_http_request');
    }

    /**
     * Proxy for the logger service of the container.
     * If no such service is found, a NullLogger is returned.
     */
    protected function getLogger(): LoggerInterface
    {
        if ($this->has('logger')) {
            $logger = $this->get('logger');
            \assert($logger instanceof LoggerInterface);

            return $logger;
        }

        return new NullLogger();
    }

    /**
     * Returns the base template name.
     *
     * @return string The template name
     */
    protected function getBaseTemplate(): string
    {
        if ($this->isXmlHttpRequest()) {
            return $this->templateRegistry->getTemplate('ajax');
        }

        return $this->templateRegistry->getTemplate('layout');
    }

    /**
     * @throws \Exception
     */
    protected function handleModelManagerException(\Exception $e): void
    {
        if ($this->getParameter('kernel.debug')) {
            throw $e;
        }

        $context = ['exception' => $e];
        if ($e->getPrevious()) {
            $context['previous_exception_message'] = $e->getPrevious()->getMessage();
        }
        $this->getLogger()->error($e->getMessage(), $context);
    }

    /**
     * Redirect the user depend on this choice.
     *
     * @phpstan-param T $object
     */
    protected function redirectTo(object $object): RedirectResponse
    {
        $request = $this->getRequest();

        $url = false;

        if (null !== $request->get('btn_update_and_list')) {
            return $this->redirectToList();
        }
        if (null !== $request->get('btn_create_and_list')) {
            return $this->redirectToList();
        }

        if (null !== $request->get('btn_create_and_create')) {
            $params = [];
            if ($this->admin->hasActiveSubClass()) {
                $params['subclass'] = $request->get('subclass');
            }
            $url = $this->admin->generateUrl('create', $params);
        }

        if ('DELETE' === $request->getMethod()) {
            return $this->redirectToList();
        }

        if (!$url) {
            foreach (['edit', 'show'] as $route) {
                if ($this->admin->hasRoute($route) && $this->admin->hasAccess($route, $object)) {
                    $url = $this->admin->generateObjectUrl(
                        $route,
                        $object,
                        $this->getSelectedTab($request)
                    );

                    break;
                }
            }
        }

        if (!$url) {
            return $this->redirectToList();
        }

        return new RedirectResponse($url);
    }

    /**
     * Redirects the user to the list view.
     */
    final protected function redirectToList(): RedirectResponse
    {
        $parameters = [];

        if ($filter = $this->admin->getFilterParameters()) {
            $parameters['filter'] = $filter;
        }

        return $this->redirect($this->admin->generateUrl('list', $parameters));
    }

    /**
     * Returns true if the preview is requested to be shown.
     */
    protected function isPreviewRequested(): bool
    {
        $request = $this->getRequest();

        return null !== $request->get('btn_preview');
    }

    /**
     * Returns true if the preview has been approved.
     */
    protected function isPreviewApproved(): bool
    {
        $request = $this->getRequest();

        return null !== $request->get('btn_preview_approve');
    }

    /**
     * Returns true if the request is in the preview workflow.
     *
     * That means either a preview is requested or the preview has already been shown
     * and it got approved/declined.
     */
    protected function isInPreviewMode(): bool
    {
        return $this->admin->supportsPreviewMode()
        && ($this->isPreviewRequested()
            || $this->isPreviewApproved()
            || $this->isPreviewDeclined());
    }

    /**
     * Returns true if the preview has been declined.
     */
    protected function isPreviewDeclined(): bool
    {
        $request = $this->getRequest();

        return null !== $request->get('btn_preview_decline');
    }

    /**
     * Gets ACL users.
     */
    protected function getAclUsers(): \Traversable
    {
        if (!$this->has('sonata.admin.security.acl_user_manager')) {
            return new \ArrayIterator([]);
        }

        $aclUsers = $this->get('sonata.admin.security.acl_user_manager')->findUsers();

        return \is_array($aclUsers) ? new \ArrayIterator($aclUsers) : $aclUsers;
    }

    /**
     * Gets ACL roles.
     */
    protected function getAclRoles(): \Traversable
    {
        $aclRoles = [];
        $roleHierarchy = $this->getParameter('security.role_hierarchy.roles');
        $pool = $this->get('sonata.admin.pool');

        foreach ($pool->getAdminServiceIds() as $id) {
            try {
                $admin = $pool->getInstance($id);
            } catch (\Exception $e) {
                continue;
            }

            $baseRole = $admin->getSecurityHandler()->getBaseRole($admin);
            foreach ($admin->getSecurityInformation() as $role => $permissions) {
                $role = sprintf($baseRole, $role);
                $aclRoles[] = $role;
            }
        }

        foreach ($roleHierarchy as $name => $roles) {
            $aclRoles[] = $name;
            $aclRoles = array_merge($aclRoles, $roles);
        }

        $aclRoles = array_unique($aclRoles);

        return new \ArrayIterator($aclRoles);
    }

    /**
     * Validate CSRF token for action without form.
     *
     * @throws HttpException
     */
    protected function validateCsrfToken(string $intention): void
    {
        if (!$this->has('security.csrf.token_manager')) {
            return;
        }

        $request = $this->getRequest();
        $token = $request->get('_sonata_csrf_token');

        if (!$this->get('security.csrf.token_manager')->isTokenValid(new CsrfToken($intention, $token))) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, 'The csrf token is not valid, CSRF attack?');
        }
    }

    /**
     * Escape string for html output.
     */
    protected function escapeHtml(string $s): string
    {
        return htmlspecialchars((string) $s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Get CSRF token.
     */
    protected function getCsrfToken(string $intention): ?string
    {
        if (!$this->has('security.csrf.token_manager')) {
            return null;
        }

        return $this->get('security.csrf.token_manager')->getToken($intention)->getValue();
    }

    /**
     * This method can be overloaded in your custom CRUD controller.
     * It's called from createAction.
     *
     * @phpstan-param T $object
     */
    protected function preCreate(Request $request, object $object): ?Response
    {
        return null;
    }

    /**
     * This method can be overloaded in your custom CRUD controller.
     * It's called from editAction.
     *
     * @phpstan-param T $object
     */
    protected function preEdit(Request $request, object $object): ?Response
    {
        return null;
    }

    /**
     * This method can be overloaded in your custom CRUD controller.
     * It's called from deleteAction.
     *
     * @phpstan-param T $object
     */
    protected function preDelete(Request $request, object $object): ?Response
    {
        return null;
    }

    /**
     * This method can be overloaded in your custom CRUD controller.
     * It's called from showAction.
     *
     * @phpstan-param T $object
     */
    protected function preShow(Request $request, object $object): ?Response
    {
        return null;
    }

    /**
     * This method can be overloaded in your custom CRUD controller.
     * It's called from listAction.
     */
    protected function preList(Request $request): ?Response
    {
        return null;
    }

    /**
     * Translate a message id.
     */
    final protected function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $domain = $domain ?: $this->admin->getTranslationDomain();

        return $this->get('translator')->trans($id, $parameters, $domain, $locale);
    }

    protected function handleXmlHttpRequestErrorResponse(Request $request, FormInterface $form): ?JsonResponse
    {
        if (empty(array_intersect(['application/json', '*/*'], $request->getAcceptableContentTypes()))) {
            return $this->renderJson([], Response::HTTP_NOT_ACCEPTABLE);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        return $this->renderJson([
            'result' => 'error',
            'errors' => $errors,
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * @phpstan-param T $object
     */
    protected function handleXmlHttpRequestSuccessResponse(Request $request, object $object): JsonResponse
    {
        if (empty(array_intersect(['application/json', '*/*'], $request->getAcceptableContentTypes()))) {
            return $this->renderJson([], Response::HTTP_NOT_ACCEPTABLE);
        }

        return $this->renderJson([
            'result' => 'ok',
            'objectId' => $this->admin->getNormalizedIdentifier($object),
            'objectName' => $this->escapeHtml($this->admin->toString($object)),
        ], Response::HTTP_OK);
    }

    private function getSelectedTab(Request $request): array
    {
        return array_filter(['_tab' => $request->request->get('_tab')]);
    }

    /**
     * @phpstan-param T $object
     */
    private function checkParentChildAssociation(Request $request, object $object): void
    {
        if (!$this->admin->isChild()) {
            return;
        }

        $parentAdmin = $this->admin->getParent();
        $parentId = $request->get($parentAdmin->getIdParameter());

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $propertyPath = new PropertyPath($this->admin->getParentAssociationMapping());

        if ($parentAdmin->getObject($parentId) !== $propertyAccessor->getValue($object, $propertyPath)) {
            throw new \RuntimeException(sprintf(
                'There is no association between "%s" and "%s"',
                $parentAdmin->toString($parentAdmin->getObject($parentId)),
                $this->admin->toString($object)
            ));
        }
    }

    /**
     * Sets the admin form theme to form view. Used for compatibility between Symfony versions.
     */
    private function setFormTheme(FormView $formView, ?array $theme = null): void
    {
        $twig = $this->get('twig');

        $twig->getRuntime(FormRenderer::class)->setTheme($formView, $theme);
    }
}
