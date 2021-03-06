<?php

    namespace thebuggenie\core\modules\installation\upgrade_32;

    use thebuggenie\core\entities\tables\ScopedTable;

    /**
     * Notifications table
     *
     * @author Daniel Andre Eikeland <zegenie@zegeniestudios.net>
     * @version 3.1
     * @license http://opensource.org/licenses/MPL-2.0 Mozilla Public License 2.0 (MPL 2.0)
     * @package thebuggenie
     * @subpackage tables
     */

    /**
     * Notifications table
     *
     * @package thebuggenie
     * @subpackage tables
     *
     * @Table(name="notifications_32")
     */
    class TBGNotificationsTable extends ScopedTable
    {

        const B2DBNAME = 'notifications';
        const ID = 'notifications.id';
        const SCOPE = 'notifications.scope';
        const MODULE_NAME = 'notifications.module_name';
        const NOTIFY_TYPE = 'notifications.notify_type';
        const TARGET_ID = 'notifications.target_id';
        const UID = 'notifications.uid';
        const GID = 'notifications.gid';
        const TID = 'notifications.tid';
        const TITLE = 'notifications.title';
        const CONTENTS = 'notifications.contents';
        const STATUS = 'notifications.status';

        protected function initialize()
        {
            parent::setup(self::B2DBNAME, self::ID);
            parent::addVarchar(self::MODULE_NAME, 50);
            parent::addInteger(self::NOTIFY_TYPE, 5);
            parent::addInteger(self::TARGET_ID, 10);
            parent::addVarchar(self::TITLE, 100);
            parent::addText(self::CONTENTS, false);
            parent::addInteger(self::STATUS, 5);
            parent::addInteger(self::UID, 10);
            parent::addInteger(self::GID, 10);
            parent::addInteger(self::TID, 10);
            parent::addInteger(self::SCOPE, 10);
        }

    }
