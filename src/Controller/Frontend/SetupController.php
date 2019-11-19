<?php
namespace App\Controller\Frontend;

use App\Acl;
use App\Auth;
use App\Entity;
use App\Exception\NotLoggedInException;
use App\Form\Form;
use App\Form\StationForm;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Settings;
use Azura\Config;
use Azura\Session\Flash;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;

class SetupController
{
    /** @var EntityManager */
    protected $em;

    /** @var Entity\Repository\SettingsRepository */
    protected $settings_repo;

    /** @var Auth */
    protected $auth;

    /** @var Acl */
    protected $acl;

    /** @var StationForm */
    protected $station_form;

    /** @var array */
    protected $settings_form_config;

    /** @var Settings */
    protected $settings;

    /**
     * @param EntityManager $em
     * @param Auth $auth
     * @param Acl $acl
     * @param StationForm $station_form
     * @param Config $config
     * @param Settings $settings
     */
    public function __construct(
        EntityManager $em,
        Entity\Repository\SettingsRepository $settingsRepository,
        Auth $auth,
        Acl $acl,
        StationForm $station_form,
        Config $config,
        Settings $settings
    ) {
        $this->em = $em;
        $this->settings_repo = $settingsRepository;
        $this->auth = $auth;
        $this->acl = $acl;
        $this->station_form = $station_form;
        $this->settings_form_config = $config->get('forms/settings');
        $this->settings = $settings;
    }

    /**
     * Setup Routing Controls
     *
     * @param ServerRequest $request
     * @param Response $response
     *
     * @return ResponseInterface
     */
    public function indexAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $current_step = $this->_getSetupStep();
        return $response->withRedirect($request->getRouter()->named('setup:' . $current_step));
    }

    /**
     * Determine which step of setup is currently active.
     *
     * @return string
     * @throws NotLoggedInException
     */
    protected function _getSetupStep(): string
    {
        if (0 !== (int)$this->settings_repo->getSetting(Entity\Settings::SETUP_COMPLETE, 0)) {
            return 'complete';
        }

        // Step 1: Register
        $num_users = (int)$this->em->createQuery(/** @lang DQL */ 'SELECT COUNT(u.id) FROM App\Entity\User u')->getSingleScalarResult();
        if (0 === $num_users) {
            return 'register';
        }

        // If past "register" step, require login.
        if (!$this->auth->isLoggedIn()) {
            throw new NotLoggedInException;
        }

        // Step 2: Set up Station
        $num_stations = (int)$this->em->createQuery(/** @lang DQL */ 'SELECT COUNT(s.id) FROM App\Entity\Station s')->getSingleScalarResult();
        if (0 === $num_stations) {
            return 'station';
        }

        // Step 3: System Settings
        return 'settings';
    }

    /**
     * Placeholder function for "setup complete" redirection.
     *
     * @param ServerRequest $request
     * @param Response $response
     *
     * @return ResponseInterface
     */
    public function completeAction(ServerRequest $request, Response $response): ResponseInterface
    {
        $request->getFlash()->addMessage('<b>' . __('Setup has already been completed!') . '</b>', Flash::ERROR);

        return $response->withRedirect($request->getRouter()->named('dashboard'));
    }

    /**
     * Setup Step 1:
     * Create Super Administrator Account
     *
     * @param ServerRequest $request
     * @param Response $response
     *
     * @return ResponseInterface
     */
    public function registerAction(ServerRequest $request, Response $response): ResponseInterface
    {
        // Verify current step.
        $current_step = $this->_getSetupStep();
        if ($current_step !== 'register' && $this->settings->isProduction()) {
            return $response->withRedirect($request->getRouter()->named('setup:' . $current_step));
        }

        // Create first account form.
        if (!empty($_POST['username']) && !empty($_POST['password'])) {
            $data = $_POST;

            // Create actions and roles supporting Super Admninistrator.
            $role = new Entity\Role;
            $role->setName(__('Super Administrator'));

            $this->em->persist($role);
            $this->em->flush();

            $rha = new Entity\RolePermission($role);
            $rha->setActionName('administer all');

            $this->em->persist($rha);

            // Create user account.
            $user = new Entity\User;
            $user->setEmail($data['username']);
            $user->setNewPassword($data['password']);
            $user->getRoles()->add($role);
            $this->em->persist($user);

            // Write to DB.
            $this->em->flush();

            // Log in the newly created user.
            $this->auth->authenticate($data['username'], $data['password']);
            $this->acl->reload();

            return $response->withRedirect($request->getRouter()->named('setup:index'));
        }

        return $request->getView()
            ->renderToResponse($response, 'frontend/setup/register');
    }

    /**
     * Setup Step 2:
     * Create Station and Parse Metadata
     *
     * @param ServerRequest $request
     * @param Response $response
     *
     * @return ResponseInterface
     */
    public function stationAction(ServerRequest $request, Response $response): ResponseInterface
    {
        // Verify current step.
        $current_step = $this->_getSetupStep();
        if ($current_step !== 'station' && $this->settings->isProduction()) {
            return $response->withRedirect($request->getRouter()->named('setup:' . $current_step));
        }

        if (false !== $this->station_form->process($request)) {
            return $response->withRedirect($request->getRouter()->named('setup:settings'));
        }

        return $request->getView()->renderToResponse($response, 'frontend/setup/station', [
            'form' => $this->station_form,
        ]);
    }

    /**
     * Setup Step 3:
     * Set site settings.
     *
     * @param ServerRequest $request
     * @param Response $response
     *
     * @return ResponseInterface
     */
    public function settingsAction(ServerRequest $request, Response $response): ResponseInterface
    {
        // Verify current step.
        $current_step = $this->_getSetupStep();
        if ($current_step !== 'settings' && $this->settings->isProduction()) {
            return $response->withRedirect($request->getRouter()->named('setup:' . $current_step));
        }

        $form = new Form($this->settings_form_config);

        $existing_settings = $this->settings_repo->fetchArray(false);
        $form->populate($existing_settings);

        if ($request->getMethod() === 'POST' && $form->isValid($_POST)) {
            $data = $form->getValues();

            // Mark setup as complete along with other settings changes.
            $data['setup_complete'] = time();

            $this->settings_repo->setSettings($data);

            // Notify the user and redirect to homepage.
            $request->getFlash()->addMessage('<b>' . __('Setup is now complete!') . '</b><br>' . __('Continue setting up your station in the main AzuraCast app.'),
                Flash::SUCCESS);

            return $response->withRedirect($request->getRouter()->named('dashboard'));
        }

        return $request->getView()->renderToResponse($response, 'frontend/setup/settings', [
            'form' => $form,
        ]);
    }
}
