<?php
namespace App\Console\Command;

use App\Settings;
use Azura\Console\Command\CommandAbstract;
use Gettext\Translations;
use Symfony\Component\Console\Style\SymfonyStyle;

class LocaleImportCommand extends CommandAbstract
{
    public function __invoke(
        SymfonyStyle $io,
        Settings $settings
    ) {
        $locales = $settings['locale']['supported'];
        $locale_base = $settings[Settings::BASE_DIR] . '/resources/locale';

        foreach ($locales as $locale_key => $locale_name) {

            $locale_source = $locale_base . '/' . $locale_key . '/LC_MESSAGES/default.po';

            if (file_exists($locale_source)) {
                /** @var Translations $translations */
                $translations = Translations::fromPoFile($locale_source);

                $locale_dest = $locale_base . '/compiled/' . $locale_key . '.php';
                $translations->toPhpArrayFile($locale_dest);

                $io->writeln(__('Imported locale: %s', $locale_key . ' (' . $locale_name . ')'));
            }
        }

        $io->writeln(__('Locales imported.'));
        return 0;
    }
}
