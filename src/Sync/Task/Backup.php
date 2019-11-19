<?php
namespace App\Sync\Task;

use App\Entity;
use App\Message;
use App\MessageQueue;
use Azura\Console\Application;
use Azura\Logger;
use Cake\Chronos\Chronos;
use Doctrine\ORM\EntityManager;

class Backup extends AbstractTask
{
    public const BASE_DIR = '/var/azuracast/backups';

    /** @var MessageQueue */
    protected $messageQueue;

    /** @var Application */
    protected $console;

    /**
     * @param EntityManager $em
     * @param Entity\Repository\SettingsRepository $settingsRepo
     * @param MessageQueue $message_queue
     * @param Application $console
     */
    public function __construct(
        EntityManager $em,
        Entity\Repository\SettingsRepository $settingsRepo,
        MessageQueue $message_queue,
        Application $console
    ) {
        parent::__construct($em, $settingsRepo);

        $this->messageQueue = $message_queue;
        $this->console = $console;
    }

    /**
     * Handle event dispatch.
     *
     * @param Message\AbstractMessage $message
     */
    public function __invoke(Message\AbstractMessage $message)
    {
        if ($message instanceof Message\BackupMessage) {

            [$result_code, $result_output] = $this->runBackup(
                $message->path,
                $message->exclude_media
            );

            $this->settingsRepo->setSetting(Entity\Settings::BACKUP_LAST_RUN, time());
            $this->settingsRepo->setSetting(Entity\Settings::BACKUP_LAST_RESULT, $result_code);
            $this->settingsRepo->setSetting(Entity\Settings::BACKUP_LAST_OUTPUT, $result_output);
        }
    }

    /**
     * @param string|null $path
     * @param bool $exclude_media
     *
     * @return array [$result_code, $result_output]
     */
    public function runBackup($path = null, $exclude_media = false): array
    {
        $input_params = [];
        if (null !== $path) {
            $input_params['path'] = $path;
        }
        if ($exclude_media) {
            $input_params['--exclude-media'] = true;
        }

        return $this->console->runCommandWithArgs('azuracast:backup', $input_params);
    }

    /**
     * @inheritdoc
     */
    public function run($force = false): void
    {
        $backup_enabled = (bool)$this->settingsRepo->getSetting(Entity\Settings::BACKUP_ENABLED, 0);
        if (!$backup_enabled) {
            Logger::getInstance()->debug('Automated backups disabled; skipping...');
            return;
        }

        $now_utc = Chronos::now('UTC');

        $threshold = $now_utc->subDay()->getTimestamp();
        $last_run = $this->settingsRepo->getSetting(Entity\Settings::BACKUP_LAST_RUN, 0);

        if ($last_run <= $threshold) {
            // Check if the backup time matches (if it's set).
            $backup_timecode = (int)$this->settingsRepo->getSetting(Entity\Settings::BACKUP_TIME);
            if (0 !== $backup_timecode) {
                $current_timecode = (int)$now_utc->format('Hi');

                if ($backup_timecode !== $current_timecode) {
                    return;
                }
            }

            // Trigger a new backup.
            $message = new Message\BackupMessage;
            $message->path = 'automatic_backup.zip';
            $message->exclude_media = (bool)$this->settingsRepo->getSetting(Entity\Settings::BACKUP_EXCLUDE_MEDIA, 0);
            $this->messageQueue->produce($message);
        }
    }
}
