<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

class Zotero_Collection {
	private $_id;
	private $_libraryID;
	private $_key;
	private $_name;
	private $_parent = false;
	private $_dateAdded;
	private $_dateModified;
	
	private $loaded;
	private $changed;
	
	private $childCollectionsLoaded;
	private $childCollections = array();
	
	private $childItemsLoaded;
	private $childItems = array();
	
	public function __construct() {
		$numArgs = func_num_args();
		if ($numArgs) {
			throw new Exception("Constructor doesn't take any parameters");
		}
	}
	
	
	public function __get($field) {
		if (($this->_id || $this->_key) && !$this->loaded) {
			$this->load(true);
		}
		
		switch ($field) {
			case 'parent':
				return $this->getParent();
			
			case 'parentKey':
				return $this->getParentKey();
		}
		
		if (!property_exists('Zotero_Collection', '_' . $field)) {
			throw new Exception("Zotero_Collection property '$field' doesn't exist");
		}
		$field = '_' . $field;
		return $this->$field;
	}
	
	
	public function __set($field, $value) {
		switch ($field) {
			case 'id':
			case 'libraryID':
			case 'key':
				if ($this->loaded) {
					trigger_error("Cannot set $field after collection is already loaded", E_USER_ERROR);
				}
				$this->checkValue($field, $value);
				$field = '_' . $field;
				$this->$field = $value;
				return;
		}
		
		if ($this->id || $this->key) {
			if (!$this->loaded) {
				$this->load(true);
			}
		}
		else {
			$this->loaded = true;
		}
		
		switch ($field) {
			case 'parent':
				$this->setParent($value);
				return;
			
			case 'parentKey':
				$this->setParentKey($value);
				return;
		}
		
		$this->checkValue($field, $value);
		
		$field = '_' . $field;
		if ($this->$field != $value) {
			$this->changed = true;
			$this->$field = $value;
		}
	}
	
	
	/**
	 * Check if collection exists in the database
	 *
	 * @return	bool			TRUE if the item exists, FALSE if not
	 */
	public function exists() {
		if (!$this->id) {
			trigger_error('$this->id not set');
		}
		
		$sql = "SELECT COUNT(*) FROM collections WHERE collectionID=?";
		return !!Zotero_DB::valueQuery($sql, $this->id);
	}
	
	
	public function save() {
		if (!$this->libraryID) {
			trigger_error("Library ID must be set before saving", E_USER_ERROR);
		}
		
		Zotero_Collections::editCheck($this);
		
		if (!$this->changed) {
			Z_Core::debug("Collection $this->id has not changed");
			return false;
		}
		
		Zotero_DB::beginTransaction();
		
		try {
			$collectionID = $this->id ? $this->id : Zotero_ID::get('collections');
			
			Z_Core::debug("Saving collection $this->id");
			
			$key = $this->key ? $this->key : $this->generateKey();
			
			// Verify parent
			if ($this->_parent) {
				if (is_int($this->_parent)) {
					$newParentCollection = Zotero_Collections::get($this->_parent);
				}
				else {
					// TODO: static binding
					$newParentCollection = Zotero_Collections::getByLibraryAndKey($this->libraryID, $this->_parent, 'collections');
				}
				
				if (!$newParentCollection) {
					// TODO: clear caches
					throw new Exception("Cannot set parent to invalid collection $this->_parent");
				}
				
				if ($newParentCollection->id == $this->id) {
					trigger_error("Cannot move collection $this->id into itself!", E_USER_ERROR);
				}
				
				if ($this->id && $this->hasDescendent('collection', $newParentCollection->id)) {
					trigger_error('Cannot move collection into one of its own descendents!', E_USER_ERROR);
				}
				
				$parent = $newParentCollection->id;
			}
			else {
				$parent = null;
			}
			
			$fields = "collectionName=?, parentCollectionID=?, libraryID=?, `key`=?,
						dateAdded=?, dateModified=?, serverDateModified=?, serverDateModifiedMS=?";
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$timestampMS = Zotero_DB::getTransactionTimestampMS();
			$params = array(
				$this->name,
				$parent,
				$this->libraryID,
				$key,
				$this->dateAdded ? $this->dateAdded : $timestamp,
				$this->dateModified ? $this->dateModified : $timestamp,
				$timestamp,
				$timestampMS
			);
			
			$params = array_merge(array($collectionID), $params, $params);
			
			$sql = "INSERT INTO collections SET collectionID=?, $fields
					ON DUPLICATE KEY UPDATE $fields";
			$insertID = Zotero_DB::query($sql, $params);
			if (!$this->id) {
				if (!$insertID) {
					throw new Exception("Collection id not available after INSERT");
				}
				$collectionID = $insertID;
				Zotero_Collections::cacheLibraryKeyID($this->libraryID, $key, $insertID);
			}
			
			Zotero_DB::commit();
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		// If successful, set values in object
		if (!$this->id) {
			$this->_id = $collectionID;
		}
		if (!$this->key) {
			$this->_key = $key;
		}
		
		return $this->id;
	}
	
	
	/**
	 * Returns child collections
	 *
	 * @return {Integer[]}	Array of collectionIDs
	 */
	public function getChildCollections() {
		if (!$this->childCollectionsLoaded) {
			$this->loadChildCollections();
		}
		return $this->childCollections;
	}
	
	
	/*
	public function setChildCollections($collectionIDs) {
		Zotero_DB::beginTransaction();
		
		if (!$this->childCollectionsLoaded) {
			$this->loadChildCollections();
		}
		
		$current = $this->childCollections;
		$removed = array_diff($current, $collectionIDs);
		$new = array_diff($collectionIDs, $current);
		
		if ($removed) {
			$sql = "UPDATE collections SET parentCollectionID=NULL
					WHERE userID=? AND collectionID IN (";
			$q = array();
			$params = array($this->userID, $this->id);
			foreach ($removed as $collectionID) {
				$q[] = '?';
				$params[] = $collectionID;
			}
			$sql .= implode(',', $q) . ")";
			Zotero_DB::query($sql, $params);
		}
		
		if ($new) {
			$sql = "UPDATE collections SET parentCollectionID=?
					WHERE userID=? AND collectionID IN (";
			$q = array();
			$params = array($this->userID);
			foreach ($new as $collectionID) {
				$q[] = '?';
				$params[] = $collectionID;
			}
			$sql .= implode(',', $q) . ")";
			Zotero_DB::query($sql, $params);
		}
		
		$this->childCollections = $new;
		
		Zotero_DB::commit();
	}
	*/
	
	
	/**
	 * Returns child items
	 *
	 * @return {Integer[]}	Array of itemIDs
	 */
	public function getChildItems() {
		if (!$this->childItemsLoaded) {
			$this->loadChildItems();
		}
		return $this->childItems;
	}
	
	
	public function setChildItems($itemIDs) {
		Zotero_DB::beginTransaction();
		
		if (!$this->childItemsLoaded) {
			$this->loadChildItems();
		}
		
		$current = $this->childItems;
		$removed = array_diff($current, $itemIDs);
		$new = array_diff($itemIDs, $current);
		
		if ($removed) {
			$sql = "DELETE FROM collectionItems WHERE collectionID=? AND itemID IN (";
			$q = array();
			$params = array($this->id);
			foreach ($removed as $itemID) {
				$q[] = '?';
				$params[] = $itemID;
			}
			$sql .= implode(',', $q) . ")";
			Zotero_DB::query($sql, $params);
		}
		
		if ($new) {
			// DEBUG
			if ($this->id == 1528) {
				$sql = "INSERT INTO collectionItems (collectionID, itemID) VALUES (?, ?)";
				foreach ($new as $itemID) {
					Zotero_DB::query($sql, array($this->id, $itemID));
				}
			}
			else {
			
			$sql = "INSERT INTO collectionItems (collectionID, itemID) VALUES";
			$q = array();
			$params = array();
			foreach ($new as $itemID) {
				$q[] = '(?,?)';
				$params = array_merge($params,
					array($this->id, $itemID));
			}
			$sql .= implode(',', $q);
			Zotero_DB::query($sql, $params);
			
			}
		}
		
		$this->childItems = $new;
		
		Zotero_DB::commit();
	}
	
	
	public function addItem($itemID) {
		if (!$this->exists()) {
			trigger_error("Collection hasn't been saved", E_USER_ERROR);
		}
		
		Zotero_DB::beginTransaction();
		
		if (!Zotero_Items::get($itemID)) {
			Zotero_DB::rollback();
			trigger_error("Item $itemID does not exist", E_USER_ERROR);
		}
		
		// TODO: support order index
		
		$sql = "INSERT IGNORE INTO collectionItems (collectionID, itemID) VALUES (?, ?)";
		Zotero_DB::query($sql, array($this->id, $itemID));
		
		Zotero_DB::commit();
	}
	
	
	public function addItems($itemIDs) {
		Zotero_DB::beginTransaction();
		foreach ($itemIDs as $itemID) {
			$this->addItem($itemID);
		}
		Zotero_DB::commit();
	}
	
	
	public function hasDescendent($type, $id) {
		$descendents = $this->getChildren(true, false, $type);
		for ($i=0, $len=sizeOf($descendents); $i<$len; $i++) {
			if ($descendents[$i]['id'] == $id) {
				return true;
			}
		}
		return false;
	}
	
	
	/**
	 * Returns an array of descendent collections and items
	 *	(rows of 'id', 'type' ('item' or 'collection'), 'parent', and,
	 * 	if collection, 'name' and the nesting 'level')
	 *
	 * @param	bool		$recursive	Descend into subcollections
	 * @param	bool		$nested		Return multidimensional array with 'children'
	 *									nodes instead of flat array
	 * @param	string	$type		'item', 'collection', or FALSE for both
	 */
	public function getChildren($recursive=false, $nested=false, $type=false, $level=1) {
		$toReturn = array();
		
		// 0 == collection
		// 1 == item
		$children = Zotero_DB::query('SELECT collectionID AS id, 
				0 AS type, collectionName AS collectionName, `key`
				FROM collections WHERE parentCollectionID=?
				UNION SELECT itemID AS id, 1 AS type, NULL AS collectionName, `key`
				FROM collectionItems JOIN items USING (itemID) WHERE collectionID=?',
				array($this->id, $this->id));
		
		if ($type) {
			switch ($type) {
				case 'item':
				case 'collection':
					break;
				default:
					throw ("Invalid type '$type'");
			}
		}
		
		for ($i=0, $len=sizeOf($children); $i<$len; $i++) {
			// This seems to not work without parseInt() even though
			// typeof children[i]['type'] == 'number' and
			// children[i]['type'] === parseInt(children[i]['type']),
			// which sure seems like a bug to me
			switch ($children[$i]['type']) {
				case 0:
					if (!$type || $type == 'collection') {
						$toReturn[] = array(
							'id' => $children[$i]['id'],
							'name' =>  $children[$i]['collectionName'],
							'key' => $children[$i]['key'],
							'type' =>  'collection',
							'level' =>  $level,
							'parent' =>  $this->id
						);
					}
					
					if ($recursive) {
						$col = Zotero_Collections::get($children[$i]['id']);
						$descendents = $col->getChildren(true, $nested, $type, $level+1);
						
						if ($nested) {
							$toReturn[sizeOf($toReturn) - 1]['children'] = $descendents;
						}
						else {
							for ($j=0, $len2=sizeOf($descendents); $j<$len2; $j++) {
								$toReturn[] = $descendents[$j];
							}
						}
					}
				break;
				
				case 1:
					if (!$type || $type == 'item') {
						$toReturn[] = array(
							'id' => $children[$i]['id'],
							'key' => $children[$i]['key'],
							'type' => 'item',
							'parent' => $this->id
						);
					}
				break;
			}
		}
		
		return $toReturn;
	}
	
	
	private function getParent() {
		if ($this->_parent !== false) {
			if (!$this->_parent) {
				return null;
			}
			if (is_int($this->_parent)) {
				return $this->_parent;
			}
			$parentCollection = Zotero_Collections::getByLibraryAndKey($this->libraryID, $this->_parent);
			if (!$parentCollection) {
				throw new Exception("Source collection for keyed parent doesn't exist");
			}
			// Replace stored key with id
			$this->_parent = $parentCollection->id;
			return $parentCollection->id;
		}
		
		if (!$this->id) {
			return false;
		}
		
		$sql = "SELECT parentCollectionID FROM collections WHERE collectionID=?";
		$parentCollectionID = Zotero_DB::valueQuery($sql, $this->id);
		if (!$parentCollectionID) {
			$parentCollectionID = null;
		}
		$this->_parent = $parentCollectionID;
		return $parentCollectionID;
	}
	
	
	private function getParentKey() {
		if ($this->_parent !== false) {
			if (!$this->_parent) {
				return null;
			}
			if (is_string($this->_parent)) {
				return $this->_parent;
			}
			$parentCollection = Zotero_Collections::get($this->_parent);
			return $parentCollection->key;
		}
		
		if (!$this->id) {
			return false;
		}
		
		$sql = "SELECT B.`key` FROM collections A JOIN collections B
				ON (A.parentCollectionID=B.collectionID) WHERE A.collectionID=?";
		$key = Zotero_DB::valueQuery($sql, $this->id);
		if (!$key) {
			$key = null;
		}
		$this->_parent = $key;
		return $key;
	}
	
	
	private function setParent($parentCollectionID) {
		if ($this->id || $this->key) {
			if (!$this->loaded) {
				$this->load(true);
			}
		}
		else {
			$this->loaded = true;
		}
		
		$oldParentCollectionID = $this->getParent();
		if ($oldParentCollectionID == $parentCollectionID) {
			Z_Core::debug("Parent collection has not changed for collection $this->id");
			return false;
		}
		
/*		if ($this->id && $this->exists() && !$this->previousData) {
			$this->previousData = $this.serialize();
		}
*/		
		$this->_parent = $parentCollectionID ? (int) $parentCollectionID : null;
		$this->changed = true;
		return true;
	}
	
	
	public function setParentKey($parentCollectionKey) {
		if ($this->id || $this->key) {
			if (!$this->loaded) {
				$this->load(true);
			}
		}
		else {
			$this->loaded = true;
		}
		
		$oldParentCollectionID = $this->getParent();
		if ($oldParentCollectionID) {
			$parentCollection = Zotero_Collections::get($oldParentCollectionID);
			$oldParentCollectionKey = $parentCollection->key;
			if (!$oldParentCollectionKey) {
				throw new Exception("No key for parent collection $oldParentCollectionID"); 
			}
		}
		else {
			$oldParentCollectionKey = null;
		}
		if ($oldParentCollectionKey == $parentCollectionKey) {
			Z_Core::debug('Source collection has not changed in Zotero_Collection::setParentKey()');
			return false;
		}
		
		/*if ($this->id && $this->exists() && !$this->previousData) {
			$this->previousData = $this.serialize();
		}*/
		$this->_parent = $parentCollectionKey ? $parentCollectionKey : null;
		$this->changed = true;
		
		return true;
	}
	
	
	private function load($allowFail=false) {
		$id = $this->_id;
		$libraryID = $this->_libraryID;
		$key = $this->_key;
		
		Z_Core::debug("Loading data for collection $id");
		
		$sql = "SELECT collectionID AS id, collectionName AS name, libraryID, `key`,
				dateAdded, dateModified, parentCollectionID AS parent
				FROM collections WHERE ";
		if ($id) {
			if (!$id) {
				throw new Exception("Collection ID not set");
			}
			$sql .= "collectionID=?";
			$params = $id;
		}
		else {
			$sql .= "libraryID=? AND `key`=?";
			$params = array($libraryID, $key);
		}
		$data = Zotero_DB::rowQuery($sql, $params);
		
		$this->loaded = true;
		
		if (!$data) {
			return;
		}
		
		foreach ($data as $key=>$val) {
			$field = '_' . $key;
			$this->$field = $val;
		}
	}
	
	
	private function loadChildCollections() {
		Z_Core::debug("Loading subcollections for collection $this->id");
		
		if ($this->childCollectionsLoaded) {
			trigger_error("Subcollections for collection $this->id already loaded", E_USER_ERROR);
		}
		
		if (!$this->id) {
			trigger_error('$this->id not set', E_USER_ERROR);
		}
		
		$sql = "SELECT collectionID FROM collections WHERE parentCollectionID=?";
		$ids = Zotero_DB::columnQuery($sql, $this->id);
		
		$this->childCollections = $ids ? $ids : array();
		$this->childCollectionsLoaded = true;
	}
	
	
	private function loadChildItems() {
		Z_Core::debug("Loading child items for collection $this->id");
		
		if ($this->childItemsLoaded) {
			trigger_error("Child items for collection $this->id already loaded", E_USER_ERROR);
		}
		
		if (!$this->id) {
			trigger_error('$this->id not set', E_USER_ERROR);
		}
		
		$sql = "SELECT itemID FROM collectionItems WHERE collectionID=?";
		$ids = Zotero_DB::columnQuery($sql, $this->id);
		
		$this->childItems = $ids ? $ids : array();
		$this->childItemsLoaded = true;
	}
	
	
	private function checkValue($field, $value) {
		if (!property_exists($this, '_' . $field)) {
			trigger_error("Invalid property '$field'", E_USER_ERROR);
		}
		
		// Data validation
		switch ($field) {
			case 'id':
			case 'libraryID':
				if (!Zotero_Utilities::isPosInt($value)) {
					$this->invalidValueError($field, $value);
				}
				break;
			
			case 'key':
				if (!preg_match('/^[23456789ABCDEFGHIJKMNPQRSTUVWXTZ]{8}$/', $value)) {
					$this->invalidValueError($field, $value);
				}
				break;
			
			case 'dateAdded':
			case 'dateModified':
				if (!preg_match("/^[0-9]{4}\-[0-9]{2}\-[0-9]{2} ([0-1][0-9]|[2][0-3]):([0-5][0-9]):([0-5][0-9])$/", $value)) {
					$this->invalidValueError($field, $value);
				}
				break;
		}
	}
	
	
	private function generateKey() {
		trigger_error('Unimplemented', E_USER_ERROR);
	}
	
	
	private function invalidValueError($field, $value) {
		trigger_error("Invalid '$field' value '$value'", E_USER_ERROR);
	}
}
?>