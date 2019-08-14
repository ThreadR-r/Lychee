<?php

namespace Lychee\Modules;

use ZipArchive;

final class Album {

	private $albumIDs = null;

	/**
	 * @return boolean Returns true when successful.
	 */
	public function __construct($albumIDs) {

		// Init vars
		$this->albumIDs = $albumIDs;

		return true;

	}

	/**
	 * @return string|false ID of the created album.
	 */
	public function add($title = 'Untitled', $use_existing = false, $parent = -1) {

		// Check if album exists
		if ($use_existing) {
			$exists = $this->exists($title);
			if ($exists!==false) {
				return $exists;
			}
		}

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		// Properties
		$id       = generateID();
		$sysstamp = time();
		$min_takestamp = 0;
		$max_takestamp = 0;
		$public   = 0;
		$visible  = 1;

		// Database
		$query  = Database::prepare(Database::get(), "INSERT INTO ? (id, title, sysstamp, min_takestamp, max_takestamp, public, visible, license, parent) VALUES ('?', '?', '?', '?', '?', '?', '?', '?', '?')", array(LYCHEE_TABLE_ALBUMS, $id, $title, $sysstamp, $min_takestamp, $max_takestamp, $public, $visible, 'none', $parent));
		$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		if ($result===false) return false;
		return $id;

	}

	/**
	 * Rurns album-attributes into a front-end friendly format. Note that some attributes remain unchanged.
	 * @return array Returns album-attributes in a normalized structure.
	 */
	public static function prepareData(array $data) {

		// This function requires the following album-attributes and turns them
		// into a front-end friendly format: id, title, public, sysstamp, password, license
		// Note that some attributes remain unchanged

		// Init
		$album = null;

		// Set unchanged attributes
		$album['id']     = $data['id'];
		$album['title']  = $data['title'];
		$album['parent_id'] = $data['parent'];

		// Additional attributes
		// Only part of $album when available
		if (isset($data['description']))  $album['description'] = $data['description'];
		if (isset($data['visible']))      $album['visible'] = $data['visible'];
		if (isset($data['downloadable'])) $album['downloadable'] = $data['downloadable'];
		if (isset($data['public']))       $album['public'] = $data['public'];
		$album['license'] = Settings::get()['default_license'];

		if (isset($data['license']))
		{
			if($data['license'] != '' && $data['license'] != 'none')
			{
				$album['license'] = $data['license'];
			}
		}

		// Parse date
		$album['sysdate'] = strftime('%B %Y', $data['sysstamp']);

		if ($data['min_takestamp']!=="0") {
            $album['min_takestamp'] = strftime('%B %Y', $data['min_takestamp']);
        }
        else {
		    $album['min_takestamp'] = "";
        }
        if ($data['max_takestamp']!=="0") {
            $album['max_takestamp'] = strftime('%B %Y', $data['max_takestamp']);
        }
        else {
            $album['max_takestamp'] = "";
        }
		// Parse password
		$album['password'] = ($data['password']=='' ? '0' : '1');

		// Parse thumbs or set default value
		$album['thumbs'] = (isset($data['thumbs']) ? explode(',', $data['thumbs']) : array());
		$album['types'] = (isset($data['types']) ? explode(',', $data['types']) : array());

		return $album;

	}

	/**
	 * @return array|false Returns an array of photos and album information or false on failure.
	 */
	public function get() {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		// Get album information
		switch ($this->albumIDs) {

			case 'f':
				$return['public'] = '0';
				$query = Database::prepare(Database::get(), "SELECT id, title, tags, public, star, album, thumbUrl, takestamp, url, medium, small, size, type, width, height, license  FROM ? WHERE star = 1 " . Settings::get()['sortingPhotos'], array(LYCHEE_TABLE_PHOTOS));
				break;

			case 's':
				$return['public'] = '0';
				$query = Database::prepare(Database::get(), "SELECT id, title, tags, public, star, album, thumbUrl, takestamp, url, medium, small, size, type, width, height, license  FROM ? WHERE public = 1 " . Settings::get()['sortingPhotos'], array(LYCHEE_TABLE_PHOTOS));
				break;

			case 'r':
				$return['public'] = '0';
				$query = Database::prepare(Database::get(), "SELECT id, title, tags, public, star, album, thumbUrl, takestamp, url, medium, small, size, type, width, height, license  FROM ? WHERE LEFT(id, 10) >= unix_timestamp(DATE_SUB(NOW(), INTERVAL 1 DAY)) " . Settings::get()['sortingPhotos'], array(LYCHEE_TABLE_PHOTOS));
				break;

			case '0':
				$return['public'] = '0';
				$query = Database::prepare(Database::get(), "SELECT id, title, tags, public, star, album, thumbUrl, takestamp, url, medium, small, size, type, width, height, license  FROM ? WHERE album = 0 " . Settings::get()['sortingPhotos'], array(LYCHEE_TABLE_PHOTOS));
				break;

			default:
				$query  = Database::prepare(Database::get(), "SELECT * FROM ? WHERE id = '?' LIMIT 1", array(LYCHEE_TABLE_ALBUMS, $this->albumIDs));
				$albums = Database::execute(Database::get(), $query, __METHOD__, __LINE__);
				$return = $albums->fetch_assoc();
				$return = Album::prepareData($return);
				$query  = Database::prepare(Database::get(), "SELECT id, title, tags, public, star, album, thumbUrl, takestamp, url, medium, small, size, type, width, height, license FROM ? WHERE album = '?' " . Settings::get()['sortingPhotos'], array(LYCHEE_TABLE_PHOTOS, $this->albumIDs));
				break;

		}

		// Get photos
		$photos          = Database::execute(Database::get(), $query, __METHOD__, __LINE__);
		$previousPhotoID = '';

		// Get albums
		$albums = new Albums();
		$albums = $albums->get(false, $this->albumIDs)['albums'];
		$return['albums'] = $albums;

		if ($photos===false) return false;

		$return['photos'] = array();
		$photo_counter = 0;
		while ($photo = $photos->fetch_assoc()) {

			// Turn data from the database into a front-end friendly format
			$photo = Photo::prepareData($photo);

			// Set previous and next photoID for navigation purposes
			$photo['previousPhoto'] = $previousPhotoID;
			$photo['nextPhoto']     = '';

			// Set current photoID as nextPhoto of previous photo
			if ($previousPhotoID!=='') $return['photos'][$photo_counter - 1]['nextPhoto'] = $photo['id'];
			$previousPhotoID = $photo['id'];

			// Add to return
			$return['photos'][$photo_counter] = $photo;
			$photo_counter ++;
		}

		if ($photos->num_rows===0) {

			// Album empty
			$return['photos'] = false;
			$return['photos'] = false;

		} else {

			// Enable next and previous for the first and last photo
			$lastElement    = end($return['photos']);
			$lastElementId  = $lastElement['id'];
			$firstElement   = reset($return['photos']);
			$firstElementId = $firstElement['id'];

			if ($lastElementId!==$firstElementId) {
				$return['photos'][$photo_counter - 1]['nextPhoto']      = $firstElementId;
				$return['photos'][0]['previousPhoto'] = $lastElementId;
			}

		}

		$return['id']  = $this->albumIDs;
		$return['num'] = $photos->num_rows;

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		return $return;

	}

	/**
	 * Starts a download of an album.
	 * @return resource|boolean Sends a ZIP-file or returns false on failure.
	 */
	public function getArchive() {
		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		// determine Zip title according to albumID
		switch($this->albumIDs) {
			case 's':
				$zipTitle = 'Public';
				break;
			case 'f':
				$zipTitle = 'Starred';
				break;
			case 'r':
				$zipTitle = 'Recent';
				break;
			default:
				$zipTitle = 'Unsorted';
		}

		// Get title from database when album is not a SmartAlbum
		if ($this->albumIDs!=0&&is_numeric($this->albumIDs)) {

			$query = Database::prepare(Database::get(), "SELECT title FROM ? WHERE id = '?' LIMIT 1", array(LYCHEE_TABLE_ALBUMS, $this->albumIDs));
			$album = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

			if ($album===false) return false;

			// Get album object
			$album = $album->fetch_object();

			// Album not found?
			if ($album===null) {
				Log::error(Database::get(), __METHOD__, __LINE__, 'Could not find specified album');
				return false;
			}

			// Set title
			$zipTitle = $album->title;

		}

		// Illicit chars
		$badChars =	array_merge(
			array_map('chr', range(0,31)),
			array("<", ">", ":", '"', "/", "\\", "|", "?", "*")
		);

		// Escape title
		$zipTitle = str_replace($badChars, '', $zipTitle);

		$filename = LYCHEE_DATA . $zipTitle . '.zip';

		// Create zip
		$zip = new ZipArchive();
		if ($zip->open($filename, ZIPARCHIVE::CREATE)!==TRUE) {
			Log::error(Database::get(), __METHOD__, __LINE__, 'Could not create ZipArchive');
			return false;
		}

		function aux($id, $rootalbumID, $title, $path, $zip) {
			// Illicit chars
			$badChars =	array_merge(
				array_map('chr', range(0,31)),
				array("<", ">", ":", '"', "/", "\\", "|", "?", "*")
			);

			//Determine the photos query and if it needs to go deeper by setting d variable
			$d = false;
			switch($id) {
				case 's':
					$photos   = Database::prepare(Database::get(), 'SELECT title, url FROM ? WHERE public = 1', array(LYCHEE_TABLE_PHOTOS));
					break;
				case 'f':
					$photos   = Database::prepare(Database::get(), 'SELECT title, url FROM ? WHERE star = 1', array(LYCHEE_TABLE_PHOTOS));
					break;
				case 'r':
					$photos   = Database::prepare(Database::get(), 'SELECT title, url FROM ? WHERE LEFT(id, 10) >= unix_timestamp(DATE_SUB(NOW(), INTERVAL 1 DAY)) GROUP BY checksum', array(LYCHEE_TABLE_PHOTOS));
					break;
				default:
					$photos   = Database::prepare(Database::get(), "SELECT title, url FROM ? WHERE album = '?'", array(LYCHEE_TABLE_PHOTOS, $id));
					$d = ($id==0)?false:true;
					break;
			}

			// Execute query
			$photos = Database::execute(Database::get(), $photos, __METHOD__, __LINE__);

			// Parse each path
			$files = array();
			while ($photo = $photos->fetch_object()) {

				// Parse url
				$photo->url = LYCHEE_UPLOADS_BIG . $photo->url;

				// Parse title
				$photo->title = str_replace($badChars, '', $photo->title);
				if (!isset($photo->title)||$photo->title==='') $photo->title = 'Untitled';

				// Check if readable
				if (!@is_readable($photo->url)) {
					Log::error(Database::get(), __METHOD__, __LINE__, 'Original photo missing: ' .$photo->url);
					continue;
				}

				// Get extension of image
				$extension = getExtension($photo->url, false);

				// Set title for photo
				$zipFileName =  $path . '/' . $photo->title . $extension;

				// Check for duplicates
				if (!empty($files)) {
					$i = 1;
					while (in_array($zipFileName, $files)) {

						// Set new title for photo
						$zipFileName = $path . '/' . $photo->title . '-' . $i . $extension;

						$i++;

					}
				}

				// Add to array
				$files[] = $zipFileName;

				// Add photo to zip
				$zip->addFile($photo->url, $zipFileName);

			}

			// If it is necessary to go deeper in subalbums (in case of existence)
			if ($d) {
				//Look for subalbums
				$albums = Database::prepare(Database::get(), 'SELECT id, title, parent FROM ? WHERE parent = ' . $id . ' ' . Settings::get()['sortingAlbums'], array(LYCHEE_TABLE_ALBUMS));
				$albums = Database::execute(Database::get(), $albums, __METHOD__, __LINE__);

				// Check if it still is in root album. Then if it is empty of album and photo
				if ($id == $rootalbumID) {
					if ($photos->num_rows==0 && $albums->num_rows==0) {
						Log::error(Database::get(), __METHOD__, __LINE__, 'Could not create ZipArchive without images and albums');
						return false;
					}
				}
				if ($albums!==false) {
					while ($album = $albums->fetch_assoc()) {
						aux($album['id'], $rootalbumID, $album['title'], $path . '/' . $album['title'], $zip);
					}
				}
			}
		}

		aux($this->albumIDs, $this->albumIDs, $zipTitle, $zipTitle, $zip);

		// Finish zip
		$zip->close();

		// Send zip
		header("Content-Type: application/zip");
		header("Content-Disposition: attachment; filename=\"$zipTitle.zip\"");
		header("Content-Length: " . filesize($filename));
		readfile($filename);

		// Delete zip
		unlink($filename);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		return true;

	}

	/**
	 * @return boolean Returns true when successful.
	 */
	public function setTitle($title = 'Untitled') {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		// Execute query
		$query  = Database::prepare(Database::get(), "UPDATE ? SET title = '?' WHERE id IN (?)", array(LYCHEE_TABLE_ALBUMS, $title, $this->albumIDs));
		$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		if ($result===false) return false;
		return true;

	}

	/**
	 * @return boolean Returns true when successful.
	 */
	public function setDescription($description = '') {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		// Execute query
		$query  = Database::prepare(Database::get(), "UPDATE ? SET description = '?' WHERE id IN (?)", array(LYCHEE_TABLE_ALBUMS, $description, $this->albumIDs));
		$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		if ($result===false) return false;
		return true;

	}

	/**
	 * @return boolean Returns true when license is set.
	 */
	public function setLicense($license) {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		// Execute query
		$query  = Database::prepare(Database::get(), "UPDATE ? SET license = '?' WHERE id IN (?)", array(LYCHEE_TABLE_ALBUMS, $license, $this->albumIDs));
		$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		if ($result===false) return false;
		return true;
	}

	/**
	 * @return boolean Returns true when the album is public.
	 */
	public function getPublic() {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		if ($this->albumIDs==='0'||$this->albumIDs==='s'||$this->albumIDs==='f') return false;

		// Execute query
		$query  = Database::prepare(Database::get(), "SELECT public FROM ? WHERE id = '?' LIMIT 1", array(LYCHEE_TABLE_ALBUMS, $this->albumIDs));
		$albums = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		if ($albums===false) return false;

		// Get album object
		$album = $albums->fetch_object();

		// Album not found?
		if ($album===null) {
			Log::error(Database::get(), __METHOD__, __LINE__, 'Could not find specified album');
			return false;
		}

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		if ($album->public==1) return true;
		return false;

	}

	/**
	 * @return boolean Returns true when the album is downloadable.
	 */
	public function getDownloadable() {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		if ($this->albumIDs==='0'||$this->albumIDs==='s'||$this->albumIDs==='f'||$this->albumIDs==='r') return false;

		// Execute query
		$query  = Database::prepare(Database::get(), "SELECT downloadable FROM ? WHERE id = '?' LIMIT 1", array(LYCHEE_TABLE_ALBUMS, $this->albumIDs));
		$albums = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		if ($albums===false) return false;

		// Get album object
		$album = $albums->fetch_object();

		// Album not found?
		if ($album===null) {
			Log::error(Database::get(), __METHOD__, __LINE__, 'Could not find specified album');
			return false;
		}

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		if ($album->downloadable==1) return true;
		return false;

	}

	/**
	 * @return boolean Returns true when successful.
	 */
	public function setPublic($public, $password, $visible, $downloadable) {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		// Convert values
		$public       = ($public==='1' ? 1 : 0);
		$visible      = ($visible==='1' ? 1 : 0);
		$downloadable = ($downloadable==='1' ? 1 : 0);

		// Set public
		$query  = Database::prepare(Database::get(), "UPDATE ? SET public = '?', visible = '?', downloadable = '?', password = NULL WHERE id IN (?)", array(LYCHEE_TABLE_ALBUMS, $public, $visible, $downloadable, $this->albumIDs));
		$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		if ($result===false) return false;

		// Reset permissions for photos
		if ($public===1) {

			$query  = Database::prepare(Database::get(), "UPDATE ? SET public = 0 WHERE album IN (?)", array(LYCHEE_TABLE_PHOTOS, $this->albumIDs));
			$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

			if ($result===false) return false;

		}

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		// Set password
		if (isset($password)&&strlen($password)>0) return $this->setPassword($password);
		return true;

	}

	/**
	 * @return boolean Returns true when successful.
	 */
	private function setPassword($password) {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		if (strlen($password)>0) {

			// Get hashed password
			$password = getHashedString($password);

			// Set hashed password
			// Do not prepare $password because it is hashed and save
			// Preparing (escaping) the password would destroy the hash
			$query = Database::prepare(Database::get(), "UPDATE ? SET password = '$password' WHERE id IN (?)", array(LYCHEE_TABLE_ALBUMS, $this->albumIDs));

		} else {

			// Unset password
			$query = Database::prepare(Database::get(), "UPDATE ? SET password = NULL WHERE id IN (?)", array(LYCHEE_TABLE_ALBUMS, $this->albumIDs));

		}

		// Execute query
		$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		if ($result===false) return false;
		return true;

	}

	/**
	 * @return boolean Returns when album is public.
	 */
	public function checkPassword($password) {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		// Execute query
		$query  = Database::prepare(Database::get(), "SELECT password FROM ? WHERE id = '?' LIMIT 1", array(LYCHEE_TABLE_ALBUMS, $this->albumIDs));
		$albums = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		if ($albums===false) return false;

		// Get album object
		$album = $albums->fetch_object();

		// Album not found?
		if ($album===null) {
			Log::error(Database::get(), __METHOD__, __LINE__, 'Could not find specified album');
			return false;
		}

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		// Check if password is correct
		if ($album->password===NULL || $album->password === '') return true;
		return password_verify($password, $album->password);

	}

	/**
	 * @return boolean Returns true when successful.
	 */
	public function merge() {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		// Convert to array
		$albumIDs = explode(',', $this->albumIDs);

		// Get first albumID
		$albumID = array_splice($albumIDs, 0, 1);
		$albumID = $albumID[0];

		$query  = Database::prepare(Database::get(), "UPDATE ? SET album = ? WHERE album IN (?)", array(LYCHEE_TABLE_PHOTOS, $albumID, $this->albumIDs));
		$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		if ($result===false) return false;

		// $albumIDs contains all IDs without the first albumID
		// Convert to string
		$filteredIDs = implode(',', $albumIDs);

		$query  = Database::prepare(Database::get(), "DELETE FROM ? WHERE id IN (?)", array(LYCHEE_TABLE_ALBUMS, $filteredIDs));
		$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		// Update the takestamp
		$query  = Database::prepare(Database::get(), "UPDATE ? a SET a.min_takestamp = (SELECT IFNULL(min(takestamp), 0) FROM ? WHERE album = a.id), a.max_takestamp = (SELECT IFNULL(max(takestamp), 0) FROM ? WHERE album = a.id) WHERE a.id = ?",
									array(LYCHEE_TABLE_ALBUMS, LYCHEE_TABLE_PHOTOS, LYCHEE_TABLE_PHOTOS, $albumID));
		$result = $result && Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		if ($result===false) return false;
		return true;

	}

	/**
	 * @return boolean Returns true when successful.
	 */
	public function delete() {

		// Check dependencies
		Validator::required(isset($this->albumIDs), __METHOD__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 0, func_get_args());

		// Init vars
		$photoIDs = array();

		// Execute query
		$query  = Database::prepare(Database::get(), "SELECT id FROM ? WHERE album IN (?)", array(LYCHEE_TABLE_PHOTOS, $this->albumIDs));
		$photos = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		if ($photos===false) return false;

		// Only delete photos when albums contain photos
		if ($photos->num_rows>0) {

			// Add each id to photoIDs
			while ($row = $photos->fetch_object()) $photoIDs[] = $row->id;

			// Convert photoIDs to a string
			$photoIDs = implode(',', $photoIDs);

			// Delete all photos
			$photo = new Photo($photoIDs);
			if ($photo->delete()!==true) return false;

		}

		// Delete albums
		$query  = Database::prepare(Database::get(), "DELETE FROM ? WHERE id IN (?)", array(LYCHEE_TABLE_ALBUMS, $this->albumIDs));
		$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		// Call plugins
		Plugins::get()->activate(__METHOD__, 1, func_get_args());

		if ($result===false) return false;
		return true;

	}

	/**
	 * @return string|false ID of an album with the given title or false if no such album exists.
	 */
	private function exists($title) {

		$query = Database::prepare(Database::get(), "SELECT id FROM ? WHERE title = '?' LIMIT 1", array(LYCHEE_TABLE_ALBUMS, $title));

		$result = Database::execute(Database::get(), $query, __METHOD__, __LINE__);

		if ($result===false) return false;

		if ($result->num_rows===1) {

			$result = $result->fetch_object();

			return $result->id;

		}

		return false;

	}

}
