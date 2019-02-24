<?php
namespace verbb\workflow\services;

use verbb\workflow\Workflow;
use verbb\workflow\elements\Submission;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\db\Table;
use craft\events\DraftEvent;
use craft\events\ModelEvent;
use craft\helpers\Db;
use craft\helpers\UrlHelper;

class Service extends Component
{
    // Public Methods
    // =========================================================================

    public function onBeforeSaveEntry(ModelEvent $event)
    {
        $action = Craft::$app->getRequest()->getBodyParam('workflow-action');

        // Saving an entry doesn't trigger its validation - only when you're trying to publish it.
        // It doesn't make sense to submit a non-validated entry for review (that's what drafts
        // are for), so manually trigger validation for a workflow submission.
        if ($action === 'save-submission') {
            // Content validation won't trigger unless its set to 'live' - but that won't happen because an editor
            // can't publish. We quickly switch it on to make sure the entry validates correctly.
            $event->sender->setScenario(Element::SCENARIO_LIVE);
            $event->sender->validate();
        }

        // If we are approving a submission, make sure to make it live
        if ($action === 'approve-submission') {
            $event->sender->enabled = true;
        }
    }

    public function onAfterSaveEntry(ModelEvent $event)
    {
        $action = Craft::$app->getRequest()->getBodyParam('workflow-action');
        $redirect = Craft::$app->getRequest()->getBodyParam('redirect');

        if (!$action || $event->sender->propagating || isset($event->sender->draftId)) {
            return;
        }

        Craft::$app->runAction('workflow/submissions/' . $action, ['entry' => $event->sender]);

        $url = Craft::$app->getView()->renderObjectTemplate($redirect, $event->sender);
        $url = UrlHelper::url($url);

        return Craft::$app->getResponse()->redirect($url, 302)->send();
    }

    public function onAfterSaveEntryDraft(DraftEvent $event)
    {
        $action = Craft::$app->getRequest()->getBodyParam('workflow-action');
        $redirect = Craft::$app->getRequest()->getBodyParam('redirect');

        if (!$action) {
            return;
        }

        Craft::$app->runAction('workflow/submissions/' . $action, ['draft' => $event->draft]);

        $url = Craft::$app->getView()->renderObjectTemplate($redirect, $event->draft);
        $url = UrlHelper::url($url);

        return Craft::$app->getResponse()->redirect($url, 302)->send();
    }

    public function onAfterPublishEntryDraft(DraftEvent $event)
    {
        $action = Craft::$app->getRequest()->getBodyParam('workflow-action');

        if (!$action) {
            return;
        }

        Craft::$app->runAction('workflow/submissions/' . $action, ['draft' => $event->draft]);

        // Approving a draft should redirect properly
        $redirect = $event->draft->getCpEditUrl();

        return Craft::$app->getResponse()->redirect($redirect, 302)->send();
    }

    public function renderEntrySidebar(&$context)
    {
        $settings = Workflow::$plugin->getSettings();
        $currentUser = Craft::$app->getUser()->getIdentity();

        if (!$settings->editorUserGroup || !$settings->publisherUserGroup) {
            Workflow::log('Editor and Publisher groups not set in settings.');

            return;
        }

        $editorGroup = Craft::$app->userGroups->getGroupByUid($settings->editorUserGroup);
        $publisherGroup = Craft::$app->userGroups->getGroupByUid($settings->publisherUserGroup);

        if (!$currentUser) {
            Workflow::log('No current user.');

            return;
        }

        // Only show the sidebar submission button for editors
        if ($currentUser->isInGroup($editorGroup)) {
            return $this->_renderEntrySidebarPanel($context, 'editor-pane');
        }

        // Show another information panel for publishers (if there's submission info)
        if ($currentUser->isInGroup($publisherGroup)) {
            return $this->_renderEntrySidebarPanel($context, 'publisher-pane');
        }
    }


    // Private Methods
    // =========================================================================

    private function _renderEntrySidebarPanel($context, $template)
    {
        $settings = Workflow::$plugin->getSettings();

        Workflow::log('Try to render ' . $template);

        // Make sure workflow is enabled for this section - or all section
        if (!$settings->enabledSections) {
            Workflow::log('New enabled sections.');

            return;
        }

        if ($settings->enabledSections != '*') {
            $enabledSectionIds = Db::idsByUids(Table::SECTIONS, $settings->enabledSections);

            if (!in_array($context['entry']->sectionId, $enabledSectionIds)) {
                Workflow::log('Entry not in allowed section.');

                return;
            }
        }

        // See if there's an existing submission
        $ownerId = $context['entry']->id ?? ':empty:';
        $draftId = $context['draftId'] ?? ':empty:';
        $siteId = $context['entry']['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id;

        $submissions = Submission::find()
            ->ownerId($ownerId)
            ->ownerSiteId($siteId)
            ->draftId($draftId)
            ->all();

        Workflow::log('Rendering ' . $template . ' for #' . $context['entry']->id);

        return Craft::$app->view->renderTemplate('workflow/_includes/' . $template, [
            'context' => $context,
            'submissions' => $submissions,
        ]);
    }

}