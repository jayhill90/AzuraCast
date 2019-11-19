<?php
namespace App\Entity\Repository;

use App\Entity;
use App\Exception\MediaProcessingException;
use App\Radio\Filesystem;
use Azura\Doctrine\Repository;
use Azura\Settings;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMException;
use Exception;
use getID3;
use getid3_exception;
use getid3_writetags;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Serializer;
use voku\helper\UTF8;

class StationMediaRepository extends Repository
{
    /** @var Filesystem */
    protected $filesystem;

    /** @var SongRepository */
    protected $songRepo;

    /** @var CustomFieldRepository */
    protected $customFieldRepo;

    public function __construct(
        EntityManager $em,
        Serializer $serializer,
        Settings $settings,
        LoggerInterface $logger,
        Filesystem $filesystem,
        SongRepository $songRepo,
        CustomFieldRepository $customFieldRepo
    ) {
        $this->filesystem = $filesystem;
        $this->songRepo = $songRepo;
        $this->customFieldRepo = $customFieldRepo;

        parent::__construct($em, $serializer, $settings, $logger);
    }

    /**
     * @param mixed $id
     * @param Entity\Station $station
     *
     * @return Entity\StationMedia|null
     */
    public function find($id, Entity\Station $station): ?Entity\StationMedia
    {
        return $this->repository->findOneBy([
            'station' => $station,
            'id' => $id,
        ]);
    }

    /**
     * @param string $path
     * @param Entity\Station $station
     *
     * @return Entity\StationMedia|null
     */
    public function findByPath(string $path, Entity\Station $station): ?Entity\StationMedia
    {
        return $this->repository->findOneBy([
            'station' => $station,
            'path' => $path,
        ]);
    }

    /**
     * @param string $uniqueId
     * @param Entity\Station $station
     *
     * @return Entity\StationMedia|null
     */
    public function findByUniqueId(string $uniqueId, Entity\Station $station): ?Entity\StationMedia
    {
        return $this->repository->findOneBy([
            'station' => $station,
            'unique_id' => $uniqueId,
        ]);
    }

    /**
     * @param Entity\Station $station
     * @param string $tmp_path
     * @param string $dest
     *
     * @return Entity\StationMedia
     */
    public function uploadFile(Entity\Station $station, $tmp_path, $dest): Entity\StationMedia
    {
        [$dest_prefix, $dest_path] = explode('://', $dest, 2);

        $record = $this->repository->findOneBy([
            'station_id' => $station->getId(),
            'path' => $dest_path,
        ]);

        if (!($record instanceof Entity\StationMedia)) {
            $record = new Entity\StationMedia($station, $dest_path);
        }

        $this->loadFromFile($record, $tmp_path);

        $fs = $this->filesystem->getForStation($station);
        $fs->upload($tmp_path, $dest);

        $record->setMtime(time());

        $this->em->persist($record);
        $this->em->flush();

        return $record;
    }

    /**
     * Process metadata information from media file.
     *
     * @param Entity\StationMedia $media
     * @param null $file_path
     *
     * @throws getid3_exception
     */
    public function loadFromFile(Entity\StationMedia $media, $file_path = null): void
    {
        // Load metadata from supported files.
        $id3 = new getID3();

        $id3->option_md5_data = true;
        $id3->option_md5_data_source = true;
        $id3->encoding = 'UTF-8';

        $file_info = $id3->analyze($file_path);

        // Persist the media record for later custom field operations.
        $this->em->persist($media);

        // Report any errors found by the file analysis to the logs
        if (!empty($file_info['error'])) {
            $media_warning = 'Warning for uploaded media file "' . pathinfo($media->getPath(),
                    PATHINFO_FILENAME) . '": ' . json_encode($file_info['error']);
            $this->logger->error($media_warning);
        }

        // Set playtime length if the analysis was able to determine it
        if (is_numeric($file_info['playtime_seconds'])) {
            $media->setLength($file_info['playtime_seconds']);
        }

        $tagsToSet = [
            'title' => 'setTitle',
            'artist' => 'setArtist',
            'album' => 'setAlbum',
            'unsynchronized_lyric' => 'setLyrics',
            'isrc' => 'setIsrc',
        ];

        // Clear existing auto-assigned custom fields.
        foreach ($media->getCustomFields() as $existingCustomField) {
            /** @var Entity\StationMediaCustomField $existingCustomField */
            if ($existingCustomField->getField()->hasAutoAssign()) {
                $this->em->remove($existingCustomField);
            }
        }

        $customFieldsToSet = $this->customFieldRepo->getAutoAssignableFields();

        if (!empty($file_info['tags'])) {
            foreach ($file_info['tags'] as $tag_type => $tag_data) {
                foreach ($tagsToSet as $tag => $tagMethod) {
                    if (!empty($tag_data[$tag][0])) {
                        $tagValue = $this->cleanUpString($tag_data[$tag][0]);
                        $media->{$tagMethod}($tagValue);
                    }
                }

                foreach ($customFieldsToSet as $tag => $customFieldKey) {
                    if (!empty($tag_data[$tag][0])) {
                        $tagValue = $this->cleanUpString($tag_data[$tag][0]);

                        $customFieldRow = new Entity\StationMediaCustomField($media, $customFieldKey);
                        $customFieldRow->setValue($tagValue);
                        $this->em->persist($customFieldRow);
                    }
                }
            }
        }

        if (!empty($file_info['attached_picture'][0])) {
            $picture = $file_info['attached_picture'][0];
            $this->writeAlbumArt($media, $picture['data']);
        } elseif (!empty($file_info['comments']['picture'][0])) {
            $picture = $file_info['comments']['picture'][0];
            $this->writeAlbumArt($media, $picture['data']);
        }

        // Attempt to derive title and artist from filename.
        if (empty($media->getTitle())) {
            $filename = pathinfo($media->getPath(), PATHINFO_FILENAME);
            $filename = str_replace('_', ' ', $filename);

            $string_parts = explode('-', $filename);

            // If not normally delimited, return "text" only.
            if (1 === count($string_parts)) {
                $media->setTitle(trim($filename));
                $media->setArtist('');
            } else {
                $media->setTitle(trim(array_pop($string_parts)));
                $media->setArtist(trim(implode('-', $string_parts)));
            }
        }

        $media->setSong($this->songRepo->getOrCreate([
            'artist' => $media->getArtist(),
            'title' => $media->getTitle(),
        ]));
    }

    protected function cleanUpString(string $original): string
    {
        $string = UTF8::encode('UTF-8', $original);
        $string = UTF8::fix_simple_utf8($string);
        return UTF8::clean(
            $string,
            true,
            true,
            true,
            true,
            true
        );
    }

    /**
     * Crop album art and write the resulting image to storage.
     *
     * @param Entity\StationMedia $media
     * @param string $rawArtString The raw image data, as would be retrieved from file_get_contents.
     *
     * @return bool
     */
    public function writeAlbumArt(Entity\StationMedia $media, $rawArtString): bool
    {
        $source_image_info = getimagesizefromstring($rawArtString);
        $source_image_width = $source_image_info[0] ?? 0;
        $source_image_height = $source_image_info[1] ?? 0;
        $source_mime_type = $source_image_info['mime'] ?? 'unknown';

        $dest_max_width = 1200;
        $dest_max_height = 1200;

        $source_inside_dest = $source_image_width <= $dest_max_width && $source_image_height <= $dest_max_height;

        // Avoid GD entirely if it's already a JPEG within our parameters.
        if ($source_mime_type === 'image/jpeg' && $source_inside_dest) {
            $albumArt = $rawArtString;
        } else {
            $source_gd_image = imagecreatefromstring($rawArtString);

            if (!is_resource($source_gd_image)) {
                return false;
            }

            // Crop the raw art to a 1200x1200 artboard.
            if ($source_inside_dest) {
                $thumbnail_gd_image = $source_gd_image;
            } else {
                $source_aspect_ratio = $source_image_width / $source_image_height;
                $thumbnail_aspect_ratio = $dest_max_width / $dest_max_height;

                if ($thumbnail_aspect_ratio > $source_aspect_ratio) {
                    $thumbnail_image_width = (int)($dest_max_height * $source_aspect_ratio);
                    $thumbnail_image_height = $dest_max_height;
                } else {
                    $thumbnail_image_width = $dest_max_width;
                    $thumbnail_image_height = (int)($dest_max_width / $source_aspect_ratio);
                }

                $thumbnail_gd_image = imagecreatetruecolor($thumbnail_image_width, $thumbnail_image_height);
                imagecopyresampled($thumbnail_gd_image, $source_gd_image, 0, 0, 0, 0, $thumbnail_image_width,
                    $thumbnail_image_height, $source_image_width, $source_image_height);
            }

            ob_start();
            imagejpeg($thumbnail_gd_image, null, 90);
            $albumArt = ob_get_clean();

            imagedestroy($source_gd_image);
            imagedestroy($thumbnail_gd_image);
        }

        $fs = $this->filesystem->getForStation($media->getStation());
        $albumArtPath = $media->getArtPath();

        $media->setArtUpdatedAt(time());
        $this->em->persist($media);
        $this->em->flush($media);

        return $fs->put($albumArtPath, $albumArt);
    }

    public function removeAlbumArt(Entity\StationMedia $media): void
    {
        // Remove the album art, if it exists.
        $fs = $this->filesystem->getForStation($media->getStation());
        $currentAlbumArtPath = $media->getArtPath();

        $fs->delete($currentAlbumArtPath);

        $media->setArtUpdatedAt(0);
        $this->em->persist($media);
        $this->em->flush($media);
    }

    /**
     * @param Entity\Station $station
     * @param string $path
     *
     * @return Entity\StationMedia
     * @throws Exception
     */
    public function getOrCreate(Entity\Station $station, $path): Entity\StationMedia
    {
        if (strpos($path, '://') !== false) {
            [$path_prefix, $path] = explode('://', $path, 2);
        }

        $record = $this->repository->findOneBy([
            'station_id' => $station->getId(),
            'path' => $path,
        ]);

        $created = false;
        if (!($record instanceof Entity\StationMedia)) {
            $record = new Entity\StationMedia($station, $path);
            $created = true;
        }

        $processed = $this->processMedia($record);

        if ($created) {
            $this->em->persist($record);
            $this->em->flush($record);
        }

        return $record;
    }

    /**
     * Run media through the "processing" steps: loading from file and setting up any missing metadata.
     *
     * @param Entity\StationMedia $media
     * @param bool $force
     *
     * @return bool Whether reprocessing was required for this file.
     *
     * @throws ORMException
     * @throws getid3_exception
     */
    public function processMedia(Entity\StationMedia $media, $force = false): bool
    {
        $media_uri = $media->getPathUri();

        $fs = $this->filesystem->getForStation($media->getStation());
        if (!$fs->has($media_uri)) {
            throw new MediaProcessingException(sprintf('Media path "%s" not found.', $media_uri));
        }

        $media_mtime = $fs->getTimestamp($media_uri);

        // No need to update if all of these conditions are true.
        if (!$force && !$media->needsReprocessing($media_mtime)) {
            return false;
        }

        $tmp_uri = null;

        try {
            $tmp_path = $fs->getFullPath($media_uri);
        } catch (\InvalidArgumentException $e) {
            $tmp_uri = $fs->copyToTemp($media_uri);
            $tmp_path = $fs->getFullPath($tmp_uri);
        }

        $this->loadFromFile($media, $tmp_path);

        if (null !== $tmp_uri) {
            $fs->delete($tmp_uri);
        }

        $media->setMtime($media_mtime);
        $this->em->persist($media);

        return true;
    }

    /**
     * Write modified metadata directly to the file as ID3 information.
     *
     * @param Entity\StationMedia $media
     *
     * @return bool
     * @throws getid3_exception
     */
    public function writeToFile(Entity\StationMedia $media): bool
    {
        $fs = $this->filesystem->getForStation($media->getStation());

        $getID3 = new getID3;
        $getID3->setOption(['encoding' => 'UTF8']);

        $media_uri = $media->getPathUri();
        $tmp_uri = null;

        try {
            $tmp_path = $fs->getFullPath($media_uri);
        } catch (\InvalidArgumentException $e) {
            $tmp_uri = $fs->copyToTemp($media_uri);
            $tmp_path = $fs->getFullPath($tmp_uri);
        }

        $tagwriter = new getid3_writetags;
        $tagwriter->filename = $tmp_path;

        $tagwriter->tagformats = ['id3v1', 'id3v2.3'];
        $tagwriter->overwrite_tags = true;
        $tagwriter->tag_encoding = 'UTF8';
        $tagwriter->remove_other_tags = true;

        $tag_data = [
            'title' => [
                $media->getTitle(),
            ],
            'artist' => [
                $media->getArtist(),
            ],
            'album' => [
                $media->getAlbum(),
            ],
            'unsynchronized_lyric' => [
                $media->getLyrics(),
            ],
        ];

        $art_path = $media->getArtPath();
        if ($fs->has($art_path)) {
            $tag_data['attached_picture'][0] = [
                'encodingid' => 0, // ISO-8859-1; 3=UTF8 but only allowed in ID3v2.4
                'description' => 'cover art',
                'data' => $fs->read($art_path),
                'picturetypeid' => 0x03,
                'mime' => 'image/jpeg',
            ];

            $tag_data['comments']['picture'][0] = $tag_data['attached_picture'][0];
        }

        $tagwriter->tag_data = $tag_data;

        // write tags
        if ($tagwriter->WriteTags()) {
            $media->setMtime(time());

            if (null !== $tmp_uri) {
                $fs->updateFromTemp($tmp_uri, $media_uri);
            }
            return true;
        }

        return false;
    }

    /**
     * Read the contents of the album art from storage (if it exists).
     *
     * @param Entity\StationMedia $media
     *
     * @return string|null
     */
    public function readAlbumArt(Entity\StationMedia $media): ?string
    {
        $album_art_path = $media->getArtPath();
        $fs = $this->filesystem->getForStation($media->getStation());

        if (!$fs->has($album_art_path)) {
            return null;
        }

        return $fs->read($album_art_path);
    }

    /**
     * Return the full path associated with a media entity.
     *
     * @param Entity\StationMedia $media
     *
     * @return string
     */
    public function getFullPath(Entity\StationMedia $media): string
    {
        $fs = $this->filesystem->getForStation($media->getStation());

        $uri = $media->getPathUri();

        return $fs->getFullPath($uri);
    }
}
