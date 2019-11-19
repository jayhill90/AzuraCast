<?php
namespace App\Form;

use App\Entity;
use App\Http\ServerRequest;
use App\Settings;
use Azura\Config;
use AzuraForms\Field\AbstractField;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserProfileForm extends EntityForm
{
    /**
     * @param EntityManager $em
     * @param Serializer $serializer
     * @param ValidatorInterface $validator
     * @param Config $config
     * @param Settings $settings
     */
    public function __construct(
        EntityManager $em,
        Serializer $serializer,
        ValidatorInterface $validator,
        Config $config,
        Settings $settings
    ) {
        $form_config = $config->get('forms/profile', [
            'settings' => $settings,
        ]);
        parent::__construct($em, $serializer, $validator, $form_config);

        $this->entityClass = Entity\User::class;
    }

    /**
     * @inheritdoc
     */
    public function process(ServerRequest $request, $record = null)
    {
        $user = $request->getUser();

        $this->getField('password')->addValidator(function ($val, AbstractField $field) use ($user) {
            $form = $field->getForm();

            $new_password = $form->getField('new_password')->getValue();
            if (!empty($new_password)) {
                if ($user->verifyPassword($val)) {
                    return true;
                }

                return 'Current password could not be verified.';
            }

            return true;
        });

        return parent::process($request, $user);
    }

    public function switchTheme(ServerRequest $request): void
    {
        $user = $request->getUser();

        $themeField = $this->getField('theme');

        $themeFieldOptions = $themeField->getOptions();
        $themeOptions = array_keys($themeFieldOptions['choices']);

        $currentTheme = $user->getTheme();
        if (empty($currentTheme)) {
            $currentTheme = $themeFieldOptions['default'];
        }

        foreach ($themeOptions as $theme) {
            if ($theme !== $currentTheme) {
                $user->setTheme($theme);
                break;
            }
        }

        $this->em->persist($user);
        $this->em->flush($user);
    }

    public function getView(ServerRequest $request): string
    {
        $user = $request->getUser();

        $viewForm = new Form($this->options['groups']['customization'], $this->_normalizeRecord($user));
        return $viewForm->renderView();
    }
}
