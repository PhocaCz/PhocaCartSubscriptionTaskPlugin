<?php
namespace Phocacart\Plugin\Task\Phocacartsubscription\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\UserHelper;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;

// Load Phoca Cart libraries
require_once JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/phocacart/subscription/subscription.php';
require_once JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/phocacart/subscription/email.php';

class Phocacartsubscription extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    protected const TASKS_MAP = [
        'phocacart.subscription.expiration' => [
            'langConstPrefix' => 'PLG_TASK_PHOCACARTSUBSCRIPTION_EXPIRATION',
            'method' => 'processExpirations',
        ],
        'phocacart.subscription.notifications' => [
            'langConstPrefix' => 'PLG_TASK_PHOCACARTSUBSCRIPTION_NOTIFICATIONS',
            'method' => 'sendNotifications',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask' => 'standardRoutineHandler',
        ];
    }

    public function processExpirations(ExecuteTaskEvent $event): int {


        $db = Factory::getContainer()->get('DatabaseDriver');

        // Find expired active subscriptions (using integer status constant)
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__phocacart_subscriptions'))
            ->where($db->quoteName('status') . ' = ' . \PhocacartSubscription::STATUS_ACTIVE)
            ->where($db->quoteName('end_date') . ' <= NOW()');
        $db->setQuery($query);
        $expiredSubscriptions = $db->loadObjectList();

        if (empty($expiredSubscriptions)) {
             return Status::OK;
        }

        $count = 0;
        $emailCount = 0;

        foreach ($expiredSubscriptions as $subscription) {
            $statusOld = (int)$subscription->status;

            // Update status to expired
            $subscription->status = \PhocacartSubscription::STATUS_EXPIRED;
            $db->updateObject('#__phocacart_subscriptions', $subscription, 'id');

            // Revoke access - get product's usergroup settings
            $productQuery = $db->getQuery(true)
                ->select('subscription_usergroup_add, subscription_usergroup_remove')
                ->from('#__phocacart_products')
                ->where('id = ' . (int)$subscription->product_id);
            $db->setQuery($productQuery);
            $product = $db->loadObject();

            if ($product) {
                // Remove the groups that were added during activation
                $addGroups = array_filter(explode(',', $product->subscription_usergroup_add ?? ''));
                foreach ($addGroups as $groupId) {
                    if ((int)$groupId > 0) {
                        UserHelper::removeUserFromGroup($subscription->user_id, (int)$groupId);
                        \PhocacartSubscription::logACL($subscription->id, $subscription->user_id, (int)$groupId, 'COM_PHOCACART_SUBSCRIPTION_ACL_ACTION_REMOVED');
                    }
                }

                // Re-add groups that were removed during activation
                $removeGroups = array_filter(explode(',', $product->subscription_usergroup_remove ?? ''));
                foreach ($removeGroups as $groupId) {
                    if ((int)$groupId > 0) {
                        UserHelper::addUserToGroup($subscription->user_id, (int)$groupId);
                        \PhocacartSubscription::logACL($subscription->id, $subscription->user_id, (int)$groupId, 'COM_PHOCACART_SUBSCRIPTION_ACL_ACTION_ADDED');
                    }
                }
            }

            $statusNew = \PhocacartSubscription::STATUS_EXPIRED;

            // Log history
            \PhocacartSubscription::logHistory($subscription->id, $statusNew, \PhocacartSubscription::EVENT_EXPIRED, 'cron_task_expiration_check', $statusOld, 1);
            // Send expiration notification email
            $result = \PhocacartSubscriptionEmail::sendEmail($subscription, $statusOld, $statusNew, \PhocacartSubscription::EVENT_EXPIRED, true, false, 'cron_task_expiration_check');

            if ($result === 1) {
                $emailCount++;
            }

            $count++;
        }

        $this->logTask(sprintf('Processed %d expired subscriptions, sent %d emails', $count, $emailCount));

        return Status::OK;
    }

    public function sendNotifications(ExecuteTaskEvent $event): int {

        $db = Factory::getContainer()->get('DatabaseDriver');
        $plugin = PluginHelper::getPlugin('system', 'phocacartsubscription');
        $params = new Registry($plugin->params ?? '');
        $daysBeforeExpiry = (int)$params->get('notification_days_before', 7);

        // Find subscriptions expiring soon that haven't been notified recently
        $query = $db->getQuery(true)
            ->select('s.*')
            ->from($db->quoteName('#__phocacart_subscriptions', 's'))
            ->where($db->quoteName('s.status') . ' = ' . \PhocacartSubscription::STATUS_ACTIVE)
            ->where($db->quoteName('s.end_date') . ' BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ' . $daysBeforeExpiry . ' DAY)')
            ->where('NOT EXISTS (
                SELECT 1 FROM ' . $db->quoteName('#__phocacart_subscription_history') . ' h
                WHERE h.subscription_id = s.id
                AND h.event_type = ' . $db->quote('email_sent_expiring_soon') . '
                AND h.event_date >= DATE_SUB(NOW(), INTERVAL ' . $daysBeforeExpiry . ' DAY)
            )');

        $db->setQuery($query);
        $subscriptions = $db->loadObjectList();

        $count = 0;
        $emailCount = 0;

        foreach ($subscriptions as $subscription) {


            // No status change
            $statusOld = $subscription->status;
            $statusNew  = $subscription->status;

            // Send expiring soon notification email
            $result = \PhocacartSubscriptionEmail::sendEmail($subscription, $statusOld, $statusNew, \PhocacartSubscription::EVENT_EXPIRING_SOON, true, false, 'cron_task_expiration_notification');

            if ($result === 1) {
                $emailCount++;
            }

            $count++;
        }

        $this->logTask(sprintf('Processed %d expiring subscriptions, sent %d notification emails', $count, $emailCount));

        return Status::OK;
    }
}
