<?php
/**
* AutoMsg Plugin  - Joomla 4.x/5.x plugin
* Version			: 3.2.4
* copyright 		: Copyright (C) 2024 ConseilGouz. All rights reserved.
* license    		: http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
*/
// No direct access to this file
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Version;
use Joomla\Component\Categories\Administrator\Model\CategoryModel;
use Joomla\Component\Mails\Administrator\Model\TemplateModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Filesystem\Folder;
use Joomla\Filesystem\File;

class plgcontentautomsgInstallerScript
{
    private $min_joomla_version      = '4.0.0';
    private $min_php_version         = '8.0';
    private $name                    = 'Plugin Content AutoMsg';
    private $exttype                 = 'plugin';
    private $extname                 = 'automsg';
    private $previous_version        = '';
    private $dir           = null;
    private $db;
    private $lang;
    private $installerName = 'plgcontentautomsginstaller';
    public function __construct()
    {
        $this->dir = __DIR__;
        $this->lang = Factory::getLanguage();
        $this->lang->load($this->extname);
    }

    public function preflight($type, $parent)
    {
        if (! $this->passMinimumJoomlaVersion()) {
            $this->uninstallInstaller();
            return false;
        }

        if (! $this->passMinimumPHPVersion()) {
            $this->uninstallInstaller();
            return false;
        }
        // To prevent installer from running twice if installing multiple extensions
        if (! file_exists($this->dir . '/' . $this->installerName . '.xml')) {
            return true;
        }
    }
    function uninstall($parent) {
        $template = new TemplateModel(array('ignore_request' => true));
        $table = $template->getTable();
        $table->delete('plg_content_automsg.ownermail');
        $table->delete('plg_content_automsg.usermail');
        return true;
    }

    public function postflight($type, $parent)
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);

        if (($type == 'install') || ($type == 'update')) { // remove obsolete dir/files
            $this->postinstall_cleanup();
        }

        switch ($type) {
            case 'install': $message = Text::_('ISO_POSTFLIGHT_INSTALLED');
                break;
            case 'uninstall': $message = Text::_('ISO_POSTFLIGHT_UNINSTALLED');
                break;
            case 'update': $message = Text::_('ISO_POSTFLIGHT_UPDATED');
                break;
            case 'discover_install': $message = Text::_('ISO_POSTFLIGHT_DISC_INSTALLED');
                break;
        }
        return true;
    }
    private function postinstall_cleanup()
    {
        // create user notes
        $db = $this->db;
        $query = $db->getQuery(true);
        $query->select('id');
        $query->from('#__categories');
        $query->where('extension = ' . $db->quote('com_users'));
        $query->where('alias = ' . $db->quote('cg-auto'));
        $db->setQuery($query);
        $result = $db->loadResult();
        if (!$result) {
            $this->create_notes_category();
        }
        // create mail template
        $query = $db->getQuery(true);
        $query->select('count(`template_id`)');
        $query->from('#__mail_templates');
        $query->where('extension = ' . $db->quote('plg_content_automsg'));
        $db->setQuery($query);
        $result = $db->loadResult();
        if (!$result) {
            $this->create_mail_templates();
        }
        $obsoleteFolders = ['language'];
        // Remove plugins' files which load outside of the component. If any is not fully updated your site won't crash.
        foreach ($obsoleteFolders as $folder) {
            $f = JPATH_SITE . '/plugins/plg_content_'.$this->extname.'/' . $folder;

            if (!@file_exists($f) || !is_dir($f) || is_link($f)) {
                continue;
            }
            Folder::delete($f);
        }
        $obsleteFiles = [
            sprintf("%s/language/en-GB/en-GB.plg_content_%s.ini", JPATH_ADMINISTRATOR, $this->extname),
            sprintf("%s/language/en-GB/en-GB.plg_content_%s.sys.ini", JPATH_ADMINISTRATOR, $this->extname),
            sprintf("%s/language/fr-FR/fr-FR.plg_content_%s.ini", JPATH_ADMINISTRATOR, $this->extname),
            sprintf("%s/language/fr-FR/fr-FR.plg_content_%s.sys.ini", JPATH_ADMINISTRATOR, $this->extname),
            JPATH_SITE . '/plugins/content/'.$this->extname.'/automsg.php'
        ];
        foreach ($obsleteFiles as $file) {
            if (@is_file($file)) {
                File::delete($file);
            }
        }
        $db = $this->db;
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
        } catch (\RuntimeException $e) {
            Log::add('unable to enable '.$this->name, Log::ERROR, 'jerror');
        }
        // automsg replaces publishedarticle : copy its parmeters and remove it if exists
        $this->removePublishedArticle();
    }
    private function create_notes_category()
    {
        $category = new CategoryModel(array('ignore_request' => true));
        $data = [];
        $data['title'] = 'CG Auto';
        $data['alias'] = 'cg-auto';
        $data['path'] = 'cg-auto';
        $data['description'] = 'Cette table stocke le dernier article envoyé à l\'utilisateur';
        $data['extension'] = 'com_users';
        $data['language'] = '*';
        $data['published'] = 1;

        $table = $category->getTable();
        $table->save($data);
    }
    private function create_mail_templates()
    {
        // check if defined in previous version
        $plugin = PluginHelper::getPlugin('content', 'automsg');
        if ($plugin) { // automsg was defined : get old values
            $params = json_decode($plugin->params);
        }
        $template = new TemplateModel(array('ignore_request' => true));
        $table = $template->getTable();
        $data = [];
        // owner mail template
        $data['template_id'] = 'plg_content_automsg.ownermail';
        $data['extension'] = 'plg_content_automsg';
        $data['language'] = '';
        $data['subject'] = 'PLG_CONTENT_AUTOMSG_PUBLISHED_SUBJECT';
        $data['body'] = 'PLG_CONTENT_AUTOMSG_PUBLISHED_MSG';
        $data['htmlbody'] = '';
        $data['attachments'] = '';
        $data['params'] = '{"tags": ["creator", "title", "cat", "intro", "catimg", "url", "introimg", "subtitle", "tags", "date","featured","unsubscribe"]}';
        $table->save($data);
        // other users mail template
        $data['template_id'] = 'plg_content_automsg.usermail';
        if ($plugin) {
            $subject = $this->tagstouppercase($params->subject);
            $data['subject'] = $subject;
            $body = $this->tagstouppercase($params->body);
            $data['body'] = $body;
        } else {
            $data['subject'] = 'PLG_CONTENT_AUTOMSG_USER_SUBJECT';
            $data['body'] = 'PLG_CONTENT_AUTOMSG_USER_MSG';
        }
        $table->save($data);
    }
    private function tagstouppercase($text)
    {
        $pattern = "/\\{(.*?)\\}/i";
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[0] as $i => $match) {
                $replacement = strtoupper($match);
                $text = str_replace($match, $replacement, $text);
            }
        }
        return $text;
    }
    private function removePublishedArticle()
    {
        // Remove publishedarticle folder.
        $obsloteFolders = ['language'];
        foreach ($obsloteFolders as $folder) {
            $f = JPATH_SITE . '/plugins/content/publishedarticle';

            if (!@file_exists($f) || !is_dir($f) || is_link($f)) {
                continue;
            }

            Folder::delete($f);
        }
        // remove language files
        $langFiles = [
            sprintf("%s/language/en-GB/plg_content_%s.ini", JPATH_ADMINISTRATOR, 'publishedarticle'),
            sprintf("%s/language/en-GB/plg_content_%s.sys.ini", JPATH_ADMINISTRATOR, 'publishedarticle'),
            sprintf("%s/language/fr-FR/plg_content_%s.ini", JPATH_ADMINISTRATOR, 'publishedarticle'),
            sprintf("%s/language/fr-FR/plg_content_%s.sys.ini", JPATH_ADMINISTRATOR, 'publishedarticle'),
        ];
        foreach ($langFiles as $file) {
            if (@is_file($file)) {
                File::delete($file);
            }
        }
        // get published article params and copy it into automsg plugin
        $db = $this->db;
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
        } catch (\RuntimeException $e) {
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
        } catch (\RuntimeException $e) {
            Log::add('unable to delete publishedarticle from extensions', Log::ERROR, 'jerror');
        }
        // delete #__update_sites (keep showing update even if system publishedarticle is dissabled)
        $query = $db->getQuery(true);
        $query->select('site.update_site_id')
        ->from($db->quoteName('#__extensions', 'ext'))
        ->join('LEFT', $db->quoteName('#__update_sites_extensions', 'site').' ON site.extension_id = ext.extension_id')
        ->where($db->quoteName('ext.type').'='.$db->quote('plugin'))
        ->where($db->quoteName('ext.folder').'='.$db->quote('content'))
        ->where($db->quoteName('ext.element').'='.$db->quote('publishedarticle'));
        $db->setQuery($query);
        $upd_id = $db->loadResult();
        if (!$upd_id) {
            return true;
        }
        $conditions = array(
            $db->qn('update_site_id') . ' = ' . $upd_id
        );

        $query = $db->getQuery(true);
        $query->delete($db->quoteName('#__update_sites'))->where($conditions);
        $db->setQuery($query);
        try {
            $db->execute();
        } catch (\RuntimeException $e) {
            Log::add('unable to delete publishedarticle from updata_sites', Log::ERROR, 'jerror');
        }

    }
    // Check if Joomla version passes minimum requirement
    private function passMinimumJoomlaVersion()
    {
        $j = new Version();
        $version = $j->getShortVersion();
        if (version_compare($version, $this->min_joomla_version, '<')) {
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

        if (version_compare(PHP_VERSION, $this->min_php_version, '<')) {
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
        if (! is_dir(JPATH_PLUGINS . '/system/' . $this->installerName)) {
            return;
        }
        $this->delete([
            JPATH_PLUGINS . '/system/' . $this->installerName . '/language',
            JPATH_PLUGINS . '/system/' . $this->installerName,
        ]);
        $db = $this->db;
        $query = $db->getQuery(true)
            ->delete('#__extensions')
            ->where($db->quoteName('element') . ' = ' . $db->quote($this->installerName))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));
        $db->setQuery($query);
        $db->execute();
        Factory::getCache()->clean('_system');
    }
    public function delete($files = [])
    {
        foreach ($files as $file) {
            if (is_dir($file)) {
                Folder::delete($file);
            }

            if (is_file($file)) {
                File::delete($file);
            }
        }
    }
}
