<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\errors\SiteNotFoundException;
use craft\events\ConfigEvent;
use craft\events\DeleteSiteEvent;
use craft\events\ReorderSitesEvent;
use craft\events\SiteEvent;
use craft\events\SiteGroupEvent;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\Site;
use craft\models\SiteGroup;
use craft\queue\jobs\PropagateElements;
use craft\records\Site as SiteRecord;
use craft\records\SiteGroup as SiteGroupRecord;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\db\Exception as DbException;

/**
 * Sites service.
 * An instance of the Sites service is globally accessible in Craft via [[\craft\base\ApplicationTrait::getSites()|`Craft::$app->sites`]].
 *
 * @property int[] $allSiteIds all of the site IDs
 * @property Site|null $currentSite the current site
 * @property int[] $editableSiteIds all of the site IDs that are editable by the current user
 * @property Site $primarySite the primary site
 * @property int $totalSites the total number of sites
 * @property int $totalEditableSites the total number of sites that are editable by the current user
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Sites extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SiteGroupEvent The event that is triggered before a site group is saved.
     */
    const EVENT_BEFORE_SAVE_SITE_GROUP = 'beforeSaveSiteGroup';

    /**
     * @event SiteGroupEvent The event that is triggered after a site group is saved.
     */
    const EVENT_AFTER_SAVE_SITE_GROUP = 'afterSaveSiteGroup';

    /**
     * @event SiteGroupEvent The event that is triggered before a site group is deleted.
     */
    const EVENT_BEFORE_DELETE_SITE_GROUP = 'beforeDeleteSiteGroup';

    /**
     * @event SiteGroupEvent The event that is triggered after a site group is deleted.
     */
    const EVENT_AFTER_DELETE_SITE_GROUP = 'afterDeleteSiteGroup';

    /**
     * @event SiteEvent The event that is triggered before a site is saved.
     */
    const EVENT_BEFORE_SAVE_SITE = 'beforeSaveSite';

    /**
     * @event SiteEvent The event that is triggered after a site is saved.
     */
    const EVENT_AFTER_SAVE_SITE = 'afterSaveSite';

    /**
     * @event ReorderSitesEvent The event that is triggered before the sites are reordered.
     */
    const EVENT_BEFORE_REORDER_SITES = 'beforeReorderSites';

    /**
     * @event ReorderSitesEvent The event that is triggered after the sites are reordered.
     */
    const EVENT_AFTER_REORDER_SITES = 'afterReorderSites';

    /**
     * @event SiteEvent The event that is triggered after the primary site has changed
     */
    const EVENT_AFTER_CHANGE_PRIMARY_SITE = 'afterChangePrimarySite';

    /**
     * @event DeleteSiteEvent The event that is triggered before a site is deleted.
     *
     * You may set [[SiteEvent::isValid]] to `false` to prevent the site from getting deleted.
     */
    const EVENT_BEFORE_DELETE_SITE = 'beforeDeleteSite';

    /**
     * @event DeleteSiteEvent The event that is triggered after a site is deleted.
     */
    const EVENT_AFTER_DELETE_SITE = 'afterDeleteSite';

    const CONFIG_SITEGROUP_KEY = 'siteGroups';
    const CONFIG_SITES_KEY = 'sites';

    // Properties
    // =========================================================================

    /**
     * @var bool
     * @see getAllGroups()
     */
    private $_fetchedAllGroups = false;

    /**
     * @var
     * @see getGroupById()
     */
    private $_groupsById;

    /**
     * @var int[]|null
     * @see getEditableSiteIds()
     */
    private $_editableSiteIds;

    /**
     * @var Site[]
     * @see getSiteById()
     */
    private $_sitesById;

    /**
     * @var Site[]
     * @see getSiteByUid()
     */
    private $_sitesByUid;

    /**
     * @var Site[]
     * @see getSiteByHandle()
     */
    private $_sitesByHandle;

    /**
     * @var Site|null the current site
     * @see getCurrentSite()
     * @see setCurrentSite()
     */
    private $_currentSite;

    /**
     * @var Site|null
     * @see getPrimarySite()
     */
    private $_primarySite;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        // No technical reason to put this here, but it's sortof related
        if (defined('CRAFT_LOCALE')) {
            Craft::$app->getDeprecator()->log('CRAFT_LOCALE', 'The CRAFT_LOCALE constant has been deprecated. Use CRAFT_SITE instead, which can be set to a site ID or handle.');
        }

        // Load all the sites up front
        $this->_loadAllSites();
    }

    // Groups
    // -------------------------------------------------------------------------

    /**
     * Returns all site groups.
     *
     * @return SiteGroup[] The site groups
     */
    public function getAllGroups(): array
    {
        if ($this->_fetchedAllGroups) {
            return array_values($this->_groupsById);
        }

        $this->_groupsById = [];
        $results = $this->_createGroupQuery()->all();

        foreach ($results as $result) {
            $group = new SiteGroup($result);
            $this->_groupsById[$group->id] = $group;
        }

        $this->_fetchedAllGroups = true;

        return array_values($this->_groupsById);
    }

    /**
     * Returns a site group by its ID.
     *
     * @param int $groupId The site group’s ID
     * @return SiteGroup|null The site group, or null if it doesn’t exist
     */
    public function getGroupById(int $groupId)
    {
        if ($this->_groupsById !== null && array_key_exists($groupId, $this->_groupsById)) {
            return $this->_groupsById[$groupId];
        }

        if ($this->_fetchedAllGroups) {
            return null;
        }

        $result = $this->_createGroupQuery()
            ->where(['id' => $groupId])
            ->one();

        if (!$result) {
            return $this->_groupsById[$groupId] = null;
        }

        return $this->_groupsById[$groupId] = new SiteGroup($result);
    }

    /**
     * Saves a site group.
     *
     * @param SiteGroup $group The site group to be saved
     * @param bool $runValidation Whether the group should be validated
     * @return bool Whether the site group was saved successfully
     */
    public function saveGroup(SiteGroup $group, bool $runValidation = true): bool
    {
        $isNewGroup = !$group->id;

        // Fire a 'beforeSaveSiteGroup' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_SITE_GROUP)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_SITE_GROUP, new SiteGroupEvent([
                'group' => $group,
                'isNew' => $isNewGroup,
            ]));
        }

        if ($runValidation && !$group->validate()) {
            Craft::info('Site group not saved due to validation error.', __METHOD__);
            return false;
        }

        $projectConfig = Craft::$app->getProjectConfig();
        $configData = [
            'name' => $group->name
        ];

        if ($isNewGroup) {
            $uid = StringHelper::UUID();
        } else {
            $groupRecord = $this->_getGroupRecord($group->id);
            $uid = $groupRecord->uid;
        }

        $projectConfig->save(self::CONFIG_SITEGROUP_KEY . '.' . $uid, $configData);

        // Now that we have an ID, save it on the model & models
        if ($isNewGroup) {
            $group->id = Db::idByUid('{{%sitegroups}}', $uid);
        }

        $group->uid = $uid;

        // Fire an 'afterSaveSiteGroup' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_SITE_GROUP)) {
            $this->trigger(self::EVENT_AFTER_SAVE_SITE_GROUP, new SiteGroupEvent([
                'group' => $group,
                'isNew' => $isNewGroup,
            ]));
        }

        return true;
    }

    /**
     * Handle site group change
     *
     * @param ConfigEvent $event
     */
    public function handleChangedGroup(ConfigEvent $event)
    {
        $data = $event->newValue;
        $uid = $event->tokenMatches[0];

        $groupRecord = $this->_getGroupRecord($uid);

        // If this is a new group, set the UID we want.
        if (!$groupRecord->id) {
            $groupRecord->uid = $uid;
        }

        $groupRecord->name = $data['name'];
        $groupRecord->save(false);
    }

    /**
     * Handle site group getting deleted.
     *
     * @param ConfigEvent $event
     */
    public function handleDeletedGroup(ConfigEvent $event)
    {
        $uid = $event->tokenMatches[0];
        $groupRecord = $this->_getGroupRecord($uid);

        if (!$groupRecord->id) {
            return;
        }

        $groupRecord->softDelete();

        // Delete our cache of it
        unset($this->_groupsById[$groupRecord->id]);
    }

    /**
     * Deletes a site group by its ID.
     *
     * @param int $groupId The site group’s ID
     * @return bool Whether the site group was deleted successfully
     */
    public function deleteGroupById(int $groupId): bool
    {
        $group = $this->getGroupById($groupId);

        if (!$group) {
            return false;
        }

        return $this->deleteGroup($group);
    }

    /**
     * Deletes a site group.
     *
     * @param SiteGroup $group The site group
     * @return bool Whether the site group was deleted successfully
     */
    public function deleteGroup(SiteGroup $group): bool
    {
        if ($this->getSitesByGroupId($group->id)) {
            Craft::warning('Attempted to delete a site group that still had sites assigned to it.', __METHOD__);
            return false;
        }

        /** @var SiteGroupRecord $groupRecord */
        $groupRecord = SiteGroupRecord::find()
            ->where(['id' => $group->id])
            ->one();

        if (!$groupRecord) {
            return false;
        }

        // Fire a 'beforeDeleteSiteGroup' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_SITE_GROUP)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_SITE_GROUP, new SiteGroupEvent([
                'group' => $group
            ]));
        }

        Craft::$app->getProjectConfig()->remove(self::CONFIG_SITEGROUP_KEY . '.' . $group->uid);

        // Delete our cache of it
        unset($this->_groupsById[$group->id]);

        // Fire an 'afterDeleteSiteGroup' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_SITE_GROUP)) {
            $this->trigger(self::EVENT_AFTER_DELETE_SITE_GROUP, new SiteGroupEvent([
                'group' => $group
            ]));
        }

        return true;
    }

    // Sites
    // -------------------------------------------------------------------------

    /**
     * Returns all of the site IDs.
     *
     * @return int[] All the sites’ IDs
     */
    public function getAllSiteIds(): array
    {
        return array_keys($this->_sitesById);
    }

    /**
     * Returns a site by it's UID.
     *
     * @return Site the site
     * @throws SiteNotFoundException if no sites exist
     */
    public function getSiteByUid(string $uid): Site
    {
        if (!isset($this->_sitesByUid[$uid])) {
            throw new SiteNotFoundException('Site with UID ”' . $uid . '“ not found!');
        }

        return $this->_sitesByUid[$uid];
    }

    /**
     * Returns whether the current site has been set yet.
     *
     * @return bool
     */
    public function getHasCurrentSite(): bool
    {
        return $this->_currentSite !== null;
    }

    /**
     * Returns the current site.
     *
     * @return Site the current site
     * @throws SiteNotFoundException if no sites exist
     */
    public function getCurrentSite(): Site
    {
        if ($this->_currentSite !== null) {
            return $this->_currentSite;
        }

        // Default to the primary site
        return $this->_currentSite = $this->getPrimarySite();
    }

    /**
     * Sets the current site.
     *
     * @param Site|string|int|null $site the current site, or its handle/ID, or null
     * @throws InvalidArgumentException if $site is invalid
     */
    public function setCurrentSite($site)
    {
        // In case this was called from the constructor...
        $this->_loadAllSites();

        if ($site === null) {
            $this->_currentSite = null;
            return;
        }

        if ($site instanceof Site) {
            $this->_currentSite = $site;
        } else if (is_numeric($site)) {
            $this->_currentSite = $this->getSiteById($site);
        } else {
            $this->_currentSite = $this->getSiteByHandle($site);
        }

        // Did something go wrong?
        if (!$this->_currentSite) {
            // Fail silently if Craft isn't installed yet or is in the middle of updating
            if (Craft::$app->getIsInstalled() && !Craft::$app->getUpdates()->getIsCraftDbMigrationNeeded()) {
                throw new InvalidArgumentException('Invalid site: ' . $site);
            }
            return;
        }

        // Update the app language if this is a site request
        // (make sure the request component has been initialized first so we don't create an infinite loop)
        if (Craft::$app->has('request', true) && Craft::$app->getRequest()->getIsSiteRequest()) {
            Craft::$app->language = $this->_currentSite->language;
        }
    }

    /**
     * Returns the primary site. The primary site is whatever is listed first in Settings > Sites in the
     * control panel.
     *
     * @return Site The primary site
     * @throws SiteNotFoundException if no sites exist
     */
    public function getPrimarySite(): Site
    {
        if ($this->_primarySite === null) {
            throw new SiteNotFoundException('No primary site exists');
        }

        return $this->_primarySite;
    }

    /**
     * Returns all of the site IDs that are editable by the current user.
     *
     * @return array All the editable sites’ IDs
     */
    public function getEditableSiteIds(): array
    {
        if (!Craft::$app->getIsMultiSite()) {
            return $this->getAllSiteIds();
        }

        if ($this->_editableSiteIds !== null) {
            return $this->_editableSiteIds;
        }

        $this->_editableSiteIds = [];

        foreach ($this->getAllSites() as $site) {
            if (Craft::$app->getUser()->checkPermission('editSite:' . $site->uid)) {
                $this->_editableSiteIds[] = $site->id;
            }
        }

        return $this->_editableSiteIds;
    }

    /**
     * Returns all sites.
     *
     * @return Site[] All the sites
     */
    public function getAllSites(): array
    {
        return array_values($this->_sitesById);
    }

    /**
     * Returns all editable sites.
     *
     * @return Site[] All the editable sites
     */
    public function getEditableSites(): array
    {
        $editableSiteIds = $this->getEditableSiteIds();
        $editableSites = [];

        foreach ($this->getAllSites() as $site) {
            if (in_array($site->id, $editableSiteIds, false)) {
                $editableSites[] = $site;
            }
        }

        return $editableSites;
    }

    /**
     * Returns sites by a group ID.
     *
     * @param int $groupId
     * @return Site[]
     */
    public function getSitesByGroupId(int $groupId): array
    {
        $sites = [];
        $sortOrders = [];

        foreach ($this->getAllSites() as $site) {
            if ($site->groupId == $groupId) {
                $sites[] = $site;
                $sortOrders[] = (int)$site->sortOrder;
            }
        }

        array_multisort($sortOrders, SORT_NUMERIC, $sites);

        return $sites;
    }

    /**
     * Gets the total number of sites.
     *
     * @return int
     */
    public function getTotalSites(): int
    {
        return count($this->getAllSites());
    }

    /**
     * Gets the total number of sites that are editable by the current user.
     *
     * @return int
     */
    public function getTotalEditableSites(): int
    {
        return count($this->getEditableSiteIds());
    }

    /**
     * Returns a site by its ID.
     *
     * @param int $siteId
     * @return Site|null
     */
    public function getSiteById(int $siteId)
    {
        return $this->_sitesById[$siteId] ?? null;
    }

    /**
     * Returns a site by its handle.
     *
     * @param string $siteHandle
     * @return Site|null
     */
    public function getSiteByHandle(string $siteHandle)
    {
        return $this->_sitesByHandle[$siteHandle] ?? null;
    }

    /**
     * Saves a site.
     *
     * @param Site $site The site to be saved
     * @param bool $runValidation Whether the site should be validated
     * @return bool
     * @throws SiteNotFoundException if $site->id is invalid
     * @throws \Throwable if reasons
     */
    public function saveSite(Site $site, bool $runValidation = true): bool
    {
        $isNewSite = !$site->id;

        if (!empty($this->_sitesById)) {
            // Did the primary site just change?
            $oldPrimarySiteId = $this->getPrimarySite()->id;
        } else {
            $oldPrimarySiteId = null;
        }

        // Fire a 'beforeSaveSite' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_SITE)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_SITE, new SiteEvent([
                'site' => $site,
                'isNew' => $isNewSite,
                'oldPrimarySiteId' => $oldPrimarySiteId,
            ]));
        }

        if ($runValidation && !$site->validate()) {
            Craft::info('Site not saved due to validation error.', __METHOD__);
            return false;
        }

        $groupRecord = $this->_getGroupRecord($site->groupId);

        $projectConfig = Craft::$app->getProjectConfig();
        $configData = [
            'siteGroup' => $groupRecord->uid,
            'name' => $site->name,
            'handle' => $site->handle,
            'language' => $site->language,
            'hasUrls' => $site->hasUrls,
            'baseUrl' => $site->baseUrl,
            'sortOrder' => $site->sortOrder,
            'primary' => $site->primary,
        ];

        if ($isNewSite) {
            $uid = StringHelper::UUID();
            $configData['sortOrder'] = ((int)(new Query())
                    ->from(['{{%sites}}'])
                    ->where(['dateDeleted' => null])
                    ->max('[[sortOrder]]')) + 1;
        } else {
            $uid = Db::uidById('{{%sites}}', $site->id);
        }

        $configPath = self::CONFIG_SITES_KEY . '.' . $uid;
        $projectConfig->save($configPath, $configData);

        // Now that we have a site ID, save it on the model
        if ($isNewSite) {
            $site->id = Db::idByUid('{{%sites}}', $uid);
            $site->uid = $uid;
        }

        // Update our cache of the site
        $this->_sitesById[$site->id] = $site;
        $this->_sitesByHandle[$site->handle] = $site;

        // Fire an 'afterSaveSite' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_SITE)) {
            $this->trigger(self::EVENT_AFTER_SAVE_SITE, new SiteEvent([
                'site' => $site,
                'isNew' => $isNewSite,
                'oldPrimarySiteId' => $oldPrimarySiteId,
            ]));
        }

        return true;
    }

    /**
     * Handle site changes.
     *
     * @param ConfigEvent $event
     * @throws \Throwable
     */
    public function handleChangedSite(ConfigEvent $event)
    {
        $siteUid = $event->tokenMatches[0];
        $data = $event->newValue;
        $groupUid = $data['siteGroup'];

        // Ensure we have the site group in place first
        Craft::$app->getProjectConfig()->processConfigChanges(self::CONFIG_SITEGROUP_KEY . '.' . $groupUid);

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $siteRecord = $this->_getSiteRecord($siteUid);
            $isNewSite = $siteRecord->getIsNewRecord();
            $groupRecord = $this->_getGroupRecord($groupUid);

            // Shared attributes
            $siteRecord->uid = $siteUid;
            $siteRecord->groupId = $groupRecord['id'];
            $siteRecord->name = $data['name'];
            $siteRecord->handle = $data['handle'];
            $siteRecord->language = $data['language'];
            $siteRecord->hasUrls = $data['hasUrls'];
            $siteRecord->baseUrl = $data['baseUrl'];
            $siteRecord->primary = $data['primary'];
            $siteRecord->sortOrder = $data['sortOrder'];
            $siteRecord->save(false);

            // Force a reload with the fresh data.
            if ($siteRecord->primary && $this->_primarySite === null) {
                $this->_sitesById = null;
                $this->_loadAllSites();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        if (!empty($this->_sitesById)) {
            // Did the primary site just change?
            $oldPrimarySiteId = $this->getPrimarySite()->id;

            if ($data['primary'] && $siteRecord->id != $oldPrimarySiteId) {
                $this->_processNewPrimarySite($oldPrimarySiteId, $siteRecord->id);
            }
        }

        // Refresh sites
        $this->_refreshAllSites();

        if ($isNewSite && !empty($oldPrimarySiteId)) {
            // TODO: Move this code into element/category modules
            // Create site settings for each of the category groups
            $allSiteSettings = (new Query())
                ->select(['groupId', 'uriFormat', 'template', 'hasUrls'])
                ->from(['{{%categorygroups_sites}}'])
                ->where(['siteId' => $oldPrimarySiteId])
                ->all();

            if (!empty($allSiteSettings)) {
                $newSiteSettings = [];

                foreach ($allSiteSettings as $siteSettings) {
                    $newSiteSettings[] = [
                        $siteSettings['groupId'],
                        $siteRecord->id,
                        $siteSettings['uriFormat'],
                        $siteSettings['template'],
                        $siteSettings['hasUrls']
                    ];
                }

                Craft::$app->getDb()->createCommand()
                    ->batchInsert(
                        '{{%categorygroups_sites}}',
                        ['groupId', 'siteId', 'uriFormat', 'template', 'hasUrls'],
                        $newSiteSettings)
                    ->execute();
            }

            // Re-save most localizable element types
            // (skip entries because they only support specific sites)
            // (skip Matrix blocks because they will be re-saved when their owners are re-saved).
            $queue = Craft::$app->getQueue();
            $elementTypes = [
                Asset::class,
                Category::class,
                GlobalSet::class,
                Tag::class,
            ];

            foreach ($elementTypes as $elementType) {
                $queue->push(new PropagateElements([
                    'elementType' => $elementType,
                    'criteria' => [
                        'siteId' => $oldPrimarySiteId,
                        'status' => null,
                        'enabledForSite' => false
                    ],
                    'siteId' => $siteRecord->id,
                ]));
            }
        }
    }

    /**
     * Reorders sites.
     *
     * @param string[] $siteUids The site UIDs in their new order
     * @return bool Whether the sites were reordered successfthe sites are reorderedy
     * @throws \Throwable if reasons
     */
    public function reorderSites(array $siteUids): bool
    {
        // Fire a 'beforeReorderSites' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_REORDER_SITES)) {
            $this->trigger(self::EVENT_BEFORE_REORDER_SITES, new ReorderSitesEvent([
                'siteUids' => $siteUids,
            ]));
        }

        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($siteUids as $sortOrder => $siteUid) {
            $data = $projectConfig->get(self::CONFIG_SITES_KEY . '.' . $siteUid);
            $data['sortOrder'] = $sortOrder + 1;
            $projectConfig->save(self::CONFIG_SITES_KEY . '.' . $siteUid, $data);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_REORDER_SITES)) {
            $this->trigger(self::EVENT_AFTER_REORDER_SITES, new ReorderSitesEvent([
                'siteUids' => $siteUids,
            ]));
        }

        return true;
    }

    /**
     * Deletes a site by its ID.
     *
     * @param int $siteId The site ID to be deleted
     * @param int|null $transferContentTo The site ID that should take over the deleted site’s contents
     * @return bool Whether the site was deleted successfully
     * @throws \Throwable if reasons
     */
    public function deleteSiteById(int $siteId, int $transferContentTo = null): bool
    {
        $site = $this->getSiteById($siteId);

        if (!$site) {
            return false;
        }

        return $this->deleteSite($site, $transferContentTo);
    }

    /**
     * Deletes a site.
     *
     * @param Site $site The site to be deleted
     * @param int|null $transferContentTo The site ID that should take over the deleted site’s contents
     * @return bool Whether the site was deleted successfully
     * @throws Exception if $site is the primary site
     * @throws \Throwable if reasons
     */
    public function deleteSite(Site $site, int $transferContentTo = null): bool
    {
        // Make sure this isn't the primary site
        if ($site->id === $this->_primarySite->id) {
            throw new Exception('You cannot delete the primary site.');
        }

        // Fire a 'beforeDeleteSite' event
        $event = new DeleteSiteEvent([
            'site' => $site,
            'transferContentTo' => $transferContentTo,
        ]);

        $this->trigger(self::EVENT_BEFORE_DELETE_SITE, $event);

        // Make sure the event is giving us the go ahead
        if (!$event->isValid) {
            return false;
        }

        // TODO: Move this code into entries module, etc.
        // Get the section IDs that are enabled for this site
        $sectionIds = (new Query())
            ->select(['sectionId'])
            ->from(['{{%sections_sites}}'])
            ->where(['siteId' => $site->id])
            ->column();

        // Figure out which ones are *only* enabled for this site
        $soloSectionIds = [];

        foreach ($sectionIds as $sectionId) {
            $sectionSiteSettings = Craft::$app->getSections()->getSectionSiteSettings($sectionId);

            if (count($sectionSiteSettings) == 1 && $sectionSiteSettings[0]->siteId == $site->id) {
                $soloSectionIds[] = $sectionId;
            }
        }

        // Did we find any?
        if (!empty($soloSectionIds)) {
            // Should we enable those for a different site?
            if ($transferContentTo !== null) {
                Craft::$app->getDb()->createCommand()
                    ->update(
                        '{{%sections_sites}}',
                        ['siteId' => $transferContentTo],
                        ['sectionId' => $soloSectionIds])
                    ->execute();

                // Get all of the entry IDs in those sections
                $entryIds = (new Query())
                    ->select(['id'])
                    ->from(['{{%entries}}'])
                    ->where(['sectionId' => $soloSectionIds])
                    ->column();

                if (!empty($entryIds)) {
                    // Delete their template caches
                    Craft::$app->getTemplateCaches()->deleteCachesByElementId($entryIds);

                    // Update the entry tables
                    Craft::$app->getDb()->createCommand()
                        ->update(
                            '{{%content}}',
                            ['siteId' => $transferContentTo],
                            ['elementId' => $entryIds])
                        ->execute();

                    Craft::$app->getDb()->createCommand()
                        ->update(
                            '{{%elements_sites}}',
                            ['siteId' => $transferContentTo],
                            ['elementId' => $entryIds])
                        ->execute();

                    Craft::$app->getDb()->createCommand()
                        ->update(
                            '{{%entrydrafts}}',
                            ['siteId' => $transferContentTo],
                            ['entryId' => $entryIds])
                        ->execute();

                    Craft::$app->getDb()->createCommand()
                        ->update(
                            '{{%entryversions}}',
                            ['siteId' => $transferContentTo],
                            ['entryId' => $entryIds])
                        ->execute();

                    Craft::$app->getDb()->createCommand()
                        ->update(
                            '{{%relations}}',
                            ['sourceSiteId' => $transferContentTo],
                            [
                                'and',
                                ['sourceId' => $entryIds],
                                ['not', ['sourceSiteId' => null]]
                            ])
                        ->execute();

                    // All the Matrix tables
                    $blockIds = (new Query())
                        ->select(['id'])
                        ->from(['{{%matrixblocks}}'])
                        ->where(['ownerId' => $entryIds])
                        ->column();

                    if (!empty($blockIds)) {
                        Craft::$app->getDb()->createCommand()
                            ->update(
                                '{{%matrixblocks}}',
                                ['ownerSiteId' => $transferContentTo],
                                [
                                    'and',
                                    ['id' => $blockIds],
                                    ['not', ['ownerSiteId' => null]]
                                ])
                            ->execute();

                        Craft::$app->getDb()->createCommand()
                            ->delete(
                                '{{%elements_sites}}',
                                [
                                    'elementId' => $blockIds,
                                    'siteId' => $transferContentTo
                                ])
                            ->execute();

                        Craft::$app->getDb()->createCommand()
                            ->update(
                                '{{%elements_sites}}',
                                ['siteId' => $transferContentTo],
                                [
                                    'elementId' => $blockIds,
                                    'siteId' => $site->id
                                ])
                            ->execute();

                        $matrixTablePrefix = Craft::$app->getDb()->getSchema()->getRawTableName('{{%matrixcontent_}}');
                        $tablePrefixLength = strlen(Craft::$app->getDb()->tablePrefix);

                        foreach (Craft::$app->getDb()->getSchema()->getTableNames() as $tableName) {
                            if (strpos($tableName, $matrixTablePrefix) === 0) {
                                $tableName = substr($tableName, $tablePrefixLength);

                                Craft::$app->getDb()->createCommand()
                                    ->delete(
                                        $tableName,
                                        [
                                            'elementId' => $blockIds,
                                            'siteId' => $transferContentTo
                                        ])
                                    ->execute();

                                Craft::$app->getDb()->createCommand()
                                    ->update(
                                        $tableName,
                                        ['siteId' => $transferContentTo],
                                        [
                                            'elementId' => $blockIds,
                                            'siteId' => $site->id
                                        ])
                                    ->execute();
                            }
                        }

                        Craft::$app->getDb()->createCommand()
                            ->update(
                                '{{%relations}}',
                                ['sourceSiteId' => $transferContentTo],
                                [
                                    'and',
                                    ['sourceId' => $blockIds],
                                    ['not', ['sourceSiteId' => null]]
                                ])
                            ->execute();
                    }
                }
            } else {
                // Delete those sections
                foreach ($soloSectionIds as $sectionId) {
                    Craft::$app->getSections()->deleteSectionById($sectionId);
                }
            }
        }

        Craft::$app->getProjectConfig()->remove(self::CONFIG_SITES_KEY . '.' . $site->uid);

        // Fire an 'afterDeleteSite' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_SITE)) {
            $this->trigger(self::EVENT_AFTER_DELETE_SITE, new DeleteSiteEvent([
                'site' => $site,
                'transferContentTo' => $transferContentTo,
            ]));
        }

        return true;
    }

    /**
     * Handle a deleted Site.
     *
     * @param ConfigEvent $event
     * @throws DbException
     * @throws \Throwable
     * @throws \yii\base\NotSupportedException
     */
    public function handleDeletedSite(ConfigEvent $event)
    {
        $siteUid = $event->tokenMatches[0];
        $siteRecord = $this->_getSiteRecord($siteUid);

        if (!$siteRecord->id) {
            return;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            Craft::$app->getDb()->createCommand()
                ->softDelete('{{%sites}}', ['id' => $siteRecord->id])
                ->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Refresh sites
        $this->_refreshAllSites();
    }

    /**
     * Restores a site by its ID.
     *
     * @param int $id The site’s ID
     * @return bool Whether the site was restored successfully
     */
    public function restoreSiteById(int $id): bool
    {
        $affectedRows = Craft::$app->getDb()->createCommand()
            ->restore('{{%sites}}', ['id' => $id])
            ->execute();
        return (bool)$affectedRows;
    }

    // Private Methods
    // =========================================================================

    /**
     * Refresh the status of all sites based on the DB data.
     *
     * @throws DbException
     */
    private function _refreshAllSites()
    {
        $this->_sitesById = null;
        $this->_loadAllSites();
        Craft::$app->getIsMultiSite(true);
    }

    /**
     * Loads all the sites.
     */
    private function _loadAllSites()
    {
        if ($this->_sitesById !== null) {
            return;
        }

        $this->_sitesById = [];
        $this->_sitesByHandle = [];
        $this->_sitesByUid = [];

        if (!Craft::$app->getIsInstalled()) {
            return;
        }

        try {
            $results = (new Query())
                ->select([
                    's.id',
                    's.groupId',
                    's.name',
                    's.handle',
                    'language',
                    's.primary',
                    's.hasUrls',
                    's.baseUrl',
                    's.sortOrder',
                    's.uid',
                ])
                ->from(['{{%sites}} s'])
                ->innerJoin('{{%sitegroups}} sg', '[[sg.id]] = [[s.groupId]]')
                ->where(['s.dateDeleted' => null])
                ->andWhere(['sg.dateDeleted' => null])
                ->orderBy(['sg.name' => SORT_ASC, 's.sortOrder' => SORT_ASC])
                ->all();
        } catch (DbException $e) {
            // todo: remove this after the next breakpoint
            // If the error code is 42S02 (MySQL) or 42P01 (PostgreSQL), the sites table probably doesn't exist yet
            if (isset($e->errorInfo[0]) && in_array($e->errorInfo[0], ['42S02', '42P01'], true)) {
                return;
            }
            // If the error code is 42S22 (MySQL) or 42703 (PostgreSQL), then the sites table doesn't have a groupId or dateDeleted column yet
            if (isset($e->errorInfo[0]) && in_array($e->errorInfo[0], ['42S22', '42703'], true)) {
                $results = (new Query())
                    ->select([
                        's.id',
                        's.name',
                        's.handle',
                        'language',
                        's.primary',
                        's.hasUrls',
                        's.baseUrl',
                        's.sortOrder',
                        's.uid',
                    ])
                    ->from(['{{%sites}} s'])
                    ->orderBy(['s.name' => SORT_ASC])
                    ->all();
            }
            if (!isset($results)) {
                /** @noinspection PhpUnhandledExceptionInspection */
                throw $e;
            }
        }

        // Check for results because during installation, the transaction hasn't been committed yet.
        if (!empty($results)) {
            $generalConfig = Craft::$app->getConfig()->getGeneral();

            foreach ($results as $i => $result) {
                $site = new Site($result);
                $this->_sitesById[$site->id] = $site;
                $this->_sitesByHandle[$site->handle] = $site;
                $this->_sitesByUid[$site->uid] = $site;

                if ($site->primary) {
                    $this->_primarySite = $site;

                    if (is_string($generalConfig->siteName)) {
                        $site->overrideName($generalConfig->siteName);
                    }
                    if (is_string($generalConfig->siteUrl)) {
                        $site->overrideBaseUrl($generalConfig->siteUrl);
                    }
                }

                if (is_array($generalConfig->siteName) && isset($generalConfig->siteName[$site->handle])) {
                    $site->overrideName($generalConfig->siteName[$site->handle]);
                }
                if (is_array($generalConfig->siteUrl) && isset($generalConfig->siteUrl[$site->handle])) {
                    $site->overrideBaseUrl($generalConfig->siteUrl[$site->handle]);
                }
            }
        }
    }

    /**
     * Returns a Query object prepped for retrieving groups.
     *
     * @return Query
     */
    private function _createGroupQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'name',
                'uid',
            ])
            ->from(['{{%sitegroups}}'])
            ->orderBy(['name' => SORT_ASC]);
    }

    /**
     * Gets a site group record or creates a new one.
     *
     * @param mixed $criteria ID or UID of the site group.
     * @return SiteGroupRecord
     */
    private function _getGroupRecord($criteria): SiteGroupRecord
    {
        if (is_numeric($criteria)) {
            $groupRecord = SiteGroupRecord::findOne($criteria);
        } else if (\is_string($criteria)) {
            $groupRecord = SiteGroupRecord::findOne(['uid' => $criteria]);
        }

        return $groupRecord ?? new SiteGroupRecord();
    }

    /**
     * Gets a site record or creates a new one.
     *
     * @param mixed $criteria ID or UID of the site group.
     * @return SiteGroupRecord
     */
    private function _getSiteRecord($criteria): SiteRecord
    {
        if (is_numeric($criteria)) {
            $siteRecord = SiteRecord::findOne($criteria);
        } else if (\is_string($criteria)) {
            $siteRecord = SiteRecord::findOne(['uid' => $criteria]);
        }

        return $siteRecord ?? new SiteRecord();
    }

    /**
     * Handles things that happen when there's a new primary site
     *
     * @param int $oldPrimarySiteId
     * @param int $newPrimarySiteId
     * @throws \Throwable
     */
    private function _processNewPrimarySite(int $oldPrimarySiteId, int $newPrimarySiteId)
    {
        App::maxPowerCaptain();

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()
                ->update('{{%sites}}', ['primary' => false], ['id' => $oldPrimarySiteId])
                ->execute();
            $db->createCommand()
                ->update('{{%sites}}', ['primary' => true], ['id' => $newPrimarySiteId])
                ->execute();

            // Update all of the non-localized elements
            $nonLocalizedElementTypes = [];

            foreach (Craft::$app->getElements()->getAllElementTypes() as $elementType) {
                /** @var Element|string $elementType */
                if (!$elementType::isLocalized()) {
                    $nonLocalizedElementTypes[] = $elementType;
                }
            }

            if (!empty($nonLocalizedElementTypes)) {
                $elementIds = (new Query())
                    ->select(['id'])
                    ->from(['{{%elements}}'])
                    ->where(['type' => $nonLocalizedElementTypes])
                    ->column();

                if (!empty($elementIds)) {
                    // To be sure we don't hit any unique constraint database errors, first make sure there are no rows for
                    // these elements that don't currently use the old primary site ID
                    $deleteCondition = [
                        'and',
                        ['elementId' => $elementIds],
                        ['not', ['siteId' => $oldPrimarySiteId]]
                    ];

                    $db->createCommand()
                        ->delete('{{%elements_sites}}', $deleteCondition)
                        ->execute();
                    $db->createCommand()
                        ->delete('{{%content}}', $deleteCondition)
                        ->execute();

                    // Now swap the sites
                    $updateColumns = ['siteId' => $newPrimarySiteId];
                    $updateCondition = ['elementId' => $elementIds];

                    $db->createCommand()
                        ->update('{{%elements_sites}}', $updateColumns, $updateCondition)
                        ->execute();
                    $db->createCommand()
                        ->update('{{%content}}', $updateColumns, $updateCondition)
                        ->execute();
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Set the new primary site by forcing a reload from the DB.
        $this->_refreshAllSites();

        // Fire an afterChangePrimarySite event
        if ($this->hasEventHandlers(self::EVENT_AFTER_CHANGE_PRIMARY_SITE)) {
            $this->trigger(self::EVENT_AFTER_CHANGE_PRIMARY_SITE, new SiteEvent([
                'site' => $this->_primarySite,
            ]));
        }
    }
}
