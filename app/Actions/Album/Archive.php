<?php

namespace App\Actions\Album;

use App\Contracts\AbstractAlbum;
use App\Contracts\BaseAlbum;
use App\Facades\AccessControl;
use App\Facades\Helpers;
use App\Models\Album;
use App\Models\Configs;
use App\Models\Logs;
use App\Models\Photo;
use App\Models\TagAlbum;
use App\SmartAlbums\BaseSmartAlbum;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\Exception\FileNotFoundException;
use ZipStream\Exception\FileNotReadableException;
use ZipStream\ZipStream;

class Archive extends Action
{
	public const BAD_CHARS = [
		"\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07",
		"\x08", "\x09", "\x0a", "\x0b", "\x0c", "\x0d", "\x0e", "\x0f",
		"\x10", "\x11", "\x12", "\x13", "\x14", "\x15", "\x16", "\x17",
		"\x18", "\x19", "\x1a", "\x1b", "\x1c", "\x1d", "\x1e", "\x1f",
		'<', '>', ':', '"', '/', '\\', '|', '?', '*',
	];

	/**
	 * @param array $albumIDs
	 *
	 * @return StreamedResponse
	 */
	public function do(array $albumIDs): StreamedResponse
	{
		$albums = $this->albumFactory->findWhereIDsIn($albumIDs);

		$response = new StreamedResponse(function () use ($albums) {
			$options = new \ZipStream\Option\Archive();
			$options->setEnableZip64(Configs::get_value('zip64', '1') === '1');
			$zip = new ZipStream(null, $options);

			$usedDirNames = [];
			foreach ($albums as $album) {
				$this->compressAlbum($album, $usedDirNames, null, $zip);
			}

			// finish the zip stream
			$zip->finish();
		});

		// Set file type and destination
		$zipTitle = self::createZipTitle($albums);
		$disposition = HeaderUtils::makeDisposition(
			HeaderUtils::DISPOSITION_ATTACHMENT,
			$zipTitle . '.zip',
			mb_check_encoding($zipTitle, 'ASCII') ? '' : 'Album.zip'
		);
		$response->headers->set('Content-Type', 'application/x-zip');
		$response->headers->set('Content-Disposition', $disposition);

		// Disable caching
		$response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
		$response->headers->set('Pragma', 'no-cache');
		$response->headers->set('Expires', '0');

		return $response;
	}

	/**
	 * Create the title of the ZIP archive.
	 */
	private static function createZipTitle(Collection $albums): string
	{
		return $albums->containsOneItem() ?
			self::createValidTitle($albums->first()->title) :
			'Albums';
	}

	/**
	 * Creates a title which only contains valid characters.
	 *
	 * Removes all invalid characters from the given title.
	 * If the title happens to become the empty string after removing all
	 * illegal characters, the fixed string 'Untitled'  is returned.
	 *
	 * @param string $title the title with possibly invalid characters
	 *
	 * @return string the title without any invalid characters
	 */
	private static function createValidTitle(string $title): string
	{
		return str_replace(self::BAD_CHARS, '', $title) ?? 'Untitled';
	}

	/**
	 * Returns a unique string.
	 *
	 * Returns the input value `$str` possibly augmented by a counter
	 * suffix `-<n>` such that the returned value is not contained in the
	 * input array `$used`.
	 * The method adds the return value to `$used`.
	 *
	 * @param string $str  the input string which shall be made unique
	 * @param array  $used an input array of previously used strings;
	 *                     the output array will contain the result value
	 *
	 * @return string the unique string
	 */
	private function makeUnique(string $str, array &$used): string
	{
		if (!empty($used)) {
			$i = 1;
			$tmp = $str;
			while (in_array($tmp, $used)) {
				$tmp = $str . '-' . $i;
				$i++;
			}
			$str = $tmp;
		}
		$used[] = $str;

		return $str;
	}

	/**
	 * Compresses an album recursively.
	 *
	 * @param AbstractAlbum $album            the album which shall be added
	 *                                        to the archive
	 * @param array         $usedDirNames     the list of already used
	 *                                        directory names on the same level
	 *                                        as `$album`
	 *                                        ("siblings" of `$album`)
	 * @param string|null   $fullNameOfParent the fully qualified path name
	 *                                        of the parent directory
	 * @param ZipStream     $zip              the archive
	 *
	 * @throws FileNotFoundException
	 * @throws FileNotReadableException
	 */
	private function compressAlbum(AbstractAlbum $album, array &$usedDirNames, ?string $fullNameOfParent, ZipStream $zip): void
	{
		if (!self::isArchivable($album)) {
			return;
		}

		$fullNameOfDirectory = $this->makeUnique(self::createValidTitle($album->title), $usedDirNames);
		if (!empty($fullNameOfParent)) {
			$fullNameOfDirectory = $fullNameOfParent . '/' . $fullNameOfDirectory;
		}

		$usedFileNames = [];
		$photos = $album->photos;

		/** @var Photo $photo */
		foreach ($photos as $photo) {
			// For photos in smart or tag albums, skip the ones that are not
			// downloadable based on their actual parent album.  The test for
			// album_id == null shouldn't really be needed as all such photos
			// in smart albums should be owned by the current user...
			if (($album instanceof BaseSmartAlbum || $album instanceof TagAlbum) &&
			!AccessControl::is_current_user($photo->owner_id) &&
			!($photo->album_id == null ? $album->is_downloadable : $photo->album->is_downloadable)) {
				continue;
			}

			$fullPath = $photo->size_variants->getOriginal()->full_path;
			// Check if readable
			if (!@is_readable($fullPath)) {
				Logs::error(__METHOD__, __LINE__, 'Original photo missing: ' . $fullPath);
				continue;
			}

			// Set title for photo
			$extension = Helpers::getExtension($fullPath, false);
			$fileBaseName = $this->makeUnique(self::createValidTitle($photo->title), $usedFileNames);
			$fileName = $fullNameOfDirectory . '/' . $fileBaseName . $extension;

			// Reset the execution timeout for every iteration.
			set_time_limit(ini_get('max_execution_time'));
			$zip->addFileFromPath($fileName, $fullPath);
		}

		// Recursively compress sub-albums
		if ($album instanceof Album) {
			$subDirs = [];
			$subAlbums = $album->children;
			foreach ($subAlbums as $subAlbum) {
				$this->compressAlbum($subAlbum, $subDirs, $fullNameOfDirectory, $zip);
			}
		}
	}

	/**
	 * Tests whether the given album may be archived by the current user.
	 *
	 * @param AbstractAlbum $album
	 *
	 * @return bool
	 */
	private static function isArchivable(AbstractAlbum $album): bool
	{
		return
			$album->is_downloadable ||
			($album instanceof BaseSmartAlbum && AccessControl::is_logged_in()) ||
			($album instanceof BaseAlbum && AccessControl::is_current_user($album->owner_id));
	}
}
