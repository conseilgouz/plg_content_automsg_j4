<?php
/**
 * Plugin AutoMsg : send Email to selected users when an article is published
 * Version		  : 3.2.0
 *
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * @copyright (c) 2024 ConseilGouz. All Rights Reserved.
 * @author ConseilGouz
 */

namespace ConseilGouz\Plugin\Content\AutoMsg\Extension;

defined('_JEXEC') or die;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailTemplate;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Model\ArticleModel;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;

final class AutoMsg extends CMSPlugin
{
    use DatabaseAwareTrait;

    protected $itemtags;

    protected $info_cat;

    protected $tag_img;

    protected $cat_img;

    protected $url;

    protected $needCatImg;

    protected $needIntroImg;

    protected $deny;

    public function onContentAfterSave($context, $article, $isNew): void
    {

        // Check if this function is enabled.
        if (!$this->params->def('email_new_fe', 1)) {
            return ;
        }

        // Check this is a new article.
        if (!$isNew) {
            return ;
        }
        $auto = $this->params->get('msgauto', '');
        if (($article->state == 1) && ($auto == 1)) {// article auto publié
            $arr[0] = $article->id;
            self::onContentChangeState($context, $arr, $article->state);
            return;
        }
        return ;
    }
    /**
     * Change the state in core_content if the state in a table is changed
     *
     * @param   string   $context  The context for the content passed to the plugin.
     * @param   array    $pks      A list of primary key ids of the content that has changed state.
     * @param   integer  $value    The value of the state that the content has been changed to.
     *
     * @return  boolean
     *
     * @since   3.1
     */
    public function onContentChangeState($context, $pks, $value)
    {
        if (($context != 'com_content.article') && ($context != 'com_content.form')) {
            return true;
        }
        if ($value == 0) { // unpublish => on sort
            return true;
        }
        // parametres du plugin
        $categories = $this->params->get('categories', array());
        $usergroups = $this->params->get('usergroups', array());

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('u.id'))
            ->from($db->quoteName('#__users').' as u ')
            ->join('LEFT', $db->quoteName('#__user_usergroup_map').' as g on u.id = g.user_id')
            ->where($db->quoteName('block') . ' = 0 AND g.group_id IN ('.implode(',', $usergroups).')');
        $db->setQuery($query);
        $users = (array) $db->loadColumn();
        // check profile automsg
        $query = $db->getQuery(true)
            ->select($db->quoteName('p.user_id'))
            ->from($db->quoteName('#__user_profiles').' as p ')
            ->where($db->quoteName('profile_key') . ' like ' .$db->quote('profile_automsg.%').' AND '.$db->quoteName('profile_value'). ' like '.$db->quote('%Non%'));
        $db->setQuery($query);
        $this->deny = (array) $db->loadColumn();
        $users = array_diff($users, $this->deny);

        if (empty($users)) {
            return true;
        }
        if ($this->params->get('log', 0)) { // need to log msgs
            Log::addLogger(
                array('text_file' => 'plg_content_automsg.log.php'),
                Log::ALL,
                array('plg_content_automsg')
            );
        }
        $tokens = $this->getAutomsgToken($users);

        foreach ($pks as $articleid) {
            $model     = new ArticleModel(array('ignore_request' => true));
            $model->setState('params', $this->params);
            $model->setState('list.start', 0);
            $model->setState('list.limit', 1);
            $model->setState('filter.published', 1);
            $model->setState('filter.featured', 'show');
            // Access filter
            $access = ComponentHelper::getParams('com_content')->get('show_noauth');
            $model->setState('filter.access', $access);

            // Ordering
            $model->setState('list.ordering', 'a.hits');
            $model->setState('list.direction', 'DESC');

            $article = $model->getItem($articleid);
            if (!empty($categories) && !in_array($article->catid, $categories)) {
                continue; // wrong category
            }
            $async = false;
            if (PluginHelper::isEnabled('task', 'automsg') && ComponentHelper::isEnabled('com_automsg')) {
                $async = true; // automsg task plugin / component ok
            }
            if ($this->params->get('async', 0) && $async) {
                $this->async($article);
            } else {
                $this->sendEmails($article, $users, $tokens);
            }
        }
        return true;
    }
    private function sendEmails($article, $users, $tokens)
    {
        $app = Factory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('plg_content_automsg');
        $config = $app->getConfig();
        $msgcreator = $this->params->get('msgcreator', 0);
        $libdateformat = "d/M/Y h:m";

        $creatorId = $article->created_by;
        if (!in_array($creatorId, $users) && (!in_array($creatorId, $this->deny))) { // creator not in users array : add it
            $users[] = $creatorId;
        }
        $creator = $app->getIdentity($creatorId);
        $this->url = "<a href='".URI::root()."index.php?option=com_content&view=article&id=".$article->id."' target='_blank'>".Text::_('PLG_CONTENT_AUTOMSG_CLICK')."</a>";
        $this->info_cat = $this->getCategoryName($article->catid);
        $cat_params = json_decode($this->info_cat[0]->params);
        $this->cat_img = "";
        if ($cat_params->image != "") {
            $img = HTMLHelper::cleanImageURL($cat_params->image_intro);
            $this->cat_img = '<img src="'.URI::root().pathinfo($img->url)['dirname'].'/'.pathinfo($img->url)['basename'].'" />';
        }
        $images  = json_decode($article->images);
        $article->introimg = "";
        if (!empty($images->image_intro)) { // into img exists
            $img = HTMLHelper::cleanImageURL($images->image_intro);
            $article->introimg = '<img src="'.URI::root().pathinfo($img->url)['dirname'].'/'.pathinfo($img->url)['basename'].'" />';
        }
        $article_tags = self::getArticleTags($article->id);
        $this->itemtags = "";
        foreach ($article_tags as $tag) {
            $this->itemtags .= '<span class="iso_tag_'.$tag->alias.'">'.(($this->itemtags == "") ? $tag->tag : "<span class='iso_tagsep'><span>-</span></span>".$tag->tag).'</span>';
        }
        $this->needCatImg = false;
        $this->needIntroImg = false;

        foreach ($users as $user_id) {
            $receiver = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($user_id);
            $unsubscribe = "";
            if ($tokens[$user_id]) {
                $unsubscribe = "<a href='".URI::root()."index.php?option=com_automsg&view=automsg&layout=edit&token=".$tokens[$user_id]."' target='_blank'>".Text::_('PLG_CONTENT_AUTOMSG_UNSUBSCRIBE')."</a>";
            }
            $go = false;
            // Collect data for mail
            $data = [
                'creator'   => $creator->name,
                'id'        => $article->id,
                'title'     => $article->title,
                'cat'       => $this->info_cat[0]->title,
                'date'      => HTMLHelper::_('date', $article->created, $libdateformat),
                'intro'     => $article->introtext,
                'catimg'    => $this->cat_img,
                'url'       => $this->url,
                'introimg'  => $article->introimg,
                'subtitle'  => $article->subtitle,
                'tags'      => $this->itemtags,
                'featured'  => $article->featured,
                'unsubscribe'   => $unsubscribe
            ];
            if (($user_id == $creatorId) && ($msgcreator == 1)) { // mail specifique au createur de l'article
                $mailer = new MailTemplate('plg_content_automsg.ownermail', $receiver->getParam('language', $app->get('language')));
            } else {
                $mailer = new MailTemplate('plg_content_automsg.usermail', $receiver->getParam('language', $app->get('language')));
            }
            $mailer->addTemplateData($data);
            $mailer->addRecipient($receiver->email, $receiver->name);

            try {
                $res = $mailer->send();
            } catch (\Exception $e) {
                if ($this->params->get('log', 0)) { // need to log msgs
                    Log::add('Erreur ----> Article : '.$article->title.' non envoyé à '.$receiver->email.'/'.$e->getMessage(), Log::ERROR, 'plg_content_automsg');
                } else {
                    $app->enqueueMessage($e->getMessage().'/'.$receiver->email, 'error');
                }
                continue;
            }
            if ($this->params->get('log', 0) == 2) { // need to log all msgs
                Log::add('Article OK : '.$article->title.' envoyé à '.$receiver->email, Log::DEBUG, 'plg_content_automsg');
            }
        }
    }
    /*
     * Asynchronous process : store article id in automsg table
     */
    private function async($article)
    {
        $db    = $this->getDatabase();
        $date = Factory::getDate();

        $query = $db->getQuery(true)
        ->insert($db->quoteName('#__automsg'));
        $query->values(
            implode(
                ',',
                $query->bindArray(
                    [
                        0, // key
                        0, // state
                        $article->id,
                        $date->toSql(),
                        null
                    ],
                    [
                        ParameterType::INTEGER,
                        ParameterType::INTEGER,
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                        ParameterType::NULL
                    ]
                )
            )
        );
        $db->setQuery($query);
        $db->execute();
        if ($this->params->get('log', 0) == 2) { // need to log all msgs
            Log::add('Article Async : '.$article->title, Log::DEBUG, 'plg_content_automsg');
        }
    }
    private function getCategoryName($id)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from('#__categories ')
            ->where('id = '.(int)$id)
        ;
        $db->setQuery($query);
        return $db->loadObjectList();
    }
    private function getArticleTags($id)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);
        $query->select('tags.title as tag, tags.alias as alias, tags.note as note, tags.images as images, parent.title as parent_title, parent.alias as parent_alias')
            ->from('#__contentitem_tag_map as map ')
            ->innerJoin('#__content as c on c.id = map.content_item_id')
            ->innerJoin('#__tags as tags on tags.id = map.tag_id')
            ->innerJoin('#__tags as parent on parent.id = tags.parent_id')
            ->where('c.id = '.(int)$id.' AND map.type_alias like "com_content%"')
        ;
        $db->setQuery($query);
        return $db->loadObjectList();
    }
    private function getAutomsgToken($users)
    {
        $tokens = array();
        $db = $this->getDatabase();
        foreach ($users as $user) {
            $token = $this->checkautomsgtoken($user);
            if ($token) {// token found
                $tokens[$user] = $token;
            }
        }
        return $tokens;
    }
    /* check if automsg token exists.
    *  if it does not, create it
    */
    protected function checkautomsgtoken($userId)
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
                 ->select(
                     [
                            $db->quoteName('profile_value'),
                        ]
                 )
                ->from($db->quoteName('#__user_profiles'))
                ->where($db->quoteName('user_id') . ' = :userid')
                ->where($db->quoteName('profile_key') . ' LIKE '.$db->quote('profile_automsg.token'))
                ->bind(':userid', $userId, ParameterType::INTEGER);

        $db->setQuery($query);
        $result = $db->loadResult();
        if ($result) {
            return $result;
        } // automsg token already exists => exit
        // create a token
        $query = $db->getQuery(true)
                ->insert($db->quoteName('#__user_profiles'));
        $token = mb_strtoupper(strval(bin2hex(openssl_random_pseudo_bytes(16))));
        $order = 2;
        $query->values(
            implode(
                ',',
                $query->bindArray(
                    [
                        $userId,
                        'profile_automsg.token',
                        $token,
                        $order++,
                    ],
                    [
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::INTEGER,
                    ]
                )
            )
        );
        $db->setQuery($query);
        $db->execute();
        return $token;
    }

}
