<?php

namespace SilverStripe\SelectUpload;

use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Permission;
use SilverStripe\SelectUpload\FolderDropdownField;
use SilverStripe\ORM\SS_List;
use SilverStripe\View\Requirements;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\File;
use SilverStripe\AssetAdmin\Controller\AssetAdmin;

/**
 * A composite form field which allows users to select a folder into which files may be uploaded
 *
 * @package framework
 * @subpackage forms
 */
class SelectUploadField extends UploadField
{

    private static $url_handlers = [
        'folder/tree/$ID' => 'tree'
    ];

    /**
     * @config
     * @var array
     */
    private static $allowed_actions = [
        'upload',
        'tree',
        'changeFolder'
    ];
    /**
     * Folder selector field
     *
     * @var FolderDropdownField
     */
    protected $selectField;

    protected $canSelectFolder = true;

    public function __construct($name, $title = null, SS_List $items = null)
    {
        parent::__construct($name, $title, $items);
        $this->selectField = FolderDropdownField::create("{$name}/folder");

        // If we haven't uploaded to a folder yet, set to the default foldername
        if (!$this->selectField->Value()) {
            $folderID = $this->folderIDFromPath($this->getDefaultFolderName());
            if ($folderID) {
                $this->selectField->setValue($folderID);
            }
        }
    }

    public function Field($properties = [])
    {
        $field = parent::Field($properties);
        $folderLink = $this->Link('changeFolder');
        // Extra requirements
        Requirements::customScript("const folderURL = '$folderLink'");
        Requirements::javascript("silverstripe/selectupload:/js/SelectUploadField.js");
        Requirements::css("silverstripe/selectupload:/css/SelectUploadField.css");
        return $field;
    }

    /**
     * Get the folder selector field
     *
     * @return FolderDropdownField
     */
    public function FolderSelector()
    {
        return $this->selectField;
    }

    /**
     * Return the subtree for a selected folder id
     *
     * @param SS_HTTPRequest $request
     * @return string HTTP Response
     */
    public function tree($request)
    {
        return $this->FolderSelector()->tree($request);
    }

    public function changeFolder(HTTPRequest $request)
    {
        // CSRF check
        $token = $this->getForm()->getSecurityToken();
        if (!$token->checkRequest($request)) {
            return $this->httpError(400);
        }
        $folderID = $request->postVar('FolderID');
        if ($folderID) {
            $this->FolderSelector()->set_last_folder($folderID);
        }
    }

    public function setForm($form)
    {
        $this->selectField->setForm($form);
        return parent::setForm($form);
    }

    public function Type()
    {
        return 'selectupload entwine-uploadfield uploadfield';
    }

    /**
     * Given a request, ensure that the current field is aware of the selected folder
     *
     * @param SS_HTTPRequest $request
     */
    protected function updateFolderName($request)
    {
        // check if allowed to select folder
        if (!$this->canSelectFolder()) {
            return;
        }

        // Get path from upload
        $folderID = $request->requestVar("{$this->Name}/folder");
        $path = $this->folderPathFromID($folderID);
        if ($path !== false) {
            $this->setFolderName($path);
            $this->selectField->setValue($folderID);
        }
    }

    /**
     * Get path of a folder relative to /assets/ by id.
     * This will be a format appropriate for setting setFolderName to
     *
     * @param int $folderID
     * @return string|bool Relative path to the assets directory, or false if not found
     */
    protected function folderPathFromID($folderID)
    {
        if (empty($folderID)) {
            return false;
        }
        $folder = Folder::get()->byID($folderID);
        if (!$folder) {
            return false;
        }

        // Translate path
        $path = $folder->getFilename();
        if (stripos($path, ASSETS_DIR) === 0) {
            $path = substr($path, strlen(ASSETS_DIR) + 1);
        }
        return $path;
    }

    /**
     * Gets the ID of a folder given a path relative to /assets/.
     *
     * @param string $path
     * @return int Folder ID
     */
    protected function folderIDFromPath($path)
    {
        $folder = File::find($path);
        if ($folder) {
            return $folder->ID;
        }
    }

    public function handleRequest(HTTPRequest $request)
    {
        $this->updateFolderName($request);
        return parent::handleRequest($request);
    }

    /**
     * Specify whether the user can select an upload folder.
     * String values will be treated as required permission codes
     *
     * @param boolean|string $canSelectFolder Either a boolean flag, or a required
     * permission code
     * @return self Self reference
     */
    public function setCanSelectFolder($canSelectFolder)
    {
        return $this->canSelectFolder = $canSelectFolder;
    }

    /**
     * Determine if the current member is allowed to change the folder
     *
     * @return boolean
     */
    public function getCanSelectFolder()
    {
        if (!$this->isActive()) {
            return false;
        }
        if ($this->template && in_array($this->template, self::config()->disable_for_templates)) {
            return false;
        }
        // Check config
        $can = $this->canSelectFolder;
        return (is_bool($can)) ? $can : Permission::check($can);
    }

    public function getFolderName()
    {
        // Ensure that, if this member is allowed, the persistant folder overrides any default set
        if ($this->getCanSelectFolder()) {
            $path = $this->folderPathFromID($this->selectField->Value());
            if ($path !== false) {
                return $path;
            }
        }
        return $this->getDefaultFolderName();
    }

    /**
     * Get the 'default' folder name
     *
     * @return string
     */
    public function getDefaultFolderName()
    {
        return parent::getFolderName();
    }

    public function getDisplayFolderName()
    {
        $name = $this->getFolderName();
        return preg_replace('/\s*\\/\s*/', ' / ', trim($name, '/'));
    }

    /**
     * Returns true if the field is neither readonly nor disabled
     *
     * @return boolean
     */
    public function isActive()
    {
        return !$this->isDisabled() && !$this->isReadonly();
    }
}
