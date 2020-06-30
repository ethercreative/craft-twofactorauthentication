<?php
namespace born05\twofactorauthentication;

use born05\twofactorauthentication\widgets\Notify as NotifyWidget;

use Craft;
use born05\twofactorauthentication\services\Request as RequestService;
use born05\twofactorauthentication\services\Response as ResponseService;
use born05\twofactorauthentication\services\Verify as VerifyService;
use born05\twofactorauthentication\models\Settings;
use born05\twofactorauthentication\Variables;
use craft\base\Plugin as CraftPlugin;
use craft\base\Element;
use craft\elements\User;
use craft\services\Dashboard;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\SetElementTableAttributeHtmlEvent;
use craft\services\Plugins;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use yii\base\Event;
use yii\web\UserEvent;

class Plugin extends CraftPlugin
{
    /**
     * @var string
     */
    public $schemaVersion = '2.0.0';

    /**
     * @var bool
     */
    public $hasCpSection = true;

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * Plugin::$plugin
     *
     * @var Plugin
     */
    public static $plugin;

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        Event::on(Plugins::class, Plugins::EVENT_AFTER_LOAD_PLUGINS, function (Event $event) {
            $request = Craft::$app->getRequest();
            $response = Craft::$app->getResponse();

            if (!$this->isInstalled || $request->getIsConsoleRequest()) {
                return;
            }

            // Register Components (Services)
            $this->setComponents([
                'request' => RequestService::class,
                'response' => ResponseService::class,
                'verify' => VerifyService::class,
            ]);

            $this->request->validateRequest();

            // Verify after login.
            Event::on(\craft\web\User::class, \craft\web\User::EVENT_AFTER_LOGIN, function (UserEvent $event) {
                $this->request->userLoginEventHandler($event);
            });

            Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('twoFactorAuthentication', Variables::class);
            });

            Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
                $event->rules['two-factor-authentication'] = 'two-factor-authentication/settings/index';
            });

            // Register our widgets
            Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function (RegisterComponentTypesEvent $event) {
                $event->types[] = NotifyWidget::class;
            });

            /**
             * Adds the following attributes to the User table in the CMS
             * NOTE: You still need to select them with the 'gear'
             */
            Event::on(User::class, Element::EVENT_REGISTER_TABLE_ATTRIBUTES, function (RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['hasTwoFactorAuthentication'] = ['label' => Craft::t('two-factor-authentication', '2-Factor Auth')];
            });

            /**
             * Returns the content for the additional attributes field
             */
            Event::on(User::class, Element::EVENT_SET_TABLE_ATTRIBUTE_HTML, function (SetElementTableAttributeHtmlEvent $event) {
                $currentUser = Craft::$app->getUser()->getIdentity();
                if ($event->attribute == 'hasTwoFactorAuthentication' && $currentUser->admin) {
                    /** @var User $user */
                    $user = $event->sender;

                    if (Plugin::$plugin->verify->isEnabled($user)) {
                        $event->html = '<div class="status enabled" title="' . Craft::t('two-factor-authentication', 'Enabled') . '"></div>';
                    } else {
                        $event->html = '<div class="status" title="' . Craft::t('two-factor-authentication', 'Not enabled') . '"></div>';
                    }

                    // Prevent other event listeners from getting invoked
                    $event->handled = true;
                }
            });

            /**
             * Hook into the users cp page.
             */
            Craft::$app->view->hook('cp.users.edit.details', function (array &$context) {
                if (Craft::$app->getUser()->getIsAdmin() && !$context['isNewUser']) {
                    /** @var User $user */
                    $user = $context['user'];

                    return Craft::$app->getView()->renderTemplate('two-factor-authentication/_user/status', [
                        'user' => $user,
                        'enabled' => Plugin::$plugin->verify->isEnabled($user),
                    ]);
                }
            });
        });
    }

    protected function createSettingsModel()
    {
        return new Settings();
    }
}
