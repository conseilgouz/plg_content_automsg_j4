<?php
/**
* AutoMsg Plugin  - Joomla 4.x/5.x plugin
* Version			: 3.1.0
* copyright 		: Copyright (C) 2023 ConseilGouz. All rights reserved.
* license    		: http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
*/
// No direct access to this file
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Filesystem\Folder;
use Joomla\Filesystem\File;
use Joomla\CMS\Version;
use Joomla\CMS\Log\Log;
class plgcontentautomsgInstallerScript
{
	private $min_joomla_version      = '4.0.0';
	private $min_php_version         = '7.4';
	private $name                    = 'Plugin Content AutoMsg';
	private $exttype                 = 'plugin';
	private $extname                 = 'automsg';
	private $previous_version        = '';
	private $dir           = null;
	private $lang;
	private $installerName = 'plgcontentautomsginstaller';
	public function __construct()
	{
		$this->dir = __DIR__;
		$this->lang = Factory::getLanguage();
		$this->lang->load($this->extname);
	}

    function preflight($type, $parent)
    {
		if ( ! $this->passMinimumJoomlaVersion())
		{
			$this->uninstallInstaller();
			return false;
		}

		if ( ! $this->passMinimumPHPVersion())
		{
			$this->uninstallInstaller();
			return false;
		}
		// To prevent installer from running twice if installing multiple extensions
		if ( ! file_exists($this->dir . '/' . $this->installerName . '.xml'))
		{
			return true;
		}
    }
    
    function postflight($type, $parent)
    {
		if (($type=='install') || ($type == 'update')) { // remove obsolete dir/files
			$this->postinstall_cleanup();
		}

		switch ($type) {
            case 'install': $message = Text::_('ISO_POSTFLIGHT_INSTALLED'); break;
            case 'uninstall': $message = Text::_('ISO_POSTFLIGHT_UNINSTALLED'); break;
            case 'update': $message = Text::_('ISO_POSTFLIGHT_UPDATED'); break;
            case 'discover_install': $message = Text::_('ISO_POSTFLIGHT_DISC_INSTALLED'); break;
        }
		return true;
    }
	private function postinstall_cleanup() {

		$obsoleteFolders = ['language'];
		// Remove plugins' files which load outside of the component. If any is not fully updated your site won't crash.
		foreach ($obsoleteFolders as $folder)
		{
			$f = JPATH_SITE . '/plugins/plg_content_'.$this->extname.'/' . $folder;

			if (!@file_exists($f) || !is_dir($f) || is_link($f))
			{
				continue;
			}
			Folder::delete($f);
		}
		$obsleteFiles = [
			sprintf("%s/language/en-GB/en-GB.plg_content_%s.ini", JPATH_ADMINISTRATOR, $this->extname),
			sprintf("%s/language/en-GB/en-GB.plg_content_%s.sys.ini", JPATH_ADMINISTRATOR, $this->extname),
			sprintf("%s/language/fr-FR/fr-FR.plg_content_%s.ini", JPATH_ADMINISTRATOR, $this->extname),
			sprintf("%s/language/fr-FR/fr-FR.plg_content_%s.sys.ini", JPATH_ADMINISTRATOR, $this->extname),
			JPATH_SITE . '/plugins/plg_content_'.$this->extname.'/automsg.php'
		];
		foreach ($obsleteFiles as $file) {
			if (@is_file($file)) {
				File::delete($file);
			}
		}
		$db = Factory::getDbo();
        $conditions = array(
            $db->qn('type') . ' = ' . $db->q('plugin'),
            $db->qn('element') . ' = ' . $db->quote($this->extname)
        );
        $fields = array($db->qn('enabled') . ' = 1');

        $query = $db->getQuery(true);
		$query->update($db->quoteName('#__extensions'))->set($fields)->where($conditions);
		$db->setQuery($query);
        try {
	        $db->execute();
        }
        catch (\RuntimeException $e) {
            Log::add('unable to enable '.$this->name, Log::ERROR, 'jerror');
        }
		// automsg replaces publishedarticle : copy its parmeters and remove it if exists
		$this->removePublishedArticle();
	}
	private function removePublishedArticle() {
		// Remove publishedarticle folder.
		$obsloteFolders = ['language'];
		foreach ($obsloteFolders as $folder)
		{
			$f = JPATH_SITE . '/plugins/content/publishedarticle';

			if (!@file_exists($f) || !is_dir($f) || is_link($f))
			{
				continue;
			}

			Folder::delete($f);
		}
		// remove language files
		$langFiles = [
			sprintf("%s/language/en-GB/plg_content_%s.ini", JPATH_ADMINISTRATOR, 'publishedarticle'),
			sprintf("%s/language/en-GB/plg_content_%s.sys.ini", JPATH_ADMINISTRATOR, 'publishedarticle'),
			sprintf("%s/language/fr-FR/plg_content_%s.ini", JPATH_ADMINISTRATOR, 'publishedarticle'),
			sprintf("%s/language/fr-FR/plg_content_%s.sys.ini", JPATH_ADMINISTRATOR,'publishedarticle'),
		];
		foreach ($langFiles as $file) {
			if (@is_file($file)) {
				File::delete($file);
			}
		}
		// get published article params and copy it into automsg plugin
		$db = Factory::getDbo();
        $query = $db->getQuery(true);
		$query->select('params')
		->from($db->quoteName('#__extensions'))
		->where($db->quoteName('type').'='.$db->quote('plugin'))
		->where($db->quoteName('folder').'='.$db->quote('content'))
		->where($db->quoteName('element').'='.$db->quote('publishedarticle'));
		$db->setQuery($query);
		$params = $db->loadResult();
		if (!$params) { // not found : exit
			return true;
		}
		// let's copy those parmas
		$db = Factory::getDbo();
        $conditions = array(
            $db->qn('type') . ' = ' . $db->q('plugin'),
            $db->qn('element') . ' = ' . $db->quote($this->extname),
			$db->qn('folder') . ' = ' . $db->q('content')
        );
        $fields = array($db->qn('params') . ' = '. $db->q($params));

        $query = $db->getQuery(true);
		$query->update($db->quoteName('#__extensions'))->set($fields)->where($conditions);
		$db->setQuery($query);
        try {
	        $db->execute();
        }
        catch (\RuntimeException $e) {
            Log::add('unable to enable '.$this->name, Log::ERROR, 'jerror');
        }
		// delete publishedarticle plugin
        $conditions = array(
			$db->quoteName('type').'='.$db->quote('plugin'),
			$db->quoteName('folder').'='.$db->quote('content'),
			$db->quoteName('element').'='.$db->quote('publishedarticle')
        );
        $query = $db->getQuery(true);
		$query->delete($db->quoteName('#__extensions'))->where($conditions);
		$db->setQuery($query);
        try {
	        $db->execute();
        }
        catch (\RuntimeException $e) {
            Log::add('unable to delete publishedarticle from extensions', Log::ERROR, 'jerror');
        }
		// delete #__update_sites (keep showing update even if system publishedarticle is dissabled)
        $query = $db->getQuery(true);
		$query->select('site.update_site_id')
		->from($db->quoteName('#__extensions','ext'))
		->join('LEFT',$db->quoteName('#__update_sites_extensions','site').' ON site.extension_id = ext.extension_id' )
		->where($db->quoteName('ext.type').'='.$db->quote('plugin'))
		->where($db->quoteName('ext.folder').'='.$db->quote('content'))
		->where($db->quoteName('ext.element').'='.$db->quote('publishedarticle'));
		$db->setQuery($query);
		$upd_id = $db->loadResult();
		if (!$upd_id) return true;
        $conditions = array(
            $db->qn('update_site_id') . ' = ' . $upd_id
        );

        $query = $db->getQuery(true);
		$query->delete($db->quoteName('#__update_sites'))->where($conditions);
		$db->setQuery($query);
        try {
	        $db->execute();
        }
        catch (\RuntimeException $e) {
            Log::add('unable to delete publishedarticle from updata_sites', Log::ERROR, 'jerror');
        }
		
	}
	// Check if Joomla version passes minimum requirement
	private function passMinimumJoomlaVersion()
	{
		$j = new Version();
		$version=$j->getShortVersion(); 
		if (version_compare($version, $this->min_joomla_version, '<'))
		{
			Factory::getApplication()->enqueueMessage(
				'Incompatible Joomla version : found <strong>' . $version . '</strong>, Minimum : <strong>' . $this->min_joomla_version . '</strong>',
				'error'
			);

			return false;
		}

		return true;
	}

	// Check if PHP version passes minimum requirement
	private function passMinimumPHPVersion()
	{

		if (version_compare(PHP_VERSION, $this->min_php_version, '<'))
		{
			Factory::getApplication()->enqueueMessage(
					'Incompatible PHP version : found  <strong>' . PHP_VERSION . '</strong>, Minimum <strong>' . $this->min_php_version . '</strong>',
				'error'
			);
			return false;
		}

		return true;
	}
	private function uninstallInstaller()
	{
		if ( ! is_dir(JPATH_PLUGINS . '/system/' . $this->installerName)) {
			return;
		}
		$this->delete([
			JPATH_PLUGINS . '/system/' . $this->installerName . '/language',
			JPATH_PLUGINS . '/system/' . $this->installerName,
		]);
		$db = Factory::getDbo();
		$query = $db->getQuery(true)
			->delete('#__extensions')
			->where($db->quoteName('element') . ' = ' . $db->quote($this->installerName))
			->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
			->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));
		$db->setQuery($query);
		$db->execute();
		Factory::getCache()->clean('_system');
	}
	
}