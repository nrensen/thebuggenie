<?php

namespace thebuggenie\core\modules\main\controllers;

use thebuggenie\core\framework,
    thebuggenie\core\entities,
    thebuggenie\core\entities\tables,
    thebuggenie\modules\agile,
    thebuggenie\core\entities\Comment,
    thebuggenie\core\helpers\Pagination;

/**
 * @property entities\Project $selected_project
 * @property entities\Client $client
 * @property entities\Team $team
 *
 * actions for the main module
 */
class Main extends framework\Action
{

    /**
     * The currently selected project in actions where there is one
     *
     * @access protected
     * @property entities\Project $selected_project
     * @param framework\Request $request
     * @param $action
     */
    public function preExecute(framework\Request $request, $action)
    {
        try
        {
            if ($project_key = $request['project_key'])
                $this->selected_project = entities\Project::getByKey($project_key);
            elseif ($project_id = (int) $request['project_id'])
                $this->selected_project = tables\Projects::getTable()->selectById($project_id);

            if ($this->selected_project instanceof entities\Project && !$this->selected_project->hasAccess($this->getUser())) {
                $this->selected_project = null;
            }

            framework\Context::setCurrentProject($this->selected_project);
        }
        catch (\Exception $e)
        {

        }
    }

    /**
     * Detect and return the requested issue
     *
     * @param framework\Request $request
     * @return entities\Issue
     * @throws \Exception
     */
    protected function _getIssueFromRequest(framework\Request $request)
    {
        $issue = null;
        if ($issue_no = framework\Context::getRequest()->getParameter('issue_no'))
        {
            $issue = entities\Issue::getIssueFromLink($issue_no);
            if ($issue instanceof entities\Issue)
            {
                if (!$this->selected_project instanceof entities\Project || $issue->getProjectID() != $this->selected_project->getID())
                {
                    $issue = null;
                }
            }
            else
            {
                framework\Logging::log("Issue no [$issue_no] not a valid issue no", 'main', framework\Logging::LEVEL_WARNING_RISK);
            }
        }
        framework\Logging::log('done (Loading issue)');
        if ($issue instanceof entities\Issue && (!$issue->hasAccess() || $issue->isDeleted()))
            $issue = null;

        return $issue;
    }


    /**
     * Helper method for sorting two issues based on their project and
     * IDs.
     *
     * Sorting algorithm takes into account the following factors (in
     * decreasing weight of importance):
     *
     * - Issue belongs to selected project.
     * - Issue project name (alphabetical sorting).
     * - Issue ID.
     *
     * @param \thebuggenie\core\entities\Issue $a Issue to compare.
     * @param \thebuggenie\core\entities\Issue $b Issue to compare.
     *
     * @return -1 if issue $a comes ahead of issue $b, 0 if issue $a is the same as issue $b, 1 if issue $b comes ahead of issue $a.
     */
    protected function compareIssuesByProject($a, $b)
    {
        $project_a = $a->getProject();
        $project_b = $b->getProject();

        if ($project_a == $this->selected_project && $project_b != $this->selected_project)
        {
            $result = -1;
        }
        elseif ($project_b == $this->selected_project && $project_a != $this->selected_project)
        {
            $result = 1;
        }
        else
        {
            $result = strcasecmp($a->getProject()->getName(), $b->getProject()->getName());

            if ($result == 0)
            {
                $result = $a->getID() > $b->getID();
            }
        }

        return $result;
    }

    /**
     * Go to the next/previous open issue
     *
     * @param \thebuggenie\core\framework\Request $request
     * @throws \Exception
     */
    public function runNavigateIssue(framework\Request $request)
    {
        $issue = $this->_getIssueFromRequest($request);

        if (!$issue instanceof entities\Issue)
        {
            $this->getResponse()->setTemplate('viewissue');
            return;
        }

        do
        {
            if ($issue->getMilestone() instanceof entities\Milestone) {
                if ($request['direction'] == 'next') {
                    $found_issue = tables\Issues::getTable()->getNextIssueFromIssueMilestoneOrderAndMilestoneID($issue->getMilestoneOrder(), $issue->getMilestone()->getID(), $request['mode'] == 'open');
                } else {
                    $found_issue = tables\Issues::getTable()->getPreviousIssueFromIssueMilestoneOrderAndMilestoneID($issue->getMilestoneOrder(), $issue->getMilestone()->getID(), $request['mode'] == 'open');
                }
            } else {
                if ($request['direction'] == 'next') {
                    $found_issue = tables\Issues::getTable()->getNextIssueFromIssueIDAndProjectID($issue->getID(), $issue->getProject()->getID(), $request['mode'] == 'open');
                } else {
                    $found_issue = tables\Issues::getTable()->getPreviousIssueFromIssueIDAndProjectID($issue->getID(), $issue->getProject()->getID(), $request['mode'] == 'open');
                }
            }
            if ($found_issue === null)
                break;
        }
        while ($found_issue instanceof entities\Issue && !$found_issue->hasAccess());

        if ($found_issue instanceof entities\Issue)
        {
            $this->forward($this->getRouting()->generate('viewissue', array('project_key' => $found_issue->getProject()->getKey(), 'issue_no' => $found_issue->getFormattedIssueNo())));
        }
        else
        {
            framework\Context::setMessage('issue_message', $this->getI18n()->__('There are no more issues in that direction.'));
            $this->forward($this->getRouting()->generate('viewissue', array('project_key' => $issue->getProject()->getKey(), 'issue_no' => $issue->getFormattedIssueNo())));
        }
    }

    /**
     * View an issue
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runViewIssue(framework\Request $request)
    {
        framework\Logging::log('Loading issue');

        $issue = $this->_getIssueFromRequest($request);

        if ($issue instanceof entities\Issue)
        {
            if (!array_key_exists('viewissue_list', $_SESSION) || !is_array($_SESSION['viewissue_list']))
            {
                $_SESSION['viewissue_list'] = array();
            }

            $k = array_search($issue->getID(), $_SESSION['viewissue_list']);
            if ($k !== false)
                unset($_SESSION['viewissue_list'][$k]);

            array_push($_SESSION['viewissue_list'], $issue->getID());

            if (count($_SESSION['viewissue_list']) > 10)
                array_shift($_SESSION['viewissue_list']);

            $this->getUser()->markNotificationsRead('issue', $issue->getID());

            framework\Context::getUser()->setNotificationSetting(framework\Settings::SETTINGS_USER_NOTIFY_ITEM_ONCE . '_issue_' . $issue->getID(), false);

            framework\Event::createNew('core', 'viewissue', $issue)->trigger();
        }

        $message = framework\Context::getMessageAndClear('issue_saved');
        $uploaded = framework\Context::getMessageAndClear('issue_file_uploaded');

        if ($request->isPost() && $issue instanceof entities\Issue && $request->hasParameter('issue_action'))
        {
            if ($request['issue_action'] == 'save')
            {
                if (!$issue->hasMergeErrors())
                {
                    try
                    {
                        $issue->getWorkflow()->moveIssueToMatchingWorkflowStep($issue);
                        // Currently if category is changed we want to regenerate permissions since category is used for granting user access.
                        if ($issue->isCategoryChanged())
                        {
                            framework\Event::listen('core', 'thebuggenie\core\entities\Issue::save_pre_notifications', array($this, 'listen_issueCreate'));
                        }
                        $issue->save();
                        framework\Context::setMessage('issue_saved', true);
                        $this->forward($this->getRouting()->generate('viewissue', array('project_key' => $issue->getProject()->getKey(), 'issue_no' => $issue->getFormattedIssueNo())));
                    }
                    catch (\thebuggenie\core\exceptions\WorkflowException $e)
                    {
                        $this->error = $e->getMessage();
                        $this->workflow_error = true;
                    }
                    catch (\Exception $e)
                    {
                        $this->error = $e->getMessage();
                    }
                }
                else
                {
                    $this->issue_unsaved = true;
                }
            }
        }
        elseif (framework\Context::hasMessage('issue_deleted_shown') && (is_null($issue) || ($issue instanceof entities\Issue && $issue->isDeleted())))
        {
            $request_referer = ($request['referer'] ?: isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null);

            if ($request_referer)
            {
                return $this->forward($request_referer);
            }
        }
        elseif (framework\Context::hasMessage('issue_deleted'))
        {
            $this->issue_deleted = framework\Context::getMessageAndClear('issue_deleted');
            framework\Context::setMessage('issue_deleted_shown', true);
        }
        elseif ($message == true)
        {
            $this->issue_saved = true;
        }
        elseif ($uploaded == true)
        {
            $this->issue_file_uploaded = true;
        }
        elseif (framework\Context::hasMessage('issue_error'))
        {
            $this->error = framework\Context::getMessageAndClear('issue_error');
        }
        elseif (framework\Context::hasMessage('issue_message'))
        {
            $this->issue_message = framework\Context::getMessageAndClear('issue_message');
        }

        $this->issue = $issue;
        $event = framework\Event::createNew('core', 'viewissue', $issue)->trigger();
        $this->listenViewIssuePostError($event);
    }

    public function runMoveIssue(framework\Request $request)
    {
        $issue = null;
        $project = null;
        $multi = (bool) $request->getParameter('multi', false);
        try
        {
            if ($issue_id = $request['issue_id']) {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            if ($project_id = $request['project_id']) {
                $project = tables\Projects::getTable()->selectById($project_id);
            }
        } catch (\Exception $e) { }

        if ($issue instanceof entities\Issue && !$issue->hasAccess()) {
            $issue = null;
        }
        if ($project instanceof entities\Project && !$project->hasAccess()) {
            $project = null;
        }

        if (!$issue instanceof entities\Issue)
        {
            if ($multi)
            {
                $this->getResponse()->setHttpStatus(404);
                return $this->renderJSON(array('error' => $this->getI18n()->__('Cannot find the issue specified')));
            }
            return $this->return404(framework\Context::getI18n()->__('Cannot find the issue specified'));
        }

        if (!$issue->canEditIssueDetails()) {
            $this->getResponse()->setHttpStatus(403);
            return $this->forward403($this->getI18n()->__("You don't have permission to move this issue"));
        }

        if (!$project instanceof entities\Project)
        {
            if ($multi)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => $this->getI18n()->__('Cannot find the project specified')));
            }
            return $this->return404(framework\Context::getI18n()->__('Cannot find the project specified'));
        }

        if ($issue->getProject()->getID() != $project->getID())
        {
            $issue->setProject($project);

            if (!$issue->canEditIssueDetails()) {
                $this->getResponse()->setHttpStatus(403);
                return $this->forward403($this->getI18n()->__("You don't have permission to move this issue"));
            }

            $issue->clearUserWorkingOnIssue();
            $issue->clearAssignee();
            $issue->clearOwner();
            $issue->setPercentCompleted(0);
            $issue->setMilestone(null);
            $issue->setIssueNumber(tables\Issues::getTable()->getNextIssueNumberForProductID($project->getID()));
            $step = $issue->getProject()->getWorkflowScheme()->getWorkflowForIssuetype($issue->getIssueType())->getFirstStep();
            $step->applyToIssue($issue);
            $issue->save();
            if ($multi)
            {
                return $this->renderJSON(array('content' => $this->getComponentHTML('issuemoved', compact('issue', 'project'))));
            }
            framework\Context::setMessage('issue_message', framework\Context::getI18n()->__('The issue was moved'));
        }
        else
        {
            if ($multi)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => $this->getI18n()->__('The issue was not moved, since the project is the same')));
            }
            framework\Context::setMessage('issue_error', framework\Context::getI18n()->__('The issue was not moved, since the project is the same'));
        }

        return $this->forward($this->getRouting()->generate('viewissue', array('project_key' => $project->getKey(), 'issue_no' => $issue->getFormattedIssueNo())));
    }

    /**
     * Frontpage
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runIndex(framework\Request $request)
    {
        if (framework\Settings::isSingleProjectTracker())
        {
            if (($projects = entities\Project::getAllRootProjects(false)) && $project = array_shift($projects))
            {
                $this->forward($this->getRouting()->generate('project_dashboard', array('project_key' => $project->getKey())));
            }
        }
        $this->forward403unless($this->getUser()->hasPageAccess('home'));
        $this->links = tables\Links::getTable()->getMainLinks();
        $this->show_project_list = framework\Settings::isFrontpageProjectListVisible();
    }

    /**
     * @param framework\Request $request
     * @Route(url="/projects/list/:list_mode/:project_state/*", name="project_list")
     */
    public function runProjectList(framework\Request $request)
    {
        $list_mode = $request->getParameter('list_mode', 'all');
        $project_state = $request->getParameter('project_state', 'active');
        $paginationOptions = [
            'list_mode' => $list_mode,
            'project_state' => $project_state
        ];

        switch ($list_mode) {
            case 'all':
                $projects = entities\Project::getAllRootProjects(($project_state === 'archived'));
                break;
            case 'team':
                $paginationOptions['team_id'] = $request['team_id'];
                $this->team = tables\Teams::getTable()->selectById($request['team_id']);
                list ($activeProjects, $archivedProjects) = $this->team->getProjects();
                $projects = ($project_state === 'active') ? $activeProjects : $archivedProjects;
                break;
            case 'client':
                $paginationOptions['client_id'] = $request['client_id'];
                $this->client = tables\Clients::getTable()->selectById($request['client_id']);
                list ($activeProjects, $archivedProjects) = $this->client->getProjects();
                $projects = ($project_state === 'active') ? $activeProjects : $archivedProjects;
                break;
        }

        $pagination = new Pagination($projects, $this->getRouting()->generate('project_list', $paginationOptions), $request);
        $this->pagination = $pagination;
        $this->projects = $pagination->getPageItems();
        $this->project_count = count($projects);
        $this->list_mode = $list_mode;
        $this->project_state = $project_state;
        $this->show_project_config_link = $this->getUser()->canAccessConfigurationPage(framework\Settings::CONFIGURATION_SECTION_PROJECTS) && framework\Context::getScope()->hasProjectsAvailable();
    }

    public function runUserdata(framework\Request $request)
    {
        if ($this->getUser()->isGuest())
        {
            return $this->renderJSON(array());
        }
        else
        {
            $data = array();
            if ($request->isPost())
            {
                switch ($request['say'])
                {
                    case 'install-module':
                        try
                        {
                            entities\Module::downloadModule($request['module_key']);
                            $module = entities\Module::installModule($request['module_key']);
                            $data['installed'] = true;
                            $data['module_key'] = $request['module_key'];
                            $data['module'] = $this->getComponentHTML('configuration/modulebox', array('module' => $module));
                        }
                        catch (framework\exceptions\ModuleDownloadException $e)
                        {
                            $this->getResponse()->setHttpStatus(400);
                            switch ($e->getCode())
                            {
                                case framework\exceptions\ModuleDownloadException::JSON_NOT_FOUND:
                                    return $this->renderJSON(array('message' => $this->getI18n()->__('An error occured when trying to retrieve the module data')));
                                    break;
                                case framework\exceptions\ModuleDownloadException::FILE_NOT_FOUND:
                                    return $this->renderJSON(array('message' => $this->getI18n()->__('The module could not be downloaded')));
                                    break;
                                case framework\exceptions\ModuleDownloadException::READONLY_TARGET:
                                    return $this->renderJSON(array('title' => $this->getI18n()->__('Error extracting module zip'), 'message' => $this->getI18n()->__('Could not extract the module into the destination folder. Please check permissions.')));
                                    break;
                            }
                        }
                        catch (\Exception $e)
                        {
                            $this->getResponse()->setHttpStatus(400);
                            return $this->renderJSON(array('message' => $this->getI18n()->__('An error occured when trying to install the module')));
                        }
                        break;
                    case 'install-theme':
                        try
                        {
                            entities\Module::downloadTheme($request['theme_key']);
                            $data['installed'] = true;
                            $data['theme_key'] = $request['theme_key'];
                            $themes = framework\Context::getThemes();
                            $data['theme'] = $this->getComponentHTML('configuration/theme', array('theme' => $themes[$request['theme_key']]));
                        }
                        catch (framework\exceptions\ModuleDownloadException $e)
                        {
                            $this->getResponse()->setHttpStatus(400);
                            switch ($e->getCode())
                            {
                                case framework\exceptions\ModuleDownloadException::JSON_NOT_FOUND:
                                    return $this->renderJSON(array('message' => $this->getI18n()->__('An error occured when trying to retrieve the module data')));
                                    break;
                                case framework\exceptions\ModuleDownloadException::FILE_NOT_FOUND:
                                    return $this->renderJSON(array('message' => $this->getI18n()->__('The module could not be downloaded')));
                                    break;
                            }
                        }
                        catch (\Exception $e)
                        {
                            $this->getResponse()->setHttpStatus(400);
                            return $this->renderJSON(array('message' => $this->getI18n()->__('An error occured when trying to install the module')));
                        }
                        break;
                    case 'notificationstatus':
                        $notification = tables\Notifications::getTable()->selectById($request['notification_id']);
                        $data['notification_id'] = $request['notification_id'];
                        $data['is_read'] = 1;
                        if ($notification instanceof entities\Notification)
                        {
                            $notification->setIsRead(!$notification->isRead());
                            $notification->save();
                            $data['is_read'] = (int) $notification->isRead();
                            $this->getUser()->markNotificationGroupedNotificationsRead($notification);
                        }
                        break;
                    case 'notificationsread':
                        $this->getUser()->markAllNotificationsRead();
                        $data['all'] = 'read';
                        break;
                    case 'togglecommentsorder':
                        $direction = $this->getUser()->getCommentSortOrder();
                        $new_direction = ($direction == 'asc') ? 'desc' : 'asc';

                        $this->getUser()->setCommentSortOrder($new_direction);
                        $data['new_direction'] = $new_direction;
                        break;
                }
            }
            else
            {
                switch ($request['say'])
                {
                    case 'get_module_updates':
                        $addons_param = array('license_key' => framework\Settings::getLicenseIdentifier());
                        foreach ($request['addons'] as $addon) {
                            $addons_param[] = 'addons[]='.$addon;
                        }
                        try
                        {
                            $client = new \Net_Http_Client();
                            $client->get('https://thebuggenie.com/addons.json?'.join('&', $addons_param));
                            $addons_json = json_decode($client->getBody(), true);
                        }
                        catch (\Exception $e) {}
                        return $this->renderJSON($addons_json);
                        break;
                    case 'getsearchcounts':
                        $counts_json = array();
                        foreach ($request['search_ids'] as $search_id) {
                            if (is_numeric($search_id)) {
                                $search = tables\SavedSearches::getTable()->selectById($search_id);
                            } else {
                                $predefined_id = str_replace('predefined_', '', $search_id);
                                $search = \thebuggenie\core\entities\SavedSearch::getPredefinedSearchObject($predefined_id);
                            }
                            if ($search instanceof entities\SavedSearch) {
                                $counts_json[$search_id] = $search->getTotalNumberOfIssues();
                            }
                        }
                        return $this->renderJSON($counts_json);
                        break;
                    case 'get_theme_updates':
                        $addons_param = array('license_key' => framework\Settings::getLicenseIdentifier());
                        foreach ($request['addons'] as $addon) {
                            $addons_param[] = 'themes[]='.$addon;
                        }
                        try
                        {
                            $client = new \Net_Http_Client();
                            $client->get('https://thebuggenie.com/themes.json?'.join('&', $addons_param));
                            $addons_json = json_decode($client->getBody(), true);
                        }
                        catch (\Exception $e) {}
                        return $this->renderJSON($addons_json);
                        break;
                    case 'verify_module_update_file':
                        $filename = THEBUGGENIE_CACHE_PATH . $request['module_key'] . '.zip';
                        $exists = file_exists($filename) && dirname($filename) . DS == THEBUGGENIE_CACHE_PATH;
                        return $this->renderJSON(array('verified' => (int) $exists));
                        break;
                    case 'get_modules':
                        return $this->renderComponent('configuration/onlinemodules');
                        break;
                    case 'get_themes':
                        return $this->renderComponent('configuration/onlinethemes');
                        break;
                    case 'get_mentionables':
                        switch ($request['target_type'])
                        {
                            case 'issue':
                                $target = tables\Issues::getTable()->selectById($request['target_id']);
                                break;
                            case 'article':
                                $target = \thebuggenie\modules\publish\entities\tables\Articles::getTable()->selectById($request['target_id']);
                                break;
                            case 'project':
                                $target = tables\Projects::getTable()->selectById($request['target_id']);
                                break;
                        }
                        $mentionables = array();
                        if (isset($target) && $target instanceof \thebuggenie\core\helpers\MentionableProvider)
                        {
                            foreach ($target->getMentionableUsers() as $user)
                            {
                                if ($user->isOpenIdLocked())
                                    continue;
                                $mentionables[$user->getID()] = array('username' => $user->getUsername(), 'name' => $user->getName(), 'image' => $user->getAvatarURL());
                            }
                        }
                        foreach ($this->getUser()->getFriends() as $user)
                        {
                            if ($user->isOpenIdLocked())
                                continue;
                            $mentionables[$user->getID()] = array('username' => $user->getUsername(), 'name' => $user->getName(), 'image' => $user->getAvatarURL());
                        }
                        foreach ($this->getUser()->getTeams() as $team)
                        {
                            foreach ($team->getMembers() as $user)
                            {
                                if ($user->isOpenIdLocked())
                                    continue;
                                $mentionables[$user->getID()] = array('username' => $user->getUsername(), 'name' => $user->getName(), 'image' => $user->getAvatarURL());
                            }
                        }
                        foreach ($this->getUser()->getClients() as $client)
                        {
                            foreach ($client->getMembers() as $user)
                            {
                                if ($user->isOpenIdLocked())
                                    continue;
                                $mentionables[$user->getID()] = array('username' => $user->getUsername(), 'name' => $user->getName(), 'image' => $user->getAvatarURL());
                            }
                        }
                        $data['mentionables'] = array_values($mentionables);
                        break;
                    case 'loadcomments':
                        switch ($request['target_type'])
                        {
                            case entities\Comment::TYPE_ISSUE:
                                $target = tables\Issues::getTable()->selectById($request['target_id']);
                                $data['comments'] = $this->getComponentHTML('main/commentlist', [
                                    'comment_count_div' => 'viewissue_comment_count',
                                    'mentionable_target_type' => 'issue',
                                    'target_type' => Comment::TYPE_ISSUE,
                                    'target_id' => $target->getID(),
                                    'issue' => $target
                                ]);
                                break;
                            case entities\Comment::TYPE_ARTICLE:
                                $target = \thebuggenie\modules\publish\entities\tables\Articles::getTable()->selectById($request['target_id']);
                                $data['comments'] = $this->getComponentHTML('main/commentlist', [
                                    'comment_count_div' => 'article_comment_count',
                                    'mentionable_target_type' => 'article',
                                    'target_type' => Comment::TYPE_ARTICLE,
                                    'target_id' => $target->getID(),
                                    'article' => $target
                                ]);
                                break;
                        }
                        break;
                    default:
                        $data['unread_notifications_count'] = $this->getUser()->getNumberOfUnreadNotifications();
                        $data['unread_notifications'] = array();
                        foreach ($this->getUser()->getUnreadNotifications() as $unread_notification)
                        {
                                $data['unread_notifications'][] = $unread_notification->getID();
                        }
                        $data['poll_interval'] = framework\Settings::getNotificationPollInterval();
                }
            }

            return $this->renderJSON($data);
        }
    }

    /**
     * Developer dashboard
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runDashboard(framework\Request $request)
    {
        $this->forward403unless(!$this->getUser()->isGuest() && $this->getUser()->hasPageAccess('dashboard'));
        if (framework\Settings::isSingleProjectTracker())
        {
            if (($projects = entities\Project::getAll()) && $project = array_shift($projects))
            {
                framework\Context::setCurrentProject($project);
            }
        }
        if ($request['dashboard_id'])
        {
            $dashboard = tables\Dashboards::getTable()->selectById((int) $request['dashboard_id']);
            if ($dashboard->getType() == entities\Dashboard::TYPE_PROJECT && !$dashboard->getProject()->hasAccess())
            {
                unset($dashboard);
            }
            elseif ($dashboard->getType() == entities\Dashboard::TYPE_USER && $dashboard->getUser()->getID() != framework\Context::getUser()->getID())
            {
                unset($dashboard);
            }
        }

        if (!isset($dashboard) || !$dashboard instanceof entities\Dashboard)
        {
            $dashboard = $this->getUser()->getDefaultDashboard();
        }

        if ($request->isPost())
        {
            switch ($request['mode'])
            {
                case 'add_view':
                    $sort_order = 1;
                    foreach ($dashboard->getViews() as $view)
                    {
                        if ($view->getColumn() == $request['column'])
                            $sort_order++;
                    }
                    $view = new entities\DashboardView();
                    $view->setDashboard($dashboard);
                    $view->setType($request['view_type']);
                    $view->setDetail($request['view_subtype']);
                    $view->setColumn($request['column']);
                    $view->setSortOrder($sort_order);
                    $view->save();

                    framework\Context::setCurrentProject($view->getProject());

                    return $this->renderJSON(array('view_content' => $this->getComponentHTML('main/dashboardview', array('view' => $view, 'show' => false)), 'view_id' => $view->getID()));
                case 'remove_view':
                    $deleted = 0;
                    foreach ($dashboard->getViews() as $view)
                    {
                        if ($view->getID() == $request['view_id'])
                        {
                            $deleted = $view->getID();
                            $view->delete();
                        }
                    }
                    return $this->renderJSON(array('deleted_view' => $deleted));
            }
        }

        $this->dashboard = $dashboard;
    }

    /**
     * Save dashboard configuration (AJAX call)
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runDashboardSort(framework\Request $request)
    {
        $column = $request['column'];
        foreach ($request['view_ids'] as $order => $view_id)
        {
            $view = tables\DashboardViews::getTable()->selectById($view_id);
            $view->setSortOrder($order);
            $view->setColumn($column);
            $view->save();
        }

        return $this->renderText('ok');
    }

    /**
     * Client List
     */
    public function runClientList()
    {
        $this->forward403Unless(framework\Context::getUser()->hasPageAccess('clientlist'));
        $this->clients = \thebuggenie\core\entities\Client::getAll();
    }

    /**
     * Client Dashboard
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runClientDashboard(framework\Request $request)
    {
        $this->client = null;
        try
        {
            $this->client = tables\Clients::getTable()->selectById($request['client_id']);

            if (!$this->client instanceof entities\Client) {
                return $this->return404(framework\Context::getI18n()->__('This client does not exist'));
            }
            $this->forward403unless($this->client->hasAccess());

            $this->users = $this->client->getMembers();
        }
        catch (\Exception $e)
        {
            framework\Logging::log($e->getMessage(), 'core', framework\Logging::LEVEL_WARNING);
            return $this->return404(framework\Context::getI18n()->__('This client does not exist'));
        }
    }

    /**
     * Team List
     */
    public function runTeamList()
    {
        $this->forward403Unless(framework\Context::getUser()->hasPageAccess('teamlist'));
        $this->teams = \thebuggenie\core\entities\Team::getAll();
    }

    /**
     * Team Dashboard
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runTeamDashboard(framework\Request $request)
    {
        try
        {
            $this->team = tables\Teams::getTable()->selectById($request['team_id']);

            if (!$this->team instanceof entities\Team) {
                return $this->return404(framework\Context::getI18n()->__('This team does not exist'));
            }

            $this->forward403unless($this->team->hasAccess());

            $this->users = $this->team->getMembers();
        }
        catch (\Exception $e)
        {
            framework\Logging::log($e->getMessage(), 'core', framework\Logging::LEVEL_WARNING);
            return $this->return404(framework\Context::getI18n()->__('This team does not exist'));
        }
    }

    /**
     * Static login page
     *
     * @Route(name="login_page", url="/login")
     * @AnonymousRoute
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runLogin(framework\Request $request)
    {
        //if (!$this->getUser()->isGuest()) return $this->forward($this->$this->getRouting()->generate('home'));
        $this->section = $request->getParameter('section', 'login');
    }

    /**
     * Static elevated login page
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runDoElevatedLogin(framework\Request $request)
    {
        if ($this->getUser()->hasPassword($request['elevated_password']))
        {
            // Calculate expiration period in seconds. setCookie() method should
            // add expiration period to current time.
            $expiration = 60 * $request->getParameter('elevation_duration', 30);
            $authentication_backend = framework\Settings::getAuthenticationBackend();

            if ($authentication_backend->getAuthenticationMethod() == framework\AuthenticationBackend::AUTHENTICATION_TYPE_TOKEN)
            {
                $token = $this->getUser()->createUserSession();
                $token->setExpiresAt(time() + $expiration);
                $token->setIsElevated(true);
                $token->save();

                framework\Context::getResponse()->setCookie('elevated_session_token', $token->getToken(), $expiration);
            }
            else
            {
                framework\Context::getResponse()->setCookie('elevated_password', $this->getUser()->getHashPassword(), $expiration);
            }
            return $this->renderJSON(array('elevated' => true));
        }
        else
        {
            return $this->renderJSON(array('elevated' => false, 'error' => $this->getI18n()->__('Incorrect password')));
        }
    }

    /**
     * Static elevated login page
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runElevatedLogin(framework\Request $request)
    {
        if ($this->getUser()->isGuest())
            return $this->forward($this->getRouting()->generate('login_page'));
    }

    public function runDisableTutorial(framework\Request $request)
    {
        if (strlen(trim($request['key'])))
            $this->getUser()->disableTutorial($request['key']);

        return $this->renderJSON(array('disabled' => $request['key']));
    }

    public function runSwitchUser(framework\Request $request)
    {
        if (!$this->getUser()->canAccessConfigurationPage(framework\Settings::CONFIGURATION_SECTION_USERS) && !$request->hasCookie('original_username'))
            return $this->forward403();

        $response = $this->getResponse();
        $authentication_backend = framework\Settings::getAuthenticationBackend();
        if ($request['user_id'])
        {
            $user = new entities\User($request['user_id']);
            if ($authentication_backend->getAuthenticationMethod() == framework\AuthenticationBackend::AUTHENTICATION_TYPE_TOKEN)
            {
                $response->setCookie('original_username', $request->getCookie('username'));
                $response->setCookie('original_session_token', $request->getCookie('session_token'));
                framework\Context::getResponse()->setCookie('username', $user->getUsername());
                framework\Context::getResponse()->setCookie('session_token', $user->createUserSession()->getToken());
            }
            else
            {
                $response->setCookie('original_username', $request->getCookie('username'));
                $response->setCookie('original_password', $request->getCookie('password'));
                framework\Context::getResponse()->setCookie('password', $user->getHashPassword());
                framework\Context::getResponse()->setCookie('username', $user->getUsername());
            }
        }
        else
        {
            if ($authentication_backend->getAuthenticationMethod() == framework\AuthenticationBackend::AUTHENTICATION_TYPE_TOKEN)
            {
                $response->setCookie('username', $request->getCookie('original_username'));
                $response->setCookie('session_token', $request->getCookie('original_session_token'));
                framework\Context::getResponse()->deleteCookie('original_session_token');
                framework\Context::getResponse()->deleteCookie('original_username');
            }
            else
            {
                $response->setCookie('username', $request->getCookie('original_username'));
                $response->setCookie('password', $request->getCookie('original_password'));
                framework\Context::getResponse()->deleteCookie('original_password');
                framework\Context::getResponse()->deleteCookie('original_username');
            }
        }
        $this->forward($this->getRouting()->generate('home'));
    }

    /**
     * Do login (AJAX call)
     *
     * @Route(name="login", url="/do/login")
     * @AnonymousRoute
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runDoLogin(framework\Request $request)
    {
        $authentication_backend = framework\Settings::getAuthenticationBackend();

        if ($request->isPost())
        {
            try
            {
                $username = trim($request->getParameter('username', ''));
                $password = trim($request->getParameter('password', ''));
                $persist  = (bool) $request->getParameter('rememberme', false);

                if ($username && $password)
                {
                    $user = entities\User::identify($request, $this);

                    if (!$user instanceof entities\User || $user->isGuest())
                    {
                        throw new \Exception('No such login');
                    }

                    $user->setOnline();
                    $user->save();

                    framework\Context::setUser($user);
                    $this->verifyScopeMembership($user);

                    if (!$user->isGuest())
                    {
                        $this->_persistLogin($authentication_backend, $user, $persist);
                    }
                }
                else
                {
                    throw new \Exception('Please enter a username and password');
                }
            }
            catch (\Exception $e)
            {
                if ($request->isAjaxCall())
                {
                    $this->getResponse()->setHttpStatus(401);
                    framework\Logging::log($e->getMessage(), 'auth', framework\Logging::LEVEL_WARNING_RISK);
                    return $this->renderJSON(array("error" => $this->getI18n()->__("Invalid login details")));
                }
                else
                {
                    $this->forward403($e->getMessage());
                }
            }
        }
        else
        {
            if ($request->isAjaxCall())
            {
                $this->getResponse()->setHttpStatus(401);
                return $this->renderJSON(array("error" => $this->getI18n()->__('Please enter a username and password')));
            }
            else
            {
                $this->forward403($this->getI18n()->__('Please enter a username and password'));
            }
        }

        if (!$user instanceof entities\User)
        {
            $this->forward403($this->getI18n()->__("Invalid login details"));
        }

        $forward_url = $this->_getLoginForwardUrl($request);

        if ($request->isAjaxCall())
        {
            return $this->renderJSON(array('forward' => $forward_url));
        }
        else
        {
            $this->forward($this->getRouting()->generate('account'));
        }
    }

    /**
     * Registration logic
     *
     * @Route(name="register_check_username", url="/check/username")
     * @AnonymousRoute
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runRegisterCheckUsernameAvailability(framework\Request $request)
    {
        $username = mb_strtolower(trim($request['username']));
        $available = ($username != '') ? tables\Users::getTable()->isUsernameAvailable($username) : false;

        return $this->renderJSON(array('available' => (bool) $available));
    }

    /**
     * Registration logic
     *
     * @Route(name="register", url="/do/register")
     * @AnonymousRoute
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runRegister(framework\Request $request)
    {
        framework\Context::loadLibrary('common');
        $i18n = framework\Context::getI18n();
        $fields = array();

        try
        {
            $username = mb_strtolower(trim($request['username']));
            $buddyname = $request['buddyname'];
            $email = mb_strtolower(trim($request['email_address']));
            $confirmemail = mb_strtolower(trim($request['email_confirm']));
            $security = $request['verification_no'];
            $realname = $request['realname'];

            $available = tables\Users::getTable()->isUsernameAvailable($username);


            if (!$available)
            {
                throw new \Exception($i18n->__('This username is in use'));
            }

            if (!empty($buddyname) && !empty($email) && !empty($confirmemail) && !empty($security))
            {
                if ($email != $confirmemail)
                {
                    array_push($fields, 'email_address', 'email_confirm');
                    throw new \Exception($i18n->__('The email address must be valid, and must be typed twice.'));
                }

                if ($security != $_SESSION['activation_number'])
                {
                    array_push($fields, 'verification_no');
                    throw new \Exception($i18n->__('To prevent automatic sign-ups, enter the verification number shown below.'));
                }

                $email_ok = false;

                if (tbg_check_syntax($email, "EMAIL"))
                {
                    $email_ok = true;
                }

                if ($email_ok && framework\Settings::hasRegistrationDomainWhitelist())
                {

                    $allowed_domains = preg_replace('/[[:space:]]*,[[:space:]]*/', '|', framework\Settings::getRegistrationDomainWhitelist());
                    if (preg_match('/@(' . $allowed_domains . ')$/i', $email) == false)
                    {
                        array_push($fields, 'email_address', 'email_confirm');
                        throw new \Exception($i18n->__('Email adresses from this domain can not be used.'));
                    }
                }

                if ($email_ok == false)
                {
                    array_push($fields, 'email_address', 'email_confirm');
                    throw new \Exception($i18n->__('The email address must be valid, and must be typed twice.'));
                }

                if ($security != $_SESSION['activation_number'])
                {
                    array_push($fields, 'verification_no');
                    throw new \Exception($i18n->__('To prevent automatic sign-ups, enter the verification number shown below.'));
                }

                $password = entities\User::createPassword();
                $user = new entities\User();
                $user->setUsername($username);
                $user->setRealname($realname);
                $user->setBuddyname($buddyname);
                $user->setGroup(framework\Settings::getDefaultGroup());
                $user->setEnabled();
                $user->setPassword($password);
                $user->setEmail($email);
                $user->setJoined();
                $user->save();

                $_SESSION['activation_number'] = tbg_printRandomNumber();

                if ($user->isActivated())
                {
                    framework\Context::setMessage('auto_password', $password);
                    return $this->renderJSON(array('loginmessage' => $i18n->__('After pressing %continue, you need to set your password.', array('%continue' => $i18n->__('Continue'))), 'one_time_password' => $password, 'activated' => true));
                }
                return $this->renderJSON(array('loginmessage' => $i18n->__('The account has now been registered - check your email inbox for the activation email. Please be patient - this email can take up to two hours to arrive.'), 'activated' => false));
            }
            else
            {
                array_push($fields, 'email_address', 'email_confirm', 'buddyname', 'verification_no');
                throw new \Exception($i18n->__('You need to fill out all fields correctly.'));
            }
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $i18n->__($e->getMessage()), 'fields' => $fields));
        }
    }

    /**
     * Activate newly registered account
     *
     * @Route(name="activate", url="/activate/:user/:key")
     * @AnonymousRoute
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runActivate(framework\Request $request)
    {
        $this->getResponse()->setPage('login');

        $user = tables\Users::getTable()->getByUsername(str_replace('%2E', '.', $request['user']));
        if ($user instanceof entities\User)
        {
            if ($user->getActivationKey() != $request['key'])
            {
                framework\Context::setMessage('login_message_err', framework\Context::getI18n()->__('This activation link is not valid'));
            }
            else
            {
                $user->setValidated(true);
                $user->save();
                framework\Context::setMessage('login_message', framework\Context::getI18n()->__('Your account has been activated! You can now log in with the username %user and the password in your activation email.', array('%user' => $user->getUsername())));
            }
        }
        else
        {
            framework\Context::setMessage('login_message_err', framework\Context::getI18n()->__('This activation link is not valid'));
        }
        $this->forward($this->getRouting()->generate('login_page'));
    }

    /**
     * "My account" page
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runMyAccount(framework\Request $request)
    {
        $this->forward403unless($this->getUser()->hasPageAccess('account'));
        $categories = \thebuggenie\core\entities\Category::getAll();
        $projects = [];
        $project_subscription_key = \thebuggenie\core\framework\Settings::SETTINGS_USER_SUBSCRIBE_NEW_ISSUES_MY_PROJECTS;
        $category_subscription_key = \thebuggenie\core\framework\Settings::SETTINGS_USER_SUBSCRIBE_NEW_ISSUES_MY_PROJECTS_CATEGORY;
        $category_notification_key = \thebuggenie\core\framework\Settings::SETTINGS_USER_NOTIFY_NEW_ISSUES_MY_PROJECTS_CATEGORY;
        $subscriptionssettings = framework\Settings::getSubscriptionsSettings();
        $notificationsettings = framework\Settings:: getNotificationSettings();
        $selected_project_subscriptions = [];
        $selected_category_subscriptions = [];
        $selected_category_notifications = [];
        $this->all_projects_subscription = $this->getUser()->getNotificationSetting($project_subscription_key, false)->isOn();
        foreach (\thebuggenie\core\entities\Project::getAll() as $project_id => $project) {
            if ($project->hasAccess()) {
                $projects[$project_id] = $project;
                if ($this->getUser()->getNotificationSetting($project_subscription_key . '_' . $project_id, false)->isOn()) {
                    $selected_project_subscriptions[] = $project_id;
                }
            }
        }
        foreach ($categories as $category_id => $category) {
            if ($this->getUser()->getNotificationSetting($category_subscription_key . '_' . $category_id, false)->isOn()) {
                $selected_category_subscriptions[] = $category_id;
            }
            if ($this->getUser()->getNotificationSetting($category_notification_key . '_' . $category_id, false)->isOn()) {
                $selected_category_notifications[] = $category_id;
            }
        }
        $this->selected_project_subscriptions = ($this->all_projects_subscription) ? [] : $selected_project_subscriptions;
        $this->projects = $projects;
        $this->selected_category_subscriptions = $selected_category_subscriptions;
        $this->selected_category_notifications = $selected_category_notifications;
        $this->categories = $categories;
        $this->subscriptionssettings = $subscriptionssettings;
        $this->notificationsettings = $notificationsettings;
        $this->has_autopassword = framework\Context::hasMessage('auto_password');
        if ($this->has_autopassword)
        {
            $this->autopassword = framework\Context::getMessage('auto_password');
        }

        if ($request->isPost() && $request->hasParameter('mode')) {
            switch ($request['mode']) {
                case 'information':
                    if (!$request['buddyname'] || !$request['email']) {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Please fill out all the required fields')));
                    }
                    $this->getUser()->setBuddyname($request['buddyname']);
                    $this->getUser()->setRealname($request['realname']);
                    $this->getUser()->setHomepage($request['homepage']);
                    $this->getUser()->setEmailPrivate((bool) $request['email_private']);
                    $this->getUser()->setUsesGravatar((bool) $request['use_gravatar']);
                    $this->getUser()->setTimezone($request->getRawParameter('timezone'));
                    $this->getUser()->setLanguage($request['profile_language']);

                    if ($this->getUser()->getEmail() != $request['email']) {
                        if (framework\Event::createNew('core', 'changeEmail', $this->getUser(), array('email' => $request['email']))->triggerUntilProcessed()->isProcessed() == false) {
                            $this->getUser()->setEmail($request['email']);
                        }
                    }

                    $this->getUser()->save();

                    return $this->renderJSON(array('title' => framework\Context::getI18n()->__('Profile information saved')));
                    break;
                case 'settings':
                    $this->getUser()->setPreferredWikiSyntax($request['syntax_articles']);
                    $this->getUser()->setPreferredIssuesSyntax($request['syntax_issues']);
                    $this->getUser()->setPreferredCommentsSyntax($request['syntax_comments']);
                    $this->getUser()->setKeyboardNavigationEnabled($request['enable_keyboard_navigation']);
                    $this->getUser()->save();

                    return $this->renderJSON(array('title' => framework\Context::getI18n()->__('Profile settings saved')));
                    break;
                case 'notificationsettings':
                    $this->getUser()->setDesktopNotificationsNewTabEnabled($request['enable_desktop_notifications_new_tab']);
                    foreach ($subscriptionssettings as $setting => $description) {
                        if ($setting == framework\Settings::SETTINGS_USER_SUBSCRIBE_NEW_ISSUES_MY_PROJECTS_CATEGORY) {
                            foreach ($categories as $category_id => $category) {
                                if ($request->hasParameter('core_' . $setting . '_' . $category_id)) {
                                    $this->getUser()->setNotificationSetting($setting . '_' . $category_id, true);
                                } else {
                                    $this->getUser()->setNotificationSetting($setting . '_' . $category_id, false);
                                }
                            }
                        } elseif ($setting == framework\Settings::SETTINGS_USER_SUBSCRIBE_NEW_ISSUES_MY_PROJECTS) {
                            if ($request->hasParameter('core_' . $setting . '_all')) {
                                $this->getUser()->setNotificationSetting($setting, true);
                                foreach (\thebuggenie\core\entities\Project::getAll() as $project_id => $project) {
                                    $this->getUser()->setNotificationSetting($setting . '_' . $project_id, false);
                                }
                            } else {
                                $this->getUser()->setNotificationSetting($setting, false);
                                foreach (\thebuggenie\core\entities\Project::getAll() as $project_id => $project) {
                                    if ($request->hasParameter('core_' . $setting . '_' . $project_id)) {
                                        $this->getUser()->setNotificationSetting($setting . '_' . $project_id, true);
                                    } else {
                                        $this->getUser()->setNotificationSetting($setting . '_' . $project_id, false);
                                    }
                                }
                            }
                        } else {
                            if ($request->hasParameter('core_' . $setting)) {
                                $this->getUser()->setNotificationSetting($setting, true);
                            } else {
                                $this->getUser()->setNotificationSetting($setting, false);
                            }
                        }
                    }

                    foreach ($notificationsettings as $setting => $description) {
                        if ($setting == framework\Settings::SETTINGS_USER_NOTIFY_NEW_ISSUES_MY_PROJECTS_CATEGORY) {
                            foreach ($categories as $category_id => $category) {
                                if ($request->hasParameter('core_' . $setting . '_' . $category_id)) {
                                    $this->getUser()->setNotificationSetting($setting . '_' . $category_id, true);
                                } else {
                                    $this->getUser()->setNotificationSetting($setting . '_' . $category_id, false);
                                }
                            }
                        } else {
                            if ($request->hasParameter('core_' . $setting)) {
                                if ($setting == framework\Settings::SETTINGS_USER_NOTIFY_GROUPED_NOTIFICATIONS) {
                                    $this->getUser()->setNotificationSetting($setting, $request->getParameter('core_' . $setting));
                                } else {
                                    $this->getUser()->setNotificationSetting($setting, true);
                                }
                            } else {
                                $this->getUser()->setNotificationSetting($setting, false);
                            }
                        }
                    }

                    framework\Event::createNew('core', 'mainActions::myAccount::saveNotificationSettings')->trigger(compact('request', 'categories'));
                    $this->getUser()->save();

                    return $this->renderJSON(array('title' => framework\Context::getI18n()->__('Notification settings saved')));
                    break;
                case 'module':
                    foreach (framework\Context::getAllModules() as $modules) {
                        foreach ($modules as $module_name => $module) {
                            if ($request['target_module'] == $module_name && $module->hasAccountSettings()) {
                                try {
                                    if ($module->postAccountSettings($request)) {
                                        return $this->renderJSON(array('title' => framework\Context::getI18n()->__('Settings saved')));
                                    } else {
                                        $this->getResponse()->setHttpStatus(400);
                                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An error occured')));
                                    }
                                } catch (\Exception $e) {
                                    $this->getResponse()->setHttpStatus(400);
                                    return $this->renderJSON(array('error' => framework\Context::getI18n()->__($e->getMessage())));
                                }
                            }
                        }
                    }
                    break;
            }
        }
        $this->rnd_no = rand();
        $this->languages = framework\I18n::getLanguages();
        $this->timezones = framework\I18n::getTimezones();
        $this->error = framework\Context::getMessageAndClear('error');
        $this->username_chosen = framework\Context::getMessageAndClear('username_chosen');
        $this->openid_used = framework\Context::getMessageAndClear('openid_used');
        $this->rsskey_generated = framework\Context::getMessageAndClear('rsskey_generated');

        $this->selected_tab = 'profile';
        if ($this->rsskey_generated)
            $this->selected_tab = 'security';
    }

    /**
     * Change password ajax action
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runAccountRegenerateRssKey(framework\Request $request)
    {
        $this->getUser()->regenerateRssKey();
        framework\Context::setMessage('rsskey_generated', true);
        return $this->forward($this->getRouting()->generate('account'));
    }

    /**
     * Change password ajax action
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runAccountRemovePassword(framework\Request $request)
    {
        $passwords = $this->getUser()->getApplicationPasswords();
        foreach ($passwords as $password)
        {
            if ($password->getID() == $request['id'])
            {
                $password->delete();
                return $this->renderJSON(array('message' => $this->getI18n()->__('The application password has been deleted')));
            }
        }

        $this->getResponse()->setHttpStatus(400);
        return $this->renderJSON(array('error' => $this->getI18n()->__('Cannot delete this application-specific password')));
    }

    /**
     * Add a new application password: ajax action
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runAccountAddPassword(framework\Request $request)
    {
        $this->forward403unless($this->getUser()->hasPageAccess('account'));
        $name = trim($request['name']);
        if ($name)
        {
            framework\Logging::log('Adding new application password for user.', 'account', framework\Logging::LEVEL_INFO);
            $password = new entities\ApplicationPassword();
            $password->setUser($this->getUser());
            $password->setName($name);
            $visible_password = strtolower(entities\User::createPassword());
            // Internally creates a hash from this visible password & crypts that hash for storage
            $password->setPassword($visible_password);
            $password->save();
            $spans = '';

            for ($cc = 0; $cc < 4; $cc++)
            {
                $spans .= '<span>' . substr($visible_password, $cc * 4, 4) . '</span>';
            }

            return $this->renderJSON(array('password' => $spans));
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $this->getI18n()->__('Please enter a valid name')));
        }
    }

    /**
     * Change password ajax action
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runAccountChangePassword(framework\Request $request)
    {
        $this->forward403unless($this->getUser()->hasPageAccess('account'));
        if ($request->isPost())
        {
            if ($this->getUser()->canChangePassword() == false)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__("You're not allowed to change your password.")));
            }
            if (!$request->hasParameter('current_password') || !$request['current_password'])
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Please enter your current password')));
            }
            if (!$request->hasParameter('new_password_1') || !$request['new_password_1'])
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Please enter a new password')));
            }
            if (!$request->hasParameter('new_password_2') || !$request['new_password_2'])
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Please enter the new password twice')));
            }
            if (!$this->getUser()->hasPassword($request['current_password']))
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Please enter your current password')));
            }
            if ($request['new_password_1'] != $request['new_password_2'])
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Please enter the new password twice')));
            }
            $this->getUser()->changePassword($request['new_password_1']);
            $this->getUser()->save();
            framework\Context::clearMessage('auto_password');

            return $this->renderJSON(array('title' => framework\Context::getI18n()->__('Your new password has been saved')));
        }
    }

    protected function _clearReportIssueProperties()
    {
        $this->title = null;
        $this->description = null;
        $this->description_syntax = null;
        $this->reproduction_steps = null;
        $this->reproduction_steps_syntax = null;
        $this->selected_category = null;
        $this->selected_status = null;
        $this->selected_reproducability = null;
        $this->selected_resolution = null;
        $this->selected_severity = null;
        $this->selected_priority = null;
        $this->selected_edition = null;
        $this->selected_build = null;
        $this->selected_component = null;
        $this->selected_estimated_time = null;
        $this->selected_spent_time = null;
        $this->selected_percent_complete = null;
        $this->selected_pain_bug_type = null;
        $this->selected_pain_likelihood = null;
        $this->selected_pain_effect = null;
        $selected_customdatatype = array();
        foreach (entities\CustomDatatype::getAll() as $customdatatype)
        {
            $selected_customdatatype[$customdatatype->getKey()] = null;
        }
        $this->selected_customdatatype = $selected_customdatatype;
    }

    protected function _loadSelectedProjectAndIssueTypeFromRequestForReportIssueAction(framework\Request $request)
    {
        try
        {
            if ($project_key = $request['project_key'])
                $this->selected_project = entities\Project::getByKey($project_key);
            elseif ($project_id = $request['project_id'])
                $this->selected_project = tables\Projects::getTable()->selectById($project_id);
        }
        catch (\Exception $e)
        {

        }

        if ($this->selected_project instanceof entities\Project)
            framework\Context::setCurrentProject($this->selected_project);
        if ($this->selected_project instanceof entities\Project)
            $this->issuetypes = $this->selected_project->getIssuetypeScheme()->getIssuetypes();
        else
            $this->issuetypes = entities\Issuetype::getAll();

        $this->selected_issuetype = null;
        if ($request->hasParameter('issuetype'))
            $this->selected_issuetype = entities\Issuetype::getByKeyish($request['issuetype']);

        $this->locked_issuetype = (bool) $request['lock_issuetype'];

        if (!$this->selected_issuetype instanceof entities\Issuetype)
        {
            $this->issuetype_id = $request['issuetype_id'];
            if ($this->issuetype_id)
            {
                try
                {
                    $this->selected_issuetype = tables\Issuetypes::getTable()->selectById($this->issuetype_id);
                }
                catch (\Exception $e)
                {

                }
            }
        }
        else
        {
            $this->issuetype_id = $this->selected_issuetype->getID();
        }
    }

    protected function _postIssueValidation(framework\Request $request, &$errors, &$permission_errors)
    {
        $i18n = framework\Context::getI18n();
        if (!$this->selected_project instanceof entities\Project)
            $errors['project'] = $i18n->__('You have to select a valid project');
        if (!$this->selected_issuetype instanceof entities\Issuetype)
            $errors['issuetype'] = $i18n->__('You have to select a valid issue type');
        if (empty($errors))
        {
            $fields_array = $this->selected_project->getReportableFieldsArray($this->issuetype_id);

            $this->title = $request->getRawParameter('title');
            $this->selected_shortname = $request->getRawParameter('shortname', null);
            $this->selected_description = $request->getRawParameter('description', null);
            $this->selected_description_syntax = $request->getRawParameter('description_syntax', null);
            $this->selected_reproduction_steps = $request->getRawParameter('reproduction_steps', null);
            $this->selected_reproduction_steps_syntax = $request->getRawParameter('reproduction_steps_syntax', null);

            if ($edition_id = (int) $request['edition_id'])
                $this->selected_edition = tables\Editions::getTable()->selectById($edition_id);
            if ($build_id = (int) $request['build_id'])
                $this->selected_build = tables\Builds::getTable()->selectById($build_id);
            if ($component_id = (int) $request['component_id'])
                $this->selected_component = tables\Components::getTable()->selectById($component_id);

            if (trim($this->title) == '' || $this->title == $this->default_title)
                $errors['title'] = true;
            if (isset($fields_array['shortname']) && $fields_array['shortname']['required'] && trim($this->selected_shortname) == '')
                $errors['shortname'] = true;
            if (isset($fields_array['description']) && $fields_array['description']['required'] && trim($this->selected_description) == '')
                $errors['description'] = true;
            if (isset($fields_array['reproduction_steps']) && !$request->isAjaxCall() && $fields_array['reproduction_steps']['required'] && trim($this->selected_reproduction_steps) == '')
                $errors['reproduction_steps'] = true;

            if (isset($fields_array['edition']) && $edition_id && !in_array($edition_id, array_keys($fields_array['edition']['values'])))
                $errors['edition'] = true;

            if (isset($fields_array['build']) && $build_id && !in_array($build_id, array_keys($fields_array['build']['values'])))
                $errors['build'] = true;

            if (isset($fields_array['component']) && $component_id && !in_array($component_id, array_keys($fields_array['component']['values'])))
                $errors['component'] = true;

            if ($category_id = (int) $request['category_id'])
            {
                $category = tables\ListTypes::getTable()->selectById($category_id);

                if (! $category->hasAccess())
                {
                    $errors['category'] = true;
                }
                else
                {
                    $this->selected_category = $category;
                }
            }

            if ($status_id = (int) $request['status_id'])
                $this->selected_status = tables\ListTypes::getTable()->selectById($status_id);

            if ($reproducability_id = (int) $request['reproducability_id'])
                $this->selected_reproducability = tables\ListTypes::getTable()->selectById($reproducability_id);

            if ($milestone_id = (int) $request['milestone_id'])
            {
                $milestone = $this->_getMilestoneFromRequest($request);

                if (!$milestone instanceof entities\Milestone)
                {
                    $errors['milestone'] = true;
                }
                else
                {
                    $this->selected_milestone = $milestone;
                }
            }

            if ($parent_issue_id = (int) $request['parent_issue_id'])
                $this->parent_issue = tables\Issues::getTable()->selectById($parent_issue_id);

            if ($resolution_id = (int) $request['resolution_id'])
                $this->selected_resolution = tables\ListTypes::getTable()->selectById($resolution_id);

            if ($severity_id = (int) $request['severity_id'])
                $this->selected_severity = tables\ListTypes::getTable()->selectById($severity_id);

            if ($priority_id = (int) $request['priority_id'])
                $this->selected_priority = tables\ListTypes::getTable()->selectById($priority_id);

            if ($request['estimated_time'])
                $this->selected_estimated_time = $request['estimated_time'];

            if ($request['spent_time'])
                $this->selected_spent_time = $request['spent_time'];

            if (is_numeric($request['percent_complete']))
                $this->selected_percent_complete = (int) $request['percent_complete'];

            if ($pain_bug_type_id = (int) $request['pain_bug_type_id'])
                $this->selected_pain_bug_type = $pain_bug_type_id;

            if ($pain_likelihood_id = (int) $request['pain_likelihood_id'])
                $this->selected_pain_likelihood = $pain_likelihood_id;

            if ($pain_effect_id = (int) $request['pain_effect_id'])
                $this->selected_pain_effect = $pain_effect_id;

            $selected_customdatatype = array();
            foreach (entities\CustomDatatype::getAll() as $customdatatype)
            {
                $customdatatype_id = $customdatatype->getKey() . '_id';
                $customdatatype_value = $customdatatype->getKey() . '_value';
                if ($customdatatype->hasCustomOptions())
                {
                    $selected_customdatatype[$customdatatype->getKey()] = null;
                    if ($request->hasParameter($customdatatype_id))
                    {
                        $customdatatype_id = (int) $request->getParameter($customdatatype_id);
                        $selected_customdatatype[$customdatatype->getKey()] = new entities\CustomDatatypeOption($customdatatype_id);
                    }
                }
                else
                {
                    $selected_customdatatype[$customdatatype->getKey()] = null;
                    switch ($customdatatype->getType())
                    {
                        case entities\CustomDatatype::INPUT_TEXTAREA_MAIN:
                        case entities\CustomDatatype::INPUT_TEXTAREA_SMALL:
                            if ($request->hasParameter($customdatatype_value))
                                $selected_customdatatype[$customdatatype->getKey()] = $request->getParameter($customdatatype_value, null, false);

                            break;
                        default:
                            if ($request->hasParameter($customdatatype_value))
                                $selected_customdatatype[$customdatatype->getKey()] = $request->getParameter($customdatatype_value);
                            elseif ($request->hasParameter($customdatatype_id))
                                $selected_customdatatype[$customdatatype->getKey()] = $request->getParameter($customdatatype_id);

                            break;
                    }
                }
            }
            $this->selected_customdatatype = $selected_customdatatype;

            foreach ($fields_array as $field => $info)
            {
                if ($field == 'user_pain')
                {
                    if ($info['required'])
                    {
                        if (!($this->selected_pain_bug_type != 0 && $this->selected_pain_likelihood != 0 && $this->selected_pain_effect != 0))
                        {
                            $errors['user_pain'] = true;
                        }
                    }
                }
                elseif ($info['required'])
                {
                    $var_name = "selected_{$field}";
                    if ((in_array($field, entities\Datatype::getAvailableFields(true)) && ($this->$var_name === null || $this->$var_name === 0)) || (!in_array($field, entities\DatatypeBase::getAvailableFields(true)) && !in_array($field, array('pain_bug_type', 'pain_likelihood', 'pain_effect')) && (array_key_exists($field, $selected_customdatatype) && $selected_customdatatype[$field] === null)))
                    {
                        $errors[$field] = true;
                    }
                }
                else
                {
                    if (in_array($field, entities\Datatype::getAvailableFields(true)) || in_array($field, array('pain_bug_type', 'pain_likelihood', 'pain_effect')))
                    {
                        if (!$this->selected_project->fieldPermissionCheck($field))
                        {
                            $permission_errors[$field] = true;
                        }
                    }
                    elseif (!$this->selected_project->fieldPermissionCheck($field, true, true))
                    {
                        $permission_errors[$field] = true;
                    }
                }
            }
            $event = new framework\Event('core', 'mainActions::_postIssueValidation', null, array(), $errors);
            $event->trigger();
            $errors = $event->getReturnList();
        }
        return !(bool) (count($errors) + count($permission_errors));
    }

    protected function _postIssue(framework\Request $request)
    {
        $fields_array = $this->selected_project->getReportableFieldsArray($this->issuetype_id);
        $issue = new entities\Issue();
        $issue->setTitle($this->title);
        $issue->setIssuetype($this->issuetype_id);
        $issue->setProject($this->selected_project);
        if (isset($fields_array['shortname']))
            $issue->setShortname($this->selected_shortname);
        if (isset($fields_array['description'])) {
            $issue->setDescription($this->selected_description);
            $issue->setDescriptionSyntax($this->selected_description_syntax);
        }
        if (isset($fields_array['reproduction_steps'])) {
            $issue->setReproductionSteps($this->selected_reproduction_steps);
            $issue->setReproductionStepsSyntax($this->selected_reproduction_steps_syntax);
        }
        if (isset($fields_array['category']) && $this->selected_category instanceof entities\Datatype)
            $issue->setCategory($this->selected_category->getID());
        if (isset($fields_array['status']) && $this->selected_status instanceof entities\Datatype)
            $issue->setStatus($this->selected_status->getID());
        if (isset($fields_array['reproducability']) && $this->selected_reproducability instanceof entities\Datatype)
            $issue->setReproducability($this->selected_reproducability->getID());
        if (isset($fields_array['resolution']) && $this->selected_resolution instanceof entities\Datatype)
            $issue->setResolution($this->selected_resolution->getID());
        if (isset($fields_array['severity']) && $this->selected_severity instanceof entities\Datatype)
            $issue->setSeverity($this->selected_severity->getID());
        if (isset($fields_array['priority']) && $this->selected_priority instanceof entities\Datatype)
            $issue->setPriority($this->selected_priority->getID());
        if (isset($fields_array['estimated_time']))
            $issue->setEstimatedTime($this->selected_estimated_time);
        if (isset($fields_array['spent_time']))
            $issue->setSpentTime($this->selected_spent_time);
        if (isset($fields_array['milestone']) || isset($this->selected_milestone))
            $issue->setMilestone($this->selected_milestone);
        if (isset($fields_array['percent_complete']))
            $issue->setPercentCompleted($this->selected_percent_complete);
        if (isset($fields_array['pain_bug_type']))
            $issue->setPainBugType($this->selected_pain_bug_type);
        if (isset($fields_array['pain_likelihood']))
            $issue->setPainLikelihood($this->selected_pain_likelihood);
        if (isset($fields_array['pain_effect']))
            $issue->setPainEffect($this->selected_pain_effect);
        foreach (entities\CustomDatatype::getAll() as $customdatatype)
        {
            if (!isset($fields_array[$customdatatype->getKey()]))
                continue;
            if ($customdatatype->hasCustomOptions())
            {
                if (isset($fields_array[$customdatatype->getKey()]) && $this->selected_customdatatype[$customdatatype->getKey()] instanceof entities\CustomDatatypeOption)
                {
                    $selected_option = $this->selected_customdatatype[$customdatatype->getKey()];
                    $issue->setCustomField($customdatatype->getKey(), $selected_option->getID());
                }
            }
            else
            {
                $issue->setCustomField($customdatatype->getKey(), $this->selected_customdatatype[$customdatatype->getKey()]);
            }
        }

        // FIXME: If we set the issue assignee during report issue, this needs to be set INSTEAD of this
        if ($this->selected_project->canAutoassign())
        {
            if (isset($fields_array['component']) && $this->selected_component instanceof entities\Component && $this->selected_component->hasLeader())
            {
                $issue->setAssignee($this->selected_component->getLeader());
            }
            elseif (isset($fields_array['edition']) && $this->selected_edition instanceof entities\Edition && $this->selected_edition->hasLeader())
            {
                $issue->setAssignee($this->selected_edition->getLeader());
            }
            elseif ($this->selected_project->hasLeader())
            {
                $issue->setAssignee($this->selected_project->getLeader());
            }
        }

        if ($request->hasParameter('custom_issue_access') && $this->selected_project->permissionCheck('canlockandeditlockedissues'))
        {
            switch ($request->getParameter('issue_access'))
            {
                case 'public':
                case 'public_category':
                    $issue->setLocked(false);
                    $issue->setLockedCategory($request->hasParameter('public_category'));
                    break;
                case 'restricted':
                    $issue->setLocked();
                    break;
            }
        }
        else
        {
            $issue->setLockedFromProject($this->selected_project);
        }

        framework\Event::listen('core', 'thebuggenie\core\entities\Issue::createNew_pre_notifications', array($this, 'listen_issueCreate'));
        $issue->save();

        if (isset($this->parent_issue))
            $issue->addParentIssue($this->parent_issue);
        if (isset($fields_array['edition']) && $this->selected_edition instanceof entities\Edition)
            $issue->addAffectedEdition($this->selected_edition);
        if (isset($fields_array['build']) && $this->selected_build instanceof entities\Build)
            $issue->addAffectedBuild($this->selected_build);
        if (isset($fields_array['component']) && $this->selected_component instanceof entities\Component)
            $issue->addAffectedComponent($this->selected_component);

        return $issue;
    }

    public function listen_issueCreate(framework\Event $event)
    {
        $request = framework\Context::getRequest();
        $issue = $event->getSubject();

        if ($issue->isUnlocked())
        {
            $this->_unlockIssueAfter($request, $issue);
        }
        else if ($issue->isLocked())
        {
            $this->_lockIssueAfter($request, $issue);
        }
    }

    protected function _getMilestoneFromRequest($request)
    {
        if ($request->hasParameter('milestone_id'))
        {
            try
            {
                $milestone = tables\Milestones::getTable()->selectById((int) $request['milestone_id']);
                if ($milestone instanceof entities\Milestone && !$milestone->hasAccess()) $milestone = null;
                return $milestone;
            }
            catch (\Exception $e) { }
        }
    }

    protected function _getBuildFromRequest($request)
    {
        if ($request->hasParameter('build_id'))
        {
            try
            {
                $build = tables\Builds::getTable()->selectById((int) $request['build_id']);
                return $build;
            }
            catch (\Exception $e) { }
        }
    }

    protected function _getParentIssueFromRequest($request)
    {
        if ($request->hasParameter('parent_issue_id'))
        {
            try
            {
                $parent_issue = tables\Issues::getTable()->selectById((int) $request['parent_issue_id']);
                return $parent_issue;
            }
            catch (\Exception $e) { }
        }
    }

    protected function _getBoardFromRequest($request)
    {
        if ($request->hasParameter('board_id'))
        {
            try
            {
                $board = agile\entities\tables\AgileBoards::getTable()->selectById((int) $request['board_id']);
                return $board;
            }
            catch (\Exception $e) { }
        }
    }

    /**
     * "Report issue" page
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runReportIssue(framework\Request $request)
    {
        $i18n = framework\Context::getI18n();
        $errors = array();
        $permission_errors = array();
        $this->issue = null;
        $this->getResponse()->setPage('reportissue');

        $this->_loadSelectedProjectAndIssueTypeFromRequestForReportIssueAction($request);

        $this->forward403unless(framework\Context::getCurrentProject() instanceof entities\Project && framework\Context::getCurrentProject()->hasAccess() && $this->getUser()->canReportIssues(framework\Context::getCurrentProject()));

        if ($request->isPost())
        {
            if ($this->_postIssueValidation($request, $errors, $permission_errors))
            {
                try
                {
                    $issue = $this->_postIssue($request);
                    if ($request->hasParameter('files') && $request->hasParameter('file_description'))
                    {
                        $files = $request['files'];
                        $file_descriptions = $request['file_description'];
                        foreach ($files as $file_id => $nothing)
                        {
                            $file = tables\Files::getTable()->selectById((int) $file_id);
                            $file->setDescription($file_descriptions[$file_id]);
                            $file->save();
                            tables\IssueFiles::getTable()->addByIssueIDandFileID($issue->getID(), $file->getID());
                        }
                    }
                    if ($request['return_format'] == 'planning')
                    {
                        $this->_loadSelectedProjectAndIssueTypeFromRequestForReportIssueAction($request);
                        $options = array();
                        $options['selected_issuetype'] = $issue->getIssueType();
                        $options['selected_project'] = $this->selected_project;
                        $options['issuetypes'] = $this->issuetypes;
                        $options['issue'] = $issue;
                        $options['errors'] = $errors;
                        $options['permission_errors'] = $permission_errors;
                        $options['selected_milestone'] = $this->_getMilestoneFromRequest($request);
                        $options['selected_build'] = $this->_getBuildFromRequest($request);
                        $options['parent_issue'] = $this->_getParentIssueFromRequest($request);
                        $options['medium_backdrop'] = 1;
                        return $this->renderJSON(array('content' => $this->getComponentHTML('main/reportissuecontainer', $options)));
                    }
                    if ($request->getRequestedFormat() != 'json' && $issue->getProject()->getIssuetypeScheme()->isIssuetypeRedirectedAfterReporting($this->selected_issuetype))
                    {
                        $this->forward($this->getRouting()->generate('viewissue', array('project_key' => $issue->getProject()->getKey(), 'issue_no' => $issue->getFormattedIssueNo())), 303);
                    }
                    else
                    {
                        $this->_clearReportIssueProperties();
                        $this->issue = $issue;
                    }
                }
                catch (\Exception $e)
                {
                    if ($request['return_format'] == 'planning')
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => $e->getMessage()));
                    }
                    $errors[] = $e->getMessage();
                }
            }
        }
        if ($request['return_format'] == 'planning')
        {
            $err_msg = array();
            foreach ($errors as $field => $value)
            {
                $err_msg[] = $i18n->__('Please provide a value for the %field_name field', array('%field_name' => $field));
            }
            foreach ($permission_errors as $field => $value)
            {
                $err_msg[] = $i18n->__("The %field_name field is marked as required, but you don't have permission to set it", array('%field_name' => $field));
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $i18n->__('An error occured while creating this story: %errors', array('%errors' => '')), 'message' => join('<br>', $err_msg)));
        }
        $this->errors = $errors;
        $this->permission_errors = $permission_errors;
        $this->options = $this->getParameterHolder();
    }

    /**
     * Retrieves the fields which are valid for that product and issue type combination
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runReportIssueGetFields(framework\Request $request)
    {
        if (!$this->selected_project instanceof entities\Project)
        {
            return $this->renderText('invalid project');
        }

        $fields_array = $this->selected_project->getReportableFieldsArray($request['issuetype_id'], true);
        $available_fields = entities\DatatypeBase::getAvailableFields();
        $available_fields[] = 'pain_bug_type';
        $available_fields[] = 'pain_likelihood';
        $available_fields[] = 'pain_effect';
        return $this->renderJSON(array('available_fields' => $available_fields, 'fields' => $fields_array));
    }

    /**
     * Toggle favourite issue (starring)
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runToggleFavouriteIssue(framework\Request $request)
    {
        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
                $user = tables\Users::getTable()->selectById($request['user_id']);
            }
            catch (\Exception $e)
            {
                return $this->renderText('fail');
            }
        }
        else
        {
            return $this->renderText('no issue');
        }

        if ($user->isIssueStarred($issue_id))
        {
            $retval = !$user->removeStarredIssue($issue_id);
        }
        else
        {
            $retval = $user->addStarredIssue($issue_id);
            if ($user->getID() != $this->getUser()->getID())
            {
                framework\Event::createNew('core', 'issue_subscribe_user', $issue, compact('user'))->trigger();
            }
        }


        return $this->renderText(json_encode(array('starred' => $retval, 'subscriber' => $this->getComponentHTML('main/issuesubscriber', array('user' => $user, 'issue' => $issue)), 'count' => count($issue->getSubscribers()))));
    }

    public function runIssueDeleteTimeSpent(framework\Request $request)
    {
        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
                $spenttime = tables\IssueSpentTimes::getTable()->selectById($request['entry_id']);

                if ($spenttime instanceof entities\IssueSpentTime)
                {
                    $spenttime->delete();
                    $spenttime->getIssue()->saveSpentTime();
                }

                return $this->renderJSON(array('deleted' => 'ok', 'issue_id' => $issue_id, 'timesum' => array_sum($issue->getSpentTime()), 'spenttime' => entities\Issue::getFormattedTime($issue->getSpentTime(true, true)), 'percentbar' => $this->getComponentHTML('main/percentbar', array('percent' => $issue->getEstimatedPercentCompleted(), 'height' => 3))));
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderText('fail');
            }
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderText('no issue');
        }
    }

    public function runIssueEditTimeSpent(framework\Request $request)
    {
        $entry_id = $request['entry_id'];
        $spenttime = ($entry_id) ? tables\IssueSpentTimes::getTable()->selectById($entry_id) : new entities\IssueSpentTime();

        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderText('fail');
            }
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderText('no issue');
        }

        framework\Context::loadLibrary('common');
        $spenttime->editOrAdd($issue, $this->getUser(), array_only_with_default($request->getParameters(), array_merge(array('timespent_manual', 'timespent_specified_type', 'timespent_specified_value', 'timespent_activitytype', 'timespent_comment', 'edited_at'), \thebuggenie\core\entities\common\Timeable::getUnitsWithPoints())));

        return $this->renderJSON(array('edited' => 'ok', 'issue_id' => $issue_id, 'timesum' => array_sum($spenttime->getIssue()->getSpentTime()), 'spenttime' => entities\Issue::getFormattedTime($spenttime->getIssue()->getSpentTime(true, true)), 'percentbar' => $this->getComponentHTML('main/percentbar', array('percent' => $issue->getEstimatedPercentCompleted(), 'height' => 3)), 'timeentries' => $this->getComponentHTML('main/issuespenttimes', array('issue' => $spenttime->getIssue()))));
    }

    /**
     * Sets an issue field to a specified value
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runIssueSetField(framework\Request $request)
    {
        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderText('fail');
            }
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderText('no issue');
        }

        framework\Context::loadLibrary('common');

        if (!$issue instanceof entities\Issue)
            return false;

        switch ($request['field'])
        {
            case 'description':
                if (!$issue->canEditDescription())
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));

                $issue->setDescription($request->getRawParameter('value'));
                $issue->setDescriptionSyntax($request->getParameter('value_syntax'));
                return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => $issue->isDescriptionChanged(), 'field' => array('id' => (int) ($issue->getDescription() != ''), 'name' => $issue->getParsedDescription(array('issue' => $issue))), 'description' => $issue->getParsedDescription(array('issue' => $issue))));
            case 'shortname':
                if (!$issue->canEditShortname())
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));

                $issue->setShortname($request->getRawParameter('shortname_value'));
                return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => $issue->isShortnameChanged(), 'field' => array('id' => (int) ($issue->getShortname() != ''), 'name' => $issue->getShortname()), 'shortname' => $issue->getShortname()));
            case 'reproduction_steps':
                if (!$issue->canEditReproductionSteps())
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));

                $issue->setReproductionSteps($request->getRawParameter('value'));
                $issue->setReproductionStepsSyntax($request->getParameter('value_syntax'));
                return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => $issue->isReproductionStepsChanged(), 'field' => array('id' => (int) ($issue->getReproductionSteps() != ''), 'name' => $issue->getParsedReproductionSteps(array('issue' => $issue))), 'reproduction_steps' => $issue->getParsedReproductionSteps(array('issue' => $issue))));
            case 'title':
                if (!$issue->canEditTitle())
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));

                if ($request['value'] == '')
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You have to provide a title')));
                }

                $issue->setTitle($request->getRawParameter('value'));
                return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => $issue->isTitleChanged(), 'field' => array('id' => 1, 'name' => strip_tags($issue->getTitle()))));
            case 'percent_complete':
                if (!$issue->canEditPercentage())
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));

                $issue->setPercentCompleted($request['percent']);
                return $this->renderJSON(array('issue_id' => $issue->getID(), 'field' => 'percent_complete', 'changed' => $issue->isPercentCompletedChanged(), 'percent' => $issue->getPercentCompleted()));
            case 'estimated_time':
                if (!$issue->canEditEstimatedTime())
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));
                if (!$issue->isUpdateable())
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('This issue cannot be updated')));

                if ($request['estimated_time'])
                {
                    $issue->setEstimatedTime($request['estimated_time']);
                }
                elseif ($request->hasParameter('value'))
                {
                    $issue->setEstimatedTime($request['value']);
                }
                else
                {
                    if ($request->hasParameter('months')) $issue->setEstimatedMonths($request['months']);
                    if ($request->hasParameter('weeks')) $issue->setEstimatedWeeks($request['weeks']);
                    if ($request->hasParameter('days')) $issue->setEstimatedDays($request['days']);
                    if ($request->hasParameter('hours')) $issue->setEstimatedHours($request['hours']);
                    if ($request->hasParameter('minutes')) $issue->setEstimatedMinutes($request['minutes']);
                    if ($request->hasParameter('points')) $issue->setEstimatedPoints($request['points']);
                }
                if ($request['do_save'])
                {
                    $issue->save();
                }
                return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => $issue->isEstimatedTimeChanged(), 'field' => (($issue->hasEstimatedTime()) ? array('id' => 1, 'name' => entities\Issue::getFormattedTime($issue->getEstimatedTime(true, true))) : array('id' => 0)), 'values' => $issue->getEstimatedTime(true, true), 'percentbar' => $this->getComponentHTML('main/percentbar', array('percent' => $issue->getEstimatedPercentCompleted(), 'height' => 3))));
            case 'posted_by':
            case 'owned_by':
            case 'assigned_to':
                if ($request['field'] == 'posted_by' && !$issue->canEditPostedBy())
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));
                elseif ($request['field'] == 'owned_by' && !$issue->canEditOwner())
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));
                elseif ($request['field'] == 'assigned_to' && !$issue->canEditAssignee())
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));

                if ($request->hasParameter('value'))
                {
                    if ($request->hasParameter('identifiable_type'))
                    {
                        if (in_array($request['identifiable_type'], array('team', 'user')) && $request['value'] != 0)
                        {
                            switch ($request['identifiable_type'])
                            {
                                case 'user':
                                    $identified = tables\Users::getTable()->selectById($request['value']);
                                    break;
                                case 'team':
                                    $identified = tables\Teams::getTable()->selectById($request['value']);
                                    break;
                            }
                            if ($identified instanceof entities\User || $identified instanceof entities\Team)
                            {
                                if ($identified instanceof entities\User && (bool) $request->getParameter('teamup', false))
                                {
                                    $team = new entities\Team();
                                    $team->setName($identified->getBuddyname() . ' & ' . $this->getUser()->getBuddyname());
                                    $team->setOndemand(true);
                                    $team->save();
                                    $team->addMember($identified);
                                    $team->addMember($this->getUser());
                                    $identified = $team;
                                }
                                if ($request['field'] == 'owned_by')
                                    $issue->setOwner($identified);
                                elseif ($request['field'] == 'assigned_to')
                                    $issue->setAssignee($identified);
                            }
                        }
                        else
                        {
                            if ($request['field'] == 'owned_by')
                                $issue->clearOwner();
                            elseif ($request['field'] == 'assigned_to')
                                $issue->clearAssignee();
                        }
                    }
                    elseif ($request['field'] == 'posted_by')
                    {
                        $identified = tables\Users::getTable()->selectById($request['value']);
                        if ($identified instanceof entities\User)
                        {
                            $issue->setPostedBy($identified);
                        }
                    }
                    if ($request['field'] == 'posted_by')
                        return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => $issue->isPostedByChanged(), 'field' => array('id' => $issue->getPostedByID(), 'name' => $this->getComponentHTML('main/userdropdown', array('user' => $issue->getPostedBy())))));
                    if ($request['field'] == 'owned_by')
                        return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => $issue->isOwnerChanged(), 'field' => (($issue->isOwned()) ? array('id' => $issue->getOwner()->getID(), 'name' => (($issue->getOwner() instanceof entities\User) ? $this->getComponentHTML('main/userdropdown', array('user' => $issue->getOwner())) : $this->getComponentHTML('main/teamdropdown', array('team' => $issue->getOwner())))) : array('id' => 0))));
                    if ($request['field'] == 'assigned_to')
                        return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => $issue->isAssigneeChanged(), 'field' => (($issue->isAssigned()) ? array('id' => $issue->getAssignee()->getID(), 'name' => (($issue->getAssignee() instanceof entities\User) ? $this->getComponentHTML('main/userdropdown', array('user' => $issue->getAssignee())) : $this->getComponentHTML('main/teamdropdown', array('team' => $issue->getAssignee())))) : array('id' => 0))));
                }
                break;
            case 'category':
            case 'resolution':
            case 'severity':
            case 'reproducability':
            case 'priority':
            case 'milestone':
            case 'issuetype':
            case 'status':
            case 'pain_bug_type':
            case 'pain_likelihood':
            case 'pain_effect':
                if (($request['field'] == 'category' && !$issue->canEditCategory())
                    || ($request['field'] == 'resolution' && !$issue->canEditResolution())
                    || ($request['field'] == 'severity' && !$issue->canEditSeverity())
                    || ($request['field'] == 'reproducability' && !$issue->canEditReproducability())
                    || ($request['field'] == 'priority' && !$issue->canEditPriority())
                    || ($request['field'] == 'milestone' && !$issue->canEditMilestone())
                    || ($request['field'] == 'issuetype' && !$issue->canEditIssuetype())
                    || ($request['field'] == 'status' && !$issue->canEditStatus())
                    || (in_array($request['field'], array('pain_bug_type', 'pain_likelihood', 'pain_effect')) && !$issue->canEditUserPain()))
                {
                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));
                }

                try
                {
                    $classname = null;
                    $parameter_name = mb_strtolower($request['field']);
                    $parameter_id_name = "{$parameter_name}_id";
                    $is_pain = in_array($parameter_name, array('pain_bug_type', 'pain_likelihood', 'pain_effect'));
                    if ($is_pain)
                    {
                        switch ($parameter_name)
                        {
                            case 'pain_bug_type':
                                $set_function_name = 'setPainBugType';
                                $is_changed_function_name = 'isPainBugTypeChanged';
                                $get_pain_type_label_function = 'getPainBugTypeLabel';
                                break;
                            case 'pain_likelihood':
                                $set_function_name = 'setPainLikelihood';
                                $is_changed_function_name = 'isPainLikelihoodChanged';
                                $get_pain_type_label_function = 'getPainLikelihoodLabel';
                                break;
                            case 'pain_effect':
                                $set_function_name = 'setPainEffect';
                                $is_changed_function_name = 'isPainEffectChanged';
                                $get_pain_type_label_function = 'getPainEffectLabel';
                                break;
                        }
                    }
                    else
                    {
                        $classname = "\\thebuggenie\\core\\entities\\" . ucfirst($parameter_name);
                        $lab_function_name = $classname;
                        $set_function_name = 'set' . ucfirst($parameter_name);
                        $is_changed_function_name = 'is' . ucfirst($parameter_name) . 'Changed';
                    }
                    if ($request->hasParameter($parameter_id_name)) //$request['field'] == 'pain_bug_type')
                    {
                        $parameter_id = $request->getParameter($parameter_id_name);
                        if ($parameter_id !== 0)
                        {
                            $is_valid = ($is_pain) ? in_array($parameter_id, array_keys(entities\Issue::getPainTypesOrLabel($parameter_name))) : ($parameter_id == 0 || (($parameter = $lab_function_name::getB2DBTable()->selectByID($parameter_id)) instanceof $classname));
                        }
                        if ($parameter_id == 0 || ($parameter_id !== 0 && $is_valid))
                        {
                            if ($classname == '\\thebuggenie\\core\\entities\\Issuetype')
                            {
                                $visible_fields = ($issue->getIssuetype() instanceof entities\Issuetype) ? $issue->getProject()->getVisibleFieldsArray($issue->getIssuetype()->getID()) : array();
                            }
                            else
                            {
                                $visible_fields = null;
                            }
                            $issue->$set_function_name($parameter_id);
                            if ($is_pain)
                            {
                                if (!$issue->$is_changed_function_name())
                                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'field' => array('id' => 0), 'user_pain' => $issue->getUserPain(), 'user_pain_diff_text' => $issue->getUserPainDiffText()));

                                return ($parameter_id == 0) ? $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('id' => 0), 'user_pain' => $issue->getUserPain(), 'user_pain_diff_text' => $issue->getUserPainDiffText())) : $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('id' => $parameter_id, 'name' => $issue->$get_pain_type_label_function()), 'user_pain' => $issue->getUserPain(), 'user_pain_diff_text' => $issue->getUserPainDiffText()));
                            }
                            else
                            {
                                if (isset($parameter))
                                {
                                    if ($parameter instanceof entities\Priority) {
                                        framework\Context::loadLibrary('ui');
                                        $name = fa_image_tag($parameter->getFontAwesomeIcon(), [], $parameter->getFontAwesomeIconStyle()) . $parameter->getName();
                                    } else {
                                        $name = $parameter->getName();
                                    }
                                }
                                else
                                {
                                    $name = null;
                                }

                                $field = array('id' => $parameter_id, 'name' => $name);

                                if ($classname == '\\thebuggenie\\core\\entities\\Issuetype')
                                {
                                    framework\Context::loadLibrary('ui');
                                    $field['src'] = htmlspecialchars(framework\Context::getWebroot() . 'images/' . $issue->getIssuetype()->getIcon() . '_small.png');
                                    $field['fa_icon'] = $issue->getIssueType()->getFontAwesomeIcon();
                                }

                                if (!$issue->$is_changed_function_name())
                                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false, 'field' => $field));

                                if ($parameter_id == 0)
                                {
                                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('id' => 0)));
                                }
                                else
                                {
                                    $options = array('issue_id' => $issue->getID(), 'changed' => true, 'visible_fields' => $visible_fields, 'field' => $field);
                                    if ($request['field'] == 'milestone')
                                        $options['field']['url'] = $this->getRouting()->generate('project_milestone_details', array('project_key' => $issue->getProject()->getKey(), 'milestone_id' => $issue->getMilestone()->getID()));
                                    if ($request['field'] == 'status')
                                        $options['field']['color'] = $issue->getStatus()->getItemdata();

                                    return $this->renderJSON($options);
                                }
                            }
                        }
                    }
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => $e->getMessage()));
                }
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('No valid field value specified')));
            default:
                if ($customdatatype = entities\CustomDatatype::getByKey($request['field']))
                {
                    $key = $customdatatype->getKey();

                    $customdatatypeoption_value = $request->getParameter("{$key}_value");
                    if (!$customdatatype->hasCustomOptions())
                    {
                        switch ($customdatatype->getType())
                        {
                            case entities\CustomDatatype::EDITIONS_CHOICE:
                            case entities\CustomDatatype::COMPONENTS_CHOICE:
                            case entities\CustomDatatype::RELEASES_CHOICE:
                            case entities\CustomDatatype::STATUS_CHOICE:
                            case entities\CustomDatatype::MILESTONE_CHOICE:
                            case entities\CustomDatatype::USER_CHOICE:
                            case entities\CustomDatatype::TEAM_CHOICE:
                            case entities\CustomDatatype::CLIENT_CHOICE:
                                if ($customdatatypeoption_value == '')
                                {
                                    $issue->setCustomField($key, "");
                                    $finalvalue = "";
                                }
                                else
                                {
                                    switch ($customdatatype->getType())
                                    {
                                        case entities\CustomDatatype::EDITIONS_CHOICE:
                                            $temp = tables\Editions::getTable()->selectById($request->getRawParameter("{$key}_value"));
                                            break;
                                        case entities\CustomDatatype::COMPONENTS_CHOICE:
                                            $temp = tables\Components::getTable()->selectById($request->getRawParameter("{$key}_value"));
                                            break;
                                        case entities\CustomDatatype::RELEASES_CHOICE:
                                            $temp = tables\Builds::getTable()->selectById($request->getRawParameter("{$key}_value"));
                                            break;
                                        case entities\CustomDatatype::MILESTONE_CHOICE:
                                            $temp = tables\Milestones::getTable()->selectById($request->getRawParameter("{$key}_value"));
                                            break;
                                        case entities\CustomDatatype::STATUS_CHOICE:
                                            $temp = tables\ListTypes::getTable()->selectById($request->getRawParameter("{$key}_value"));
                                            break;
                                        case entities\CustomDatatype::USER_CHOICE:
                                            $temp = tables\Users::getTable()->selectById($request->getRawParameter("{$key}_value"));
                                            break;
                                        case entities\CustomDatatype::TEAM_CHOICE:
                                            $temp = tables\Teams::getTable()->selectById($request->getRawParameter("{$key}_value"));
                                            break;
                                        case entities\CustomDatatype::CLIENT_CHOICE:
                                            $temp = tables\Clients::getTable()->selectById($request->getRawParameter("{$key}_value"));
                                            break;
                                    }
                                        $issue->setCustomField($key, $customdatatypeoption_value);

                                if ($customdatatype->getType() == entities\CustomDatatype::STATUS_CHOICE && isset($temp) && is_object($temp))
                                {
                                    $finalvalue = '<div class="status_badge" style="background-color: ' . $temp->getColor() . ';"><span>' . $temp->getName() . '</span></div>';
                                }
                                elseif ($customdatatype->getType() == entities\CustomDatatype::USER_CHOICE && isset($temp) && is_object($temp))
                                {
                                    $finalvalue = $this->getComponentHTML('main/userdropdown', array('user' => $temp));
                                }
                                elseif ($customdatatype->getType() == entities\CustomDatatype::TEAM_CHOICE && isset($temp) && is_object($temp))
                                {
                                    $finalvalue = $this->getComponentHTML('main/teamdropdown', array('team' => $temp));
                                }
                                else
                                {
                                    $finalvalue = (is_object($temp)) ? $temp->getName() : $this->getI18n()->__('Unknown');
                                }
                                    }

                                $changed_methodname = "isCustomfield{$key}Changed";
                                if (!$issue->$changed_methodname())
                                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false));

                                return ($customdatatypeoption_value == '') ? $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('id' => 0))) : $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('value' => $key, 'name' => $finalvalue)));
                            case entities\CustomDatatype::INPUT_TEXTAREA_MAIN:
                            case entities\CustomDatatype::INPUT_TEXTAREA_SMALL:
                                if ($customdatatypeoption_value == '')
                                {
                                    $issue->setCustomField($key, "");
                                }
                                else
                                {
                                    $issue->setCustomField($key, $request->getRawParameter("{$key}_value"));
                                }
                                $changed_methodname = "isCustomfield{$key}Changed";
                                if (!$issue->$changed_methodname())
                                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false));

                                return ($customdatatypeoption_value == '') ? $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('id' => 0))) : $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('value' => $key, 'name' => tbg_parse_text($request->getRawParameter("{$key}_value")))));
                            case entities\CustomDatatype::DATE_PICKER:
                            case entities\CustomDatatype::DATETIME_PICKER:
                                if ($customdatatypeoption_value == '')
                                {
                                    $issue->setCustomField($key, "");
                                }
                                else
                                {
                                    $issue->setCustomField($key, $request->getParameter("{$key}_value"));
                                }
                                $changed_methodname = "isCustomfield{$key}Changed";
                                if (!$issue->$changed_methodname())
                                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false));

                                return ($customdatatypeoption_value == '') ? $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('id' => 0))) : $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('value' => $key, 'name' => date('Y-m-d' . ($customdatatype->getType() == entities\CustomDatatype::DATETIME_PICKER ? ' H:i' : ''), (int) $request->getRawParameter("{$key}_value")))));
                            default:
                                if ($customdatatypeoption_value == '')
                                {
                                    $issue->setCustomField($key, "");
                                }
                                else
                                {
                                    $issue->setCustomField($key, $request->getParameter("{$key}_value"));
                                }
                                $changed_methodname = "isCustomfield{$key}Changed";
                                if (!$issue->$changed_methodname())
                                    return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false));

                                return ($customdatatypeoption_value == '') ? $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('id' => 0))) : $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('value' => $key, 'name' => (filter_var($customdatatypeoption_value, FILTER_VALIDATE_URL) !== false) ? "<a href=\"{$customdatatypeoption_value}\">{$customdatatypeoption_value}</a>" : $customdatatypeoption_value)));
                        }
                    }
                    $customdatatypeoption = ($customdatatypeoption_value) ? tables\CustomFieldOptions::getTable()->selectById($customdatatypeoption_value) : null;
                    if ($customdatatypeoption instanceof entities\CustomDatatypeOption)
                    {
                        $issue->setCustomField($key, $customdatatypeoption->getID());
                    }
                    else
                    {
                        $issue->setCustomField($key, null);
                    }
                    $changed_methodname = "isCustomfield{$key}Changed";
                    if (!$issue->$changed_methodname())
                        return $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => false));

                    return (!$customdatatypeoption instanceof entities\CustomDatatypeOption) ? $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('id' => 0))) : $this->renderJSON(array('issue_id' => $issue->getID(), 'changed' => true, 'field' => array('value' => $customdatatypeoption->getID(), 'name' => $customdatatypeoption->getName())));
                }
                break;
        }

        $this->getResponse()->setHttpStatus(400);
        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('No valid field specified (%field)', array('%field' => $request['field']))));
    }

    /**
     * Reverts an issue field back to the original value
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runIssueRevertField(framework\Request $request)
    {
        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderText('fail');
            }
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderText('no issue');
        }

        $field = null;
        framework\Context::loadLibrary('common');
        switch ($request['field'])
        {
            case 'description':
                $issue->revertDescription();
                $issue->revertDescription_Syntax();
                $field = array('id' => (int) ($issue->getDescription() != ''), 'name' => $issue->getParsedDescription(array('issue' => $issue)), 'form_value' => $issue->getDescription());
                break;
            case 'reproduction_steps':
                $issue->revertReproduction_Steps();
                $issue->revertReproduction_Steps_Syntax();
                $field = array('id' => (int) ($issue->getReproductionSteps() != ''), 'name' => $issue->getParsedReproductionSteps(array('issue' => $issue)), 'form_value' => $issue->getReproductionSteps());
                break;
            case 'title':
                $issue->revertTitle();
                $field = array('id' => 1, 'name' => strip_tags($issue->getTitle()));
                break;
            case 'shortname':
                $issue->revertShortname();
                $field = array('id' => 1, 'name' => strip_tags($issue->getShortname()));
                break;
            case 'category':
                $issue->revertCategory();
                $field = ($issue->getCategory() instanceof entities\Category) ? array('id' => $issue->getCategory()->getID(), 'name' => $issue->getCategory()->getName()) : array('id' => 0);
                break;
            case 'resolution':
                $issue->revertResolution();
                $field = ($issue->getResolution() instanceof entities\Resolution) ? array('id' => $issue->getResolution()->getID(), 'name' => $issue->getResolution()->getName()) : array('id' => 0);
                break;
            case 'severity':
                $issue->revertSeverity();
                $field = ($issue->getSeverity() instanceof entities\Severity) ? array('id' => $issue->getSeverity()->getID(), 'name' => $issue->getSeverity()->getName()) : array('id' => 0);
                break;
            case 'reproducability':
                $issue->revertReproducability();
                $field = ($issue->getReproducability() instanceof entities\Reproducability) ? array('id' => $issue->getReproducability()->getID(), 'name' => $issue->getReproducability()->getName()) : array('id' => 0);
                break;
            case 'priority':
                framework\Context::loadLibrary('ui');
                $issue->revertPriority();
                $field = ($issue->getPriority() instanceof entities\Priority) ? array('id' => $issue->getPriority()->getID(), 'name' => fa_image_tag($this->issue->getPriority()->getFontAwesomeIcon(), [], $this->issue->getPriority()->getFontAwesomeIconStyle()) . $issue->getPriority()->getName()) : array('id' => 0);
                break;
            case 'percent_complete':
                $issue->revertPercentCompleted();
                $field = $issue->getPercentCompleted();
                break;
            case 'status':
                $issue->revertStatus();
                $field = ($issue->getStatus() instanceof entities\Status) ? array('id' => $issue->getStatus()->getID(), 'name' => $issue->getStatus()->getName(), 'color' => $issue->getStatus()->getColor()) : array('id' => 0);
                break;
            case 'pain_bug_type':
                $issue->revertPainBugType();
                $field = ($issue->hasPainBugType()) ? array('id' => $issue->getPainBugType(), 'name' => $issue->getPainBugTypeLabel(), 'user_pain' => $issue->getUserPain()) : array('id' => 0, 'user_pain' => $issue->getUserPain());
                break;
            case 'pain_likelihood':
                $issue->revertPainLikelihood();
                $field = ($issue->hasPainLikelihood()) ? array('id' => $issue->getPainLikelihood(), 'name' => $issue->getPainLikelihoodLabel(), 'user_pain' => $issue->getUserPain()) : array('id' => 0, 'user_pain' => $issue->getUserPain());
                break;
            case 'pain_effect':
                $issue->revertPainEffect();
                $field = ($issue->hasPainEffect()) ? array('id' => $issue->getPainEffect(), 'name' => $issue->getPainEffectLabel(), 'user_pain' => $issue->getUserPain()) : array('id' => 0, 'user_pain' => $issue->getUserPain());
                break;
            case 'issuetype':
                $issue->revertIssuetype();
                $field = ($issue->getIssuetype() instanceof entities\Issuetype) ? ['id' => $issue->getIssuetype()->getID(), 'name' => $issue->getIssuetype()->getName(), 'src' => htmlspecialchars(framework\Context::getWebroot() . 'images/' . $issue->getIssuetype()->getIcon() . '_small.png'), 'fa_icon' => $issue->getIssueType()->getFontAwesomeIcon()] : array('id' => 0);
                $visible_fields = ($issue->getIssuetype() instanceof entities\Issuetype) ? $issue->getProject()->getVisibleFieldsArray($issue->getIssuetype()->getID()) : array();
                return $this->renderJSON(['ok' => true, 'issue_id' => $issue->getID(), 'field' => $field, 'visible_fields' => $visible_fields]);
                break;
            case 'milestone':
                $issue->revertMilestone();
                $field = ($issue->getMilestone() instanceof entities\Milestone) ? array('id' => $issue->getMilestone()->getID(), 'name' => $issue->getMilestone()->getName()) : array('id' => 0);
                break;
            case 'estimated_time':
                $issue->revertEstimatedTime();
                return $this->renderJSON(array('ok' => true, 'issue_id' => $issue->getID(), 'field' => (($issue->hasEstimatedTime()) ? array('id' => 1, 'name' => entities\Issue::getFormattedTime($issue->getEstimatedTime(true, true))) : array('id' => 0)), 'values' => $issue->getEstimatedTime(true, true), 'percentbar' => $this->getComponentHTML('main/percentbar', array('percent' => $issue->getEstimatedPercentCompleted(), 'height' => 3))));
                break;
            case 'spent_time':
                $issue->revertSpentTime();
                return $this->renderJSON(array('ok' => true, 'issue_id' => $issue->getID(), 'field' => (($issue->hasSpentTime()) ? array('id' => 1, 'name' => entities\Issue::getFormattedTime($issue->getSpentTime(true, true))) : array('id' => 0)), 'values' => $issue->getSpentTime(true, true)));
                break;
            case 'owned_by':
                $issue->revertOwner();
                return $this->renderJSON(array('changed' => $issue->isOwnerChanged(), 'field' => (($issue->isOwned()) ? array('id' => $issue->getOwner()->getID(), 'name' => (($issue->getOwner() instanceof entities\User) ? $this->getComponentHTML('main/userdropdown', array('user' => $issue->getOwner())) : $this->getComponentHTML('main/teamdropdown', array('team' => $issue->getOwner())))) : array('id' => 0))));
                break;
            case 'assigned_to':
                $issue->revertAssignee();
                return $this->renderJSON(array('changed' => $issue->isAssigneeChanged(), 'field' => (($issue->isAssigned()) ? array('id' => $issue->getAssignee()->getID(), 'name' => (($issue->getAssignee() instanceof entities\User) ? $this->getComponentHTML('main/userdropdown', array('user' => $issue->getAssignee())) : $this->getComponentHTML('main/teamdropdown', array('team' => $issue->getAssignee())))) : array('id' => 0))));
                break;
            case 'posted_by':
                $issue->revertPostedBy();
                return $this->renderJSON(array('changed' => $issue->isPostedByChanged(), 'field' => array('id' => $issue->getPostedByID(), 'name' => $this->getComponentHTML('main/userdropdown', array('user' => $issue->getPostedBy())))));
                break;
            default:
                if ($customdatatype = entities\CustomDatatype::getByKey($request['field']))
                {
                    $key = $customdatatype->getKey();
                    $revert_methodname = "revertCustomfield{$key}";
                    $issue->$revert_methodname();

                    if ($customdatatype->hasCustomOptions())
                    {
                        $field = ($issue->getCustomField($key) instanceof entities\CustomDatatypeOption) ? array('value' => $issue->getCustomField($key)->getID(), 'name' => $issue->getCustomField($key)->getName()) : array('id' => 0);
                    }
                    else
                    {
                        switch ($customdatatype->getType())
                        {
                            case entities\CustomDatatype::INPUT_TEXTAREA_MAIN:
                            case entities\CustomDatatype::INPUT_TEXTAREA_SMALL:
                                $field = ($issue->getCustomField($key) != '') ? array('value' => $key, 'name' => tbg_parse_text($issue->getCustomField($key))) : array('id' => 0);
                                break;
                            default:
                                $field = ($issue->getCustomField($key) != '') ? array('value' => $key, 'name' => $issue->getCustomField($key)) : array('id' => 0);
                                break;
                        }
                    }
                }
                break;
        }

        if ($field !== null)
        {
            return $this->renderJSON(array('ok' => true, 'issue_id' => $issue->getID(), 'field' => $field));
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('No valid field specified (%field)', array('%field' => $request['field']))));
        }
    }

    /**
     * Unlock the issue
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runUnlockIssue(framework\Request $request)
    {
        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('message' => framework\Context::getI18n()->__('This issue does not exist')));
            }

            if (!$issue->canEditAccessPolicy())
            {
                $this->forward403($this->getI18n()->__("You don't have access to update the issue access policy"));
                return;
            }

            framework\Event::listen('core', 'thebuggenie\core\entities\Issue::save_pre_notifications', array($this, 'listen_issueSaveUnlock'));
            $issue->setLocked(false);
            $issue->setLockedCategory($request->hasParameter('public_category'));
            $issue->save();
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('message' => framework\Context::getI18n()->__('This issue does not exist')));
        }

        return $this->renderJSON(array('message' => $this->getI18n()->__('Issue access policy updated')));
    }

    public function listen_issueSaveUnlock(framework\Event $event)
    {
        $this->_unlockIssueAfter(framework\Context::getRequest(), $event->getSubject());
    }

    /**
     * Unlock the issue
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runLockIssue(framework\Request $request)
    {
        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('message' => framework\Context::getI18n()->__('This issue does not exist')));
            }

            if (!$issue->canEditAccessPolicy())
            {
                $this->forward403($this->getI18n()->__("You don't have access to update the issue access policy"));
                return;
            }

            framework\Event::listen('core', 'thebuggenie\core\entities\Issue::save_pre_notifications', array($this, 'listen_issueSaveLock'));
            $issue->setLocked();
            $issue->save();
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('message' => framework\Context::getI18n()->__('This issue does not exist')));
        }

        return $this->renderJSON(array('message' => $this->getI18n()->__('Issue access policy updated')));
    }

    public function listen_issueSaveLock(framework\Event $event)
    {
        $this->_lockIssueAfter(framework\Context::getRequest(), $event->getSubject());
    }

    /**
     * Mark the issue as not blocking the next release
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runMarkAsNotBlocker(framework\Request $request)
    {
        $this->forward403unless($this->getUser()->hasPermission('caneditissue') || $this->getUser()->hasPermission('caneditissuebasic'));

        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('message' => framework\Context::getI18n()->__('This issue does not exist')));
            }
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('message' => framework\Context::getI18n()->__('This issue does not exist')));
        }

        $issue->setBlocking(false);
        $issue->save();

        return $this->renderJSON('not blocking');
    }

    /**
     * Mark the issue as blocking the next release
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runMarkAsBlocker(framework\Request $request)
    {
        $this->forward403unless($this->getUser()->hasPermission('caneditissue') || $this->getUser()->hasPermission('caneditissuebasic'));

        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('message' => framework\Context::getI18n()->__('This issue does not exist')));
            }
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('message' => framework\Context::getI18n()->__('This issue does not exist')));
        }

        $issue->setBlocking();
        $issue->save();

        return $this->renderJSON('blocking');
    }

    /**
     * Delete an issue
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runDeleteIssue(framework\Request $request)
    {
        $request_referer = ($request['referer'] ?: isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null);

        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                if ($request_referer)
                {
                    return $this->forward($request_referer);
                }

                return $this->return404(framework\Context::getI18n()->__('This issue does not exist'));
            }
        }
        else
        {
            if ($request_referer)
            {
                return $this->forward($request_referer);
            }

            return $this->return404(framework\Context::getI18n()->__('This issue does not exist'));
        }

        if ($issue->isDeleted())
        {
            return $this->forward($request_referer);
        }

        $this->forward403unless($issue->canDeleteIssue());
        $issue->deleteIssue();
        $issue->save();

        framework\Context::setMessage('issue_deleted', true);
        $this->forward($this->getRouting()->generate('viewissue', array('project_key' => $issue->getProject()->getKey(), 'issue_no' => $issue->getFormattedIssueNo())) . '?referer=' . $request_referer);
    }

    /**
     * Find users and show selection links
     *
     * @param \thebuggenie\core\framework\Request $request The request object
     */
    public function runFindIdentifiable(framework\Request $request)
    {
        $this->forward403unless($request->isPost());
        $users = array();

        if ($find_identifiable_by = $request['find_identifiable_by'])
        {
            if ($request['include_clients'])
            {
                $clients = tables\Clients::getTable()->quickfind($find_identifiable_by);
            }
            else
            {
                $users = tables\Users::getTable()->getByDetails($find_identifiable_by, 10);
                if ($request['include_teams'])
                {
                    $teams = tables\Teams::getTable()->quickfind($find_identifiable_by);
                }
                else
                {
                    $teams = array();
                }
            }
        }
        $teamup_callback = $request['teamup_callback'];
        $team_callback = $request['team_callback'];
        $callback = $request['callback'];
        return $this->renderComponent('identifiableselectorresults', compact('users', 'teams', 'clients', 'callback', 'teamup_callback', 'team_callback'));
    }

    /**
     * Hides an infobox with a specific key
     *
     * @param \thebuggenie\core\framework\Request $request The request object
     */
    public function runHideInfobox(framework\Request $request)
    {
        framework\Settings::hideInfoBox($request['key']);
        return $this->renderJSON(array('hidden' => true));
    }

    public function runSetToggle(framework\Request $request)
    {
        framework\Settings::setToggle($request['key'], $request['state']);
        return $this->renderJSON(array('state' => $request['state']));
    }

    public function runGetUploadStatus(framework\Request $request)
    {
        $id = $request->getParameter('upload_id', 0);

        framework\Logging::log('requesting status for upload with id ' . $id);
        $status = framework\Context::getRequest()->getUploadStatus($id);
        framework\Logging::log('status was: ' . (int) $status['finished'] . ', pct: ' . (int) $status['percent']);
        if (array_key_exists('file_id', $status) && $request['mode'] == 'issue')
        {
            $file = tables\Files::getTable()->selectById($status['file_id']);
            $status['content_uploader'] = $this->getComponentHTML('main/attachedfile', array('base_id' => 'uploaded_files', 'mode' => 'issue', 'issue_id' => $request['issue_id'], 'file' => $file));
            $status['content_inline'] = $this->getComponentHTML('main/attachedfile', array('base_id' => 'viewissue_files', 'mode' => 'issue', 'issue_id' => $request['issue_id'], 'file' => $file));
            $issue = tables\Issues::getTable()->selectById($request['issue_id']);
            $status['attachmentcount'] = count($issue->getFiles()) + count($issue->getLinks());
        }
        elseif (array_key_exists('file_id', $status) && $request['mode'] == 'article')
        {
            $file = tables\Files::getTable()->selectById($status['file_id']);
            $status['content_uploader'] = $this->getComponentHTML('main/attachedfile', array('base_id' => 'article_' . $request['article_id'] . '_files', 'mode' => 'article', 'article_id' => $request['article_id'], 'file' => $file));
            $status['content_inline'] = $this->getComponentHTML('main/attachedfile', array('base_id' => 'article_' . $request['article_id'] . '_files', 'mode' => 'article', 'article_id' => $request['article_id'], 'file' => $file));
            $article = \thebuggenie\modules\publish\entities\Article::getByID($request['article_id']);
            $status['attachmentcount'] = count($article->getFiles());
        }

        return $this->renderJSON($status);
    }

    public function runUpdateAttachments(framework\Request $request)
    {
        switch ($request['target'])
        {
            case 'issue':
                $target = tables\Issues::getTable()->selectById($request['target_id']);
                $base_id = 'viewissue_files';
                $container_id = 'viewissue_uploaded_files';
                $target_identifier = 'issue_id';
                $target_id = $target->getID();
                break;
            case 'article':
                $target = \thebuggenie\modules\publish\entities\tables\Articles::getTable()->selectById($request['target_id']);
                $container_id = 'article_' . $target->getID() . '_files';
                $base_id = $container_id;
                $target_identifier = 'article_id';
                $target_id = $request['article_id'];
                break;
        }
        $saved_file_ids = $request['files'];
        $files = $image_files = array();
        $comments = '';
        foreach ($request['file_description'] ?: array() as $file_id => $description)
        {
            $file = tables\Files::getTable()->selectById($file_id);

            if (! $file instanceof entities\File) continue;

            $file->setDescription($description);
            $file->save();
            if (in_array($file_id, $saved_file_ids))
            {
                if ($target instanceof entities\Issue)
                {
                    $comment = $target->attachFile($file, '', '', true);

                    if ($comment instanceof entities\Comment) $comments = $this->getComponentHTML('main/comment', array('comment' => $comment, 'issue' => $target, 'mentionable_target_type' => 'issue', 'comment_count_div' => 'viewissue_comment_count')) . $comments;
                }
                else
                {
                    $target->attachFile($file);
                }
            }
            else
            {
                $target->detachFile($file);
            }
            if ($file->isImage()) {
                $image_files[] = $this->getComponentHTML('main/attachedfile', array('base_id' => $base_id, 'mode' => $request['target'], $request['target'] => $target, $target_identifier => $target_id, 'file' => $file));
            }
            else {
                $files[] = $this->getComponentHTML('main/attachedfile', array('base_id' => $base_id, 'mode' => $request['target'], $request['target'] => $target, $target_identifier => $target_id, 'file' => $file));
            }
        }
        $attachmentcount = ($request['target'] == 'issue') ? $target->countFiles() + $target->countLinks() : $target->countFiles();

        return $this->renderJSON(array('attached' => 'ok', 'container_id' => $container_id, 'files' => array_reverse(array_merge($files, $image_files)), 'attachmentcount' => $attachmentcount, 'comments' => $comments));
    }

    public function runUploadFile(framework\Request $request)
    {
        if (!isset($_SESSION['upload_files']))
        {
            $_SESSION['upload_files'] = array();
        }

        $files = array();
        $files_dir = framework\Settings::getUploadsLocalpath();

        foreach ($request->getUploadedFiles() as $key => $file)
        {
            $file['name'] = str_replace(array('[', ']'), array('(', ')'), $file['name']);
            $new_filename = framework\Context::getUser()->getID() . '_' . NOW . '_' . basename($file['name']);
            if (framework\Settings::getUploadStorage() == 'files')
            {
                $filename = $files_dir . $new_filename;
            }
            else
            {
                $filename = $file['tmp_name'];
            }
            framework\Logging::log('Moving uploaded file to ' . $filename);
            if (framework\Settings::getUploadStorage() == 'files' && !move_uploaded_file($file['tmp_name'], $filename))
            {
                framework\Logging::log('Moving uploaded file failed!');
                throw new \Exception(framework\Context::getI18n()->__('An error occured when saving the file'));
            }
            else
            {
                framework\Logging::log('Upload complete and ok, storing upload status and returning filename ' . $new_filename);
                $content_type = entities\File::getMimeType($filename);
                if (framework\Settings::getUploadStorage() == 'database')
                {
                    $file_object_id = tables\Files::getTable()->saveFile($new_filename, basename($file['name']), $content_type, null, file_get_contents($filename));
                }
                else {
                    $file_object_id = tables\Files::getTable()->saveFile($new_filename, basename($file['name']), $content_type);
                }
                return $this->renderJSON(array('file_id' => $file_object_id));
            }
        }

        return $this->renderJSON(array('error' => $this->getI18n()->__('An error occurred when uploading the file')));
    }

    public function runUpload(framework\Request $request)
    {
        $apc_exists = framework\Request::CanGetUploadStatus();
        if ($apc_exists && !$request['APC_UPLOAD_PROGRESS'])
        {
            $request->setParameter('APC_UPLOAD_PROGRESS', $request['upload_id']);
        }
        $this->getResponse()->setDecoration(\thebuggenie\core\framework\Response::DECORATE_NONE);

        $canupload = false;

        if ($request['mode'] == 'issue')
        {
            $issue = tables\Issues::getTable()->selectById($request['issue_id']);
            $canupload = (bool) ($issue instanceof entities\Issue && $issue->hasAccess() && $issue->canAttachFiles());
        }
        elseif ($request['mode'] == 'article')
        {
            $article = \thebuggenie\modules\publish\entities\Article::getByID($request['article_id']);
            $canupload = (bool) ($article instanceof \thebuggenie\modules\publish\entities\Article && $article->canEdit());
        }
        else
        {
            $event = framework\Event::createNew('core', 'upload', $request['mode']);
            $event->triggerUntilProcessed();

            $canupload = ($event->isProcessed()) ? (bool) $event->getReturnValue() : true;
        }

        if ($canupload)
        {
            try
            {
                $file = framework\Context::getRequest()->handleUpload('uploader_file');
                if ($file instanceof entities\File)
                {
                    switch ($request['mode'])
                    {
                        case 'issue':
                            if (!$issue instanceof entities\Issue)
                                break;
                            $issue->attachFile($file, $request->getRawParameter('comment'), $request['uploader_file_description']);
                            $issue->save();
                            break;
                        case 'article':
                            if (!$article instanceof \thebuggenie\modules\publish\entities\Article)
                                break;

                            $article->attachFile($file);
                            break;
                    }
                    if ($apc_exists)
                        return $this->renderText('ok');
                }
                $this->error = framework\Context::getI18n()->__('An unhandled error occured with the upload');
            }
            catch (\Exception $e)
            {
                $this->getResponse()->setHttpStatus(400);
                $this->error = $e->getMessage();
            }
        }
        else
        {
            $this->error = framework\Context::getI18n()->__('You are not allowed to attach files here');
        }
        if (!$apc_exists)
        {
            switch ($request['mode'])
            {
                case 'issue':
                    if (!$issue instanceof entities\Issue)
                        break;

                    $this->forward($this->getRouting()->generate('viewissue', array('project_key' => $issue->getProject()->getKey(), 'issue_no' => $issue->getFormattedIssueNo())));
                    break;
                case 'article':
                    if (!$article instanceof \thebuggenie\modules\publish\entities\Article)
                        break;

                    $this->forward($this->getRouting()->generate('publish_article_attachments', array('article_id' => $article->getID())));
                    break;
            }
        }
        framework\Logging::log('marking upload ' . $request['APC_UPLOAD_PROGRESS'] . ' as completed with error ' . $this->error);
        $request->markUploadAsFinishedWithError($request['APC_UPLOAD_PROGRESS'], $this->error);
        return $this->renderText($request['APC_UPLOAD_PROGRESS'] . ': ' . $this->error);
    }

    public function runDetachFile(framework\Request $request)
    {
        try
        {
            $file = tables\Files::getTable()->selectById((int) $request['file_id']);
            switch ($request['mode'])
            {
                case 'issue':
                    $issue = tables\Issues::getTable()->selectById($request['issue_id']);
                    if ($issue instanceof entities\Issue && $issue->canRemoveAttachments() && (int) $request->getParameter('file_id', 0))
                    {
                        $issue->detachFile($file);
                        return $this->renderJSON(array('file_id' => $request['file_id'], 'attachmentcount' => (count($issue->getFiles()) + count($issue->getLinks())), 'message' => framework\Context::getI18n()->__('The attachment has been removed')));
                    }
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You can not remove items from this issue')));
                case 'article':
                    $article = \thebuggenie\modules\publish\entities\Article::getByID($request['article_id']);
                    if ($article instanceof \thebuggenie\modules\publish\entities\Article && $article->canEdit() && (int) $request->getParameter('file_id', 0))
                    {
                        $article->detachFile($file);
                        return $this->renderJSON(array('file_id' => $request['file_id'], 'attachmentcount' => count($article->getFiles()), 'message' => framework\Context::getI18n()->__('The attachment has been removed')));
                    }
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You can not remove items from this issue')));
            }
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $this->getI18n()->__('An error occurred when removing the file')));
        }
        $this->getResponse()->setHttpStatus(400);
        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Invalid mode')));
    }

    public function runGetFile(framework\Request $request)
    {
        $file = new entities\File((int) $request['id']);
        if ($file instanceof entities\File)
        {
            if ($file->hasAccess())
            {
                $disableCache = true;
                $isFile = false;
                $this->getResponse()->cleanBuffer();
                $this->getResponse()->clearHeaders();
                $this->getResponse()->setDecoration(\thebuggenie\core\framework\Response::DECORATE_NONE);

                if ($file->isImage() && \thebuggenie\core\framework\Settings::isUploadsImageCachingEnabled()) {
                  $this->getResponse()->addHeader('Pragma: public');
                  $this->getResponse()->addHeader('Cache-Control: public, max-age: 15768000');
                  $this->getResponse()->addHeader("Expires: " . gmdate('D, d M Y H:i:s', time() + 15768000) . " GMT");
                  $disableCache = false;
                }

                $this->getResponse()->addHeader('Content-disposition: ' . (($request['mode'] == 'download') ? 'attachment' : 'inline') . '; filename="' . $file->getOriginalFilename() . '"');
                $this->getResponse()->setContentType($file->getContentType());
                if (framework\Settings::getUploadStorage() == 'files')
                {
                    $fh = fopen(framework\Settings::getUploadsLocalpath() . $file->getRealFilename(), 'r');
                    $isFile = true;
                }
                else
                {
                    $fh = $file->getContent();
                }
                if (is_resource($fh))
                {
                    if ($isFile && \thebuggenie\core\framework\Settings::isUploadsDeliveryUseXsend()) {
                        $this->getResponse()->addHeader('X-Sendfile: ' . framework\Settings::getUploadsLocalpath() . $file->getRealFilename());
                        $this->getResponse()->addHeader('X-Accel-Redirect: /private/' . $file->getRealFilename());

                        $this->getResponse()->renderHeaders($disableCache);
                    }
                    else
                    {
                        $this->getResponse()->renderHeaders($disableCache);
                        fpassthru($fh);
                    }
                }
                else
                {
                    $this->getResponse()->renderHeaders($disableCache);
                    echo $fh;
                }
                exit();
            }
        }
        $this->return404(framework\Context::getI18n()->__('This file does not exist'));
    }

    public function runAttachLinkToIssue(framework\Request $request)
    {
        $issue = tables\Issues::getTable()->selectById($request['issue_id']);
        if ($issue instanceof entities\Issue && $issue->canAttachLinks())
        {
            if ($request['link_url'] != '')
            {
                $link_id = $issue->attachLink($request['link_url'], $request['description']);
                return $this->renderJSON(array('message' => framework\Context::getI18n()->__('Link attached!'), 'attachmentcount' => (count($issue->getFiles()) + count($issue->getLinks())), 'content' => $this->getComponentHTML('main/attachedlink', array('issue' => $issue, 'link_id' => $link_id, 'link' => array('description' => $request['description'], 'url' => $request['link_url'])))));
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You have to provide a link URL, otherwise we have nowhere to link to!')));
        }
        $this->getResponse()->setHttpStatus(400);
        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You can not attach links to this issue')));
    }

    public function runRemoveLinkFromIssue(framework\Request $request)
    {
        $issue = tables\Issues::getTable()->selectById($request['issue_id']);
        if ($issue instanceof entities\Issue && $issue->canRemoveAttachments())
        {
            if ($request['link_id'] != 0)
            {
                $issue->removeLink($request['link_id']);
                return $this->renderJSON(array('attachmentcount' => (count($issue->getFiles()) + count($issue->getLinks())), 'message' => framework\Context::getI18n()->__('Link removed!')));
            }
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You have to provide a valid link id')));
        }
        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You can not remove items from this issue')));
    }

    public function runAttachLink(framework\Request $request)
    {
        $link_id = tables\Links::getTable()->addLink($request['target_type'], $request['target_id'], $request['link_url'], $request->getRawParameter('description'));
        return $this->renderJSON(array('message' => framework\Context::getI18n()->__('Link added!'), 'content' => $this->getComponentHTML('main/menulink', array('link_id' => $link_id, 'link' => array('target_type' => $request['target_type'], 'target_id' => $request['target_id'], 'description' => $request->getRawParameter('description'), 'url' => $request['link_url'])))));
    }

    public function runRemoveLink(framework\Request $request)
    {
        if (!$this->getUser()->canEditMainMenu($request['target_type']))
        {
            $this->getResponse()->setHttpStatus(403);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You do not have access to removing links')));
        }

        if (!$request['link_id'])
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You have to provide a valid link id')));
        }

        tables\Links::getTable()->removeByTargetTypeTargetIDandLinkID($request['target_type'], $request['target_id'], $request['link_id']);
        return $this->renderJSON(array('message' => framework\Context::getI18n()->__('Link removed!')));
    }

    public function runSaveMenuOrder(framework\Request $request)
    {
        $target_type = $request['target_type'];
        $target_id = $request['target_id'];
        tables\Links::getTable()->saveLinkOrder($request[$target_type . '_' . $target_id . '_links']);
        return $this->renderJSON('ok');
    }

    public function runDeleteComment(framework\Request $request)
    {
        $comment = tables\Comments::getTable()->selectById($request['comment_id']);
        if ($comment instanceof entities\Comment)
        {
            if (!$comment->canUserDelete(framework\Context::getUser()))
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You are not allowed to do this')));
            }
            else
            {
                unset($comment);
                $comment = tables\Comments::getTable()->selectById((int) $request['comment_id']);
                $comment->delete();
                return $this->renderJSON(array('title' => framework\Context::getI18n()->__('Comment deleted!')));
            }
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Comment ID is invalid')));
        }
    }

    public function runUpdateComment(framework\Request $request)
    {
        framework\Context::loadLibrary('ui');
        $comment = tables\Comments::getTable()->selectById($request['comment_id']);
        if ($comment instanceof entities\Comment)
        {
            if (!$comment->canUserEdit(framework\Context::getUser()))
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You are not allowed to do this')));
            }
            else
            {
                if ($request['comment_body'] == '')
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => framework\Context::getI18n()->__('The comment must have some content')));
                }

                if ($comment->getTarget() instanceof entities\Issue) {
                    framework\Context::setCurrentProject($comment->getTarget()->getProject());
                }

                $comment->setContent($request->getRawParameter('comment_body'));
                $comment->setIsPublic($request['comment_visibility']);
                $comment->setSyntax($request['comment_body_syntax']);
                $comment->setUpdatedBy($this->getUser()->getID());
                $comment->save();

                framework\Context::loadLibrary('common');
                $body = $comment->getParsedContent();

                return $this->renderJSON(array('title' => framework\Context::getI18n()->__('Comment edited!'), 'comment_body' => $body));
            }
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Comment ID is invalid')));
        }
    }

    public function listenIssueSaveAddComment(framework\Event $event)
    {
        $this->comment_lines = $event->getParameter('comment_lines');
        $this->comment = $event->getParameter('comment');
    }

    public function listenViewIssuePostError(framework\Event $event)
    {
        if (framework\Context::hasMessage('comment_error'))
        {
            $this->comment_error = true;
            $this->error = framework\Context::getMessageAndClear('comment_error');
            $this->comment_error_body = framework\Context::getMessageAndClear('comment_error_body');
        }
    }

    public function runAddComment(framework\Request $request)
    {
        $i18n = framework\Context::getI18n();
        $comment_applies_type = $request['comment_applies_type'];
        try
        {
            if (!$this->getUser()->canPostComments())
            {
                throw new \Exception($i18n->__('You are not allowed to do this'));
            }
            if (!trim($request['comment_body']))
            {
                throw new \Exception($i18n->__('The comment must have some content'));
            }

            $comment = new entities\Comment();
            $comment->setContent($request->getParameter('comment_body', null, false));
            $comment->setPostedBy($this->getUser()->getID());
            $comment->setTargetID($request['comment_applies_id']);
            $comment->setTargetType($request['comment_applies_type']);
            $comment->setReplyToComment($request['reply_to_comment_id']);
            $comment->setModuleName($request['comment_module']);
            $comment->setIsPublic((bool) $request['comment_visibility']);
            $comment->setSyntax($request['comment_body_syntax']);
            $comment->save();

            if ($comment_applies_type == entities\Comment::TYPE_ISSUE)
            {
                $issue = tables\Issues::getTable()->selectById((int) $request['comment_applies_id']);
                if (!$request->isAjaxCall() || $request['comment_save_changes'])
                {
                    $issue->setSaveComment($comment);
                    $issue->save();
                }
                else
                {
                    framework\Event::createNew('core', 'thebuggenie\core\entities\Comment::createNew', $comment, compact('issue'))->trigger();
                }
            }
            elseif ($comment_applies_type == entities\Comment::TYPE_ARTICLE)
            {
                $article = \thebuggenie\modules\publish\entities\tables\Articles::getTable()->selectById((int) $request['comment_applies_id']);
                framework\Event::createNew('core', 'thebuggenie\core\entities\Comment::createNew', $comment, compact('article'))->trigger();
            }

            $component_name = ($comment->isReply()) ? 'main/comment' : 'main/commentwrapper';
            switch ($comment_applies_type)
            {
                case entities\Comment::TYPE_ISSUE:
                    $issue = tables\Issues::getTable()->selectById($request['comment_applies_id']);

                    framework\Context::setCurrentProject($issue->getProject());
                    $comment_html = $this->getComponentHTML($component_name, array('comment' => $comment, 'issue' => $issue, 'options' => ['issue' => $issue], 'mentionable_target_type' => 'issue', 'comment_count_div' => 'viewissue_comment_count'));
                    break;
                case entities\Comment::TYPE_ARTICLE:
                    $comment_html = $this->getComponentHTML($component_name, array('comment' => $comment, 'mentionable_target_type' => 'article', 'options' => [], 'comment_count_div' => 'article_comment_count'));
                    break;
                default:
                    $comment_html = 'OH NO!';
            }
        }
        catch (\Exception $e)
        {
            if ($request->isAjaxCall())
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => $e->getMessage()));
            }
            else
            {
                framework\Context::setMessage('comment_error', $e->getMessage());
                framework\Context::setMessage('comment_error_body', $request['comment_body']);
                framework\Context::setMessage('comment_error_visibility', $request['comment_visibility']);
            }
        }
        if ($request->isAjaxCall())
            return $this->renderJSON(array('title' => $i18n->__('Comment added!'), 'comment_data' => $comment_html, 'comment_id' => $comment->getID(), 'continue_url' => $request['forward_url'], 'commentcount' => entities\Comment::countComments($request['comment_applies_id'], $request['comment_applies_type']/* , $request['comment_module'] */)));
        if (isset($comment) && $comment instanceof entities\Comment)
            $this->forward($request['forward_url'] . "#comment_{$request['comment_applies_type']}_{$request['comment_applies_id']}_{$comment->getID()}");
        else
            $this->forward($request['forward_url']);
    }

    public function runListProjects(framework\Request $request)
    {
        $projects = entities\Project::getAll();

        $return_array = array();
        foreach ($projects as $project)
        {
            $return_array[$project->getKey()] = $project->getName();
        }

        $this->projects = $return_array;
    }

    public function runListIssuetypes(framework\Request $request)
    {
        $issuetypes = entities\Issuetype::getAll();

        $return_array = array();
        foreach ($issuetypes as $issuetype)
        {
            $return_array[$issuetype->getKey()] = $issuetype->getName();
        }

        $this->issuetypes = $return_array;
    }

    public function runListFieldvalues(framework\Request $request)
    {
        $field_key = $request['field_key'];
        $return_array = array('description' => null, 'type' => null, 'choices' => null);
        if ($field_key == 'title' || in_array($field_key, entities\DatatypeBase::getAvailableFields(true)))
        {
            switch ($field_key)
            {
                case 'title':
                case 'shortname':
                    $return_array['description'] = framework\Context::getI18n()->__('Single line text input without formatting');
                    $return_array['type'] = 'single_line_input';
                    break;
                case 'description':
                case 'reproduction_steps':
                    $return_array['description'] = framework\Context::getI18n()->__('Text input with wiki formatting capabilities');
                    $return_array['type'] = 'wiki_input';
                    break;
                case 'status':
                case 'resolution':
                case 'reproducability':
                case 'priority':
                case 'severity':
                case 'category':
                    $return_array['description'] = framework\Context::getI18n()->__('Choose one of the available values');
                    $return_array['type'] = 'choice';

                    $classname = "\\thebuggenie\\core\\entities\\" . ucfirst($field_key);
                    $choices = $classname::getAll();
                    foreach ($choices as $choice_key => $choice)
                    {
                        $return_array['choices'][$choice_key] = $choice->getName();
                    }
                    break;
                case 'percent_complete':
                    $return_array['description'] = framework\Context::getI18n()->__('Value of percentage completed');
                    $return_array['type'] = 'choice';
                    $return_array['choices'][] = "1-100%";
                    break;
                case 'owner':
                case 'assignee':
                    $return_array['description'] = framework\Context::getI18n()->__('Select an existing user or <none>');
                    $return_array['type'] = 'select_user';
                    break;
                case 'estimated_time':
                case 'spent_time':
                    $return_array['description'] = framework\Context::getI18n()->__('Enter time, such as points, hours, minutes, etc or <none>');
                    $return_array['type'] = 'time';
                    break;
                case 'milestone':
                    $return_array['description'] = framework\Context::getI18n()->__('Select from available project milestones');
                    $return_array['type'] = 'choice';
                    if ($this->selected_project instanceof entities\Project)
                    {
                        $milestones = $this->selected_project->getAvailableMilestones();
                        foreach ($milestones as $milestone)
                        {
                            $return_array['choices'][$milestone->getID()] = $milestone->getName();
                        }
                    }
                    break;
            }
        }
        else
        {

        }

        $this->field_info = $return_array;
    }

    /**
     * Partial backdrop loader
     *
     * @Route(name="get_partial_for_backdrop", url="/get/partials/:key/*")
     * @AnonymousRoute
     *
     * @param framework\Request $request
     *
     * @return bool
     */
    public function runGetBackdropPartial(framework\Request $request)
    {
        if (!$request->isAjaxCall())
        {
            return $this->return404($this->getI18n()->__('You need to enable javascript for The Bug Genie to work properly'));
        }
        try
        {
            $template_name = null;
            if ($request->hasParameter('issue_id'))
            {
                $issue = tables\Issues::getTable()->selectById($request['issue_id']);
                $options = array('issue' => $issue);
            }
            else
            {
                $options = array();
            }
            switch ($request['key'])
            {
                case 'usercard':
                    $template_name = 'main/usercard';
                    if ($user_id = $request['user_id'])
                    {
                        $user = tables\Users::getTable()->selectById($user_id);
                        $options['user'] = $user;
                    }
                    break;
                case 'login':
                    $template_name = 'main/loginpopup';
                    $options = $request->getParameters();
                    $options['content'] = $this->getComponentHTML('login', array('section' => $request->getParameter('section', 'login')));
                    $options['mandatory'] = false;
                    break;
                case 'uploader':
                    $template_name = 'main/uploader';
                    $options = $request->getParameters();
                    $options['uploader'] = ($request['uploader'] == 'dynamic') ? 'dynamic' : 'standard';
                    break;
                case 'attachlink':
                    $template_name = 'main/attachlink';
                    break;
                case 'notifications':
                    $template_name = 'main/notifications';
                    $options['first_notification_id'] = $request['first_notification_id'];
                    $options['last_notification_id'] = $request['last_notification_id'];
                    break;
                case 'workflow_transition':
                    $transition = tables\WorkflowTransitions::getTable()->selectById($request['transition_id']);
                    $template_name = $transition->getTemplate();
                    $options['transition'] = $transition;
                    if ($request->hasParameter('issue_ids'))
                    {
                        $options['issues'] = array();
                        foreach ($request['issue_ids'] as $issue_id)
                        {
                            $options['issues'][$issue_id] = new entities\Issue($issue_id);
                        }
                    }
                    else
                    {
                        $options['issue'] = new entities\Issue($request['issue_id']);
                    }
                    $options['show'] = true;
                    $options['interactive'] = true;
                    $options['project'] = $this->selected_project;
                    break;
                case 'reportissue':
                    $this->_loadSelectedProjectAndIssueTypeFromRequestForReportIssueAction($request);
                    if ($this->selected_project instanceof entities\Project && !$this->selected_project->isLocked() && $this->getUser()->canReportIssues($this->selected_project)) {
                        $template_name = 'main/reportissuecontainer';
                        $options['selected_project'] = $this->selected_project;
                        $options['selected_issuetype'] = $this->selected_issuetype;
                        $options['locked_issuetype'] = $this->locked_issuetype;
                        $options['selected_milestone'] = $this->_getMilestoneFromRequest($request);
                        $options['parent_issue'] = $this->_getParentIssueFromRequest($request);
                        $options['board'] = $this->_getBoardFromRequest($request);
                        $options['selected_build'] = $this->_getBuildFromRequest($request);
                        $options['issuetypes'] = $this->issuetypes;
                        $options['errors'] = array();
                    } else {
                        throw new \Exception($this->getI18n()->__('You are not allowed to do this'));
                    }
                    break;
                case 'move_issue':
                    $template_name = 'main/moveissue';
                    $options['multi'] = (bool) $request->getParameter('multi', false);
                    break;
                case 'issue_permissions':
                    $template_name = 'main/issuepermissions';
                    break;
                case 'issue_subscribers':
                    $template_name = 'main/issuesubscribers';
                    break;
                case 'issue_spenttimes':
                    $template_name = 'main/issuespenttimes';
                    $options['initial_view'] = $request->getParameter('initial_view', 'list');
                    break;
                case 'issue_spenttime':
                    $template_name = 'main/issuespenttime';
                    $options['entry_id'] = $request->getParameter('entry_id');
                    break;
                case 'relate_issue':
                    $template_name = 'main/relateissue';
                    break;
                case 'project_build':
                    $template_name = 'project/build';
                    $options['project'] = tables\Projects::getTable()->selectById($request['project_id']);
                    if ($request->hasParameter('build_id'))
                        $options['build'] = tables\Builds::getTable()->selectById($request['build_id']);
                    break;
                case 'project_icons':
                    $template_name = 'project/projecticons';
                    $options['project'] = tables\Projects::getTable()->selectById($request['project_id']);
                    break;
                case 'project_workflow':
                    $template_name = 'project/projectworkflow';
                    $options['project'] = tables\Projects::getTable()->selectById($request['project_id']);
                    break;
                case 'permissions':
                    $options['key'] = $request['permission_key'];
                    $target_module = ($request['target_module'] !== 'core') ? $request['target_module'] : null;
                    if ($details = framework\Context::getPermissionDetails($options['key'], null, $target_module))
                    {
                        $template_name = 'configuration/permissionspopup';
                        $options['mode'] = $request['mode'];
                        $options['module'] = $request['target_module'];
                        $options['target_id'] = $request['target_id'];
                        $options['item_name'] = $details['description'];
                        $options['access_level'] = $request['access_level'];
                    }
                    break;
                case 'issuefield_permissions':
                    $options['item_key'] = $request['item_key'];
                    if ($details = framework\Context::getPermissionDetails($options['item_key']))
                    {
                        $template_name = 'configuration/issuefieldpermissions';
                        $options['item_name'] = $details['description'];
                        $options['item_id'] = $request['item_id'];
                        $options['access_level'] = $request['access_level'];
                    }
                    break;
                case 'site_icons':
                    $template_name = 'configuration/siteicons';
                    break;
                case 'project_config':
                    $template_name = 'project/editproject';
                    if ($request['project_id']) {
                        $project = tables\Projects::getTable()->selectById($request['project_id']);
                    } else {
                        $project = new entities\Project();
                        framework\Context::setCurrentProject($project);
                    }
                    $options['assignee_type'] = $request['assignee_type'];
                    $options['assignee_id'] = $request['assignee_id'];
                    $options['project'] = $project;
                    $options['section'] = $request->getParameter('section', 'info');
                    if ($request->hasParameter('edition_id'))
                    {
                        $edition = tables\Editions::getTable()->selectById($request['edition_id']);
                        $options['edition'] = $edition;
                        $options['selected_section'] = $request->getParameter('section', 'general');
                    }
                    break;
                case 'issue_add_item':
                    $issue = tables\Issues::getTable()->selectById($request['issue_id']);
                    $template_name = 'main/issueadditem';
                    break;
                case 'client_users':
                    $options['client'] = tables\Clients::getTable()->selectById($request['client_id']);
                    $template_name = 'main/clientusers';
                    break;
                case 'dashboard_config':
                    $template_name = 'main/dashboardconfig';
                    $options['tid'] = $request['tid'];
                    $options['target_type'] = $request['target_type'];
                    $options['previous_route'] = $request['previous_route'];
                    $options['mandatory'] = true;
                    break;
                case 'bulk_workflow':
                    $template_name = 'search/bulkworkflow';
                    $options['issue_ids'] = $request['issue_ids'];
                    break;
                case 'confirm_username':
                    $template_name = 'main/confirmusername';
                    $options['username'] = $request['username'];
                    break;
                case 'add_dashboard_view':
                    $template_name = 'main/adddashboardview';
                    break;
                case 'userscopes':
                    if (!framework\Context::getScope()->isDefault())
                        throw new \Exception($this->getI18n()->__('This is not allowed outside the default scope'));

                    $template_name = 'configuration/userscopes';
                    $options['user'] = new entities\User((int) $request['user_id']);
                    break;
                case 'milestone':
                    $template_name = 'project/milestone';
                    $options['project'] = \thebuggenie\core\entities\tables\Projects::getTable()->selectById($request['project_id']);
                    if ($request->hasParameter('milestone_id'))
                        $options['milestone'] = \thebuggenie\core\entities\tables\Milestones::getTable()->selectById($request['milestone_id']);
                    break;
                default:
                    $event = new framework\Event('core', 'get_backdrop_partial', $request['key']);
                    $event->triggerUntilProcessed();
                    $options = $event->getReturnList();
                    $template_name = $event->getReturnValue();
            }
            if ($template_name !== null)
            {
                return $this->renderJSON(array('content' => $this->getComponentHTML($template_name, $options)));
            }
        }
        catch (\Exception $e)
        {
            $this->getResponse()->cleanBuffer();
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An error occured: %error_message', array('%error_message' => $e->getMessage()))));
        }
        $this->getResponse()->cleanBuffer();
        $this->getResponse()->setHttpStatus(400);
        $error = (framework\Context::isDebugMode()) ? framework\Context::getI18n()->__('Invalid template or parameter') : $this->getI18n()->__('Could not show the requested popup');
        return $this->renderJSON(array('error' => $error));
    }

    /**
     * Find issues that might be related to selected issue.
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runFindRelatedIssues(framework\Request $request)
    {
        $issue_id = $request['issue_id'];
        $searchfor = trim($request['searchfor']);

        // Verify request parameters.
        if ($issue_id === null)
        {
            return $this->return400(framework\Context::getI18n()->__('Please provide an issue number'));
        }
        else if ($searchfor === null or $searchfor === "")
        {
            return $this->return400(framework\Context::getI18n()->__('Please provide search text'));
        }
        // Valid project has been referenced, user has permissions
        // to access it (preExecute performs access check already).
        else if ($this->selected_project instanceof entities\Project)
        {
            $issue = tables\Issues::getTable()->selectById($issue_id);

            if ($issue instanceof entities\Issue && $issue->hasAccess($this->getUser()))
            {
                // Try to get both exact match and text-based search.
                $exact_match = entities\Issue::getIssue($searchfor);
                $found_issues = entities\Issue::findIssuesByText($searchfor, null);

                // Exclude selected issue from search results.
                if ($exact_match == $issue)
                {
                    $exact_match = null;
                }

                if (($key = array_search($issue, $found_issues)) !== false)
                {
                    unset($found_issues[$key]);
                }

                // Exclude exact match from general search results.
                if (($key = array_search($exact_match, $found_issues)) !== false)
                {
                    unset($found_issues[$key]);
                }

                // Sort issues by project. Selected project at top.
                usort($found_issues, array($this, 'compareIssuesByProject'));

                // Group issues, exact match first, followed by
                // current project, then other projects.
                $grouped_issues = [];

                foreach ($found_issues as $found_issue)
                {
                    $project_name = $found_issue->getProject()->getName();

                    if (!array_key_exists($project_name, $found_issues))
                    {
                        $grouped_issues[$project_name] = [];
                    }

                    $grouped_issues[$project_name][] = $found_issue;
                }

                if ($exact_match instanceof entities\Issue)
                {
                    $grouped_issues = [$this->getI18n()->__('Exact match') => [$exact_match]] + $grouped_issues;
                }
            }
            else
            {
                return $this->return404(framework\Context::getI18n()->__('Could not find this issue'));
            }
        }
        else
        {
            return $this->return404(framework\Context::getI18n()->__('This project does not exist'));
        }

        // Prepare response and return it.
        $this->getResponse()->setHttpStatus(framework\Response::HTTP_STATUS_OK);

        $parameters = [
            'selected_project' => $this->selected_project,
            'issue' => $issue,
            'grouped_issues' => $grouped_issues
        ];

        return $this->renderJSON(array('content' => $this->getComponentHTML('main/findrelatedissues', $parameters)));
    }

    /**
     * Find issues that might be duplicates of selected issue.
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runFindDuplicatedIssue(framework\Request $request)
    {
        $issue_id = $request['issue_id'];
        $searchfor = trim($request['searchfor']);

        // Verify request parameters.
        if ($issue_id === null)
        {
            return $this->return400(framework\Context::getI18n()->__('Please provide an issue number'));
        }
        else if ($searchfor === null or $searchfor === "")
        {
            return $this->return400(framework\Context::getI18n()->__('Please provide search text'));
        }
        // Valid project has been referenced, user has permissions
        // to access it (preExecute performs access check already).
        else if ($this->selected_project instanceof entities\Project)
        {
            $issue = tables\Issues::getTable()->selectById($issue_id);

            if ($issue instanceof entities\Issue && $issue->hasAccess($this->getUser()))
            {
                // Try to get both exact match and text-based search.
                $exact_match = entities\Issue::getIssue($searchfor);
                $matched_issues = entities\Issue::findIssuesByText($searchfor, $this->selected_project);

                // Exclude selected issue from search results.
                if ($exact_match == $issue)
                {
                    $exact_match = null;
                }

                if (($key = array_search($issue, $matched_issues)) !== false)
                {
                    unset($matched_issues[$key]);
                }

                // Exclude exact match from general search results.
                if (($key = array_search($exact_match, $matched_issues)) !== false)
                {
                    unset($matched_issues[$key]);
                }

                // Add exact match to top of the list.
                if ($exact_match instanceof entities\Issue)
                {
                    array_unshift($matched_issues, $exact_match);
                }
            }
            else
            {
                return $this->return404(framework\Context::getI18n()->__('Could not find this issue'));
            }
        }
        else
        {
            return $this->return404(framework\Context::getI18n()->__('This project does not exist'));
        }

        // Prepare response and return it.
        $this->getResponse()->setHttpStatus(framework\Response::HTTP_STATUS_OK);

        $parameters = [
            'selected_project' => $this->selected_project,
            'issue' => $issue,
            'matched_issues' => $matched_issues
        ];

        return $this->renderJSON(array('content' => $this->getComponentHTML('main/findduplicateissues', $parameters)));
    }

    public function runRemoveRelatedIssue(framework\Request $request)
    {
        try
        {
            try
            {
                $issue_id = (int) $request['issue_id'];
                $related_issue_id = (int) $request['related_issue_id'];
                $issue = null;
                $related_issue = null;
                if ($issue_id && $related_issue_id)
                {
                    $issue = tables\Issues::getTable()->selectById($issue_id);
                    $related_issue = tables\Issues::getTable()->selectById($related_issue_id);
                }
                if (!$issue instanceof entities\Issue || !$related_issue instanceof entities\Issue)
                {
                    throw new \Exception('');
                }
                $issue->removeDependantIssue($related_issue->getID());
            }
            catch (\Exception $e)
            {
                throw new \Exception($this->getI18n()->__('Please provide a valid issue number and a valid related issue number'));
            }
            return $this->renderJSON(array('message' => $this->getI18n()->__('The issues are no longer related')));
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $e->getMessage()));
        }
    }

    public function runRemoveDuplicatedIssue(framework\Request $request)
    {
        try
        {
            try
            {
                $issue_id = (int) $request['issue_id'];
                $duplicated_issue_id = (int) $request['duplicated_issue_id'];
                $issue = null;
                $duplicated_issue = null;
                if ($issue_id && $duplicated_issue_id)
                {
                    $issue = tables\Issues::getTable()->selectById($issue_id);
                    $duplicated_issue = tables\Issues::getTable()->selectById($duplicated_issue_id);
                }
                if (!$issue instanceof entities\Issue || !$duplicated_issue instanceof entities\Issue || !$duplicated_issue->isDuplicate() || $duplicated_issue->getDuplicateOf()->getID() != $issue_id)
                {
                    throw new \Exception('');
                }
                $duplicated_issue->clearDuplicate();
            }
            catch (\Exception $e)
            {
                throw new \Exception($this->getI18n()->__('Please provide a valid issue number and a valid duplicated issue number'));
            }
            return $this->renderJSON(array('message' => $this->getI18n()->__('The issues are no longer duplications')));
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $e->getMessage()));
        }
    }

    public function runRelateIssues(framework\Request $request)
    {
        $status = 200;
        $message = null;

        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                $status = 400;
                $message = framework\Context::getI18n()->__('Could not find this issue');
            }
        }
        else
        {
            $status = 400;
            $message = framework\Context::getI18n()->__('Please provide an issue number');
        }

        if ($issue instanceof entities\Issue && !$issue->canAddRelatedIssues())
        {
            $status = 400;
            $message = framework\Context::getI18n()->__('You are not allowed to relate issues');
        }

        $this->getResponse()->setHttpStatus($status);
        if ($status == 400)
        {
            return $this->renderJSON(array('error' => $message));
        }

        $related_issues = $request->getParameter('relate_issues', array());

        $cc = 0;
        $message = framework\Context::getI18n()->__('Unknown error');
        $content = '';
        if (count($related_issues))
        {
            $mode = $request['relate_action'];
            foreach ($related_issues as $issue_id)
            {
                try
                {
                    $related_issue = tables\Issues::getTable()->selectById((int) $issue_id);
                    if ($mode == 'relate_children')
                    {
                        $issue->addChildIssue($related_issue);
                    }
                    else
                    {
                        $issue->addParentIssue($related_issue);
                    }
                    $cc++;
                    $content .= $this->getComponentHTML('main/relatedissue', array('issue' => $related_issue, 'related_issue' => $issue));
                }
                catch (\Exception $e)
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An error occured when relating issues: %error', array('%error' => $e->getMessage()))));
                }
            }
        }
        else
        {
            $message = framework\Context::getI18n()->__('Please select at least one issue');
        }

        if ($cc > 0)
        {
            return $this->renderJSON(array('content' => $content, 'message' => framework\Context::getI18n()->__('The related issue was added'), 'count' => count($issue->getChildIssues())));
        }
        else
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An error occured when relating issues: %error', array('%error' => $message))));
        }
    }

    public function runRelatedIssues(framework\Request $request)
    {
        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $this->issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {

            }
        }
    }

    public function runVoteForIssue(framework\Request $request)
    {
        $i18n = framework\Context::getI18n();
        $issue = tables\Issues::getTable()->selectById($request['issue_id']);
        $vote_direction = $request['vote'];
        if ($issue instanceof entities\Issue && !$issue->hasUserVoted($this->getUser()->getID(), ($vote_direction == 'up')))
        {
            $issue->vote(($vote_direction == 'up'));
            return $this->renderJSON(array('content' => $issue->getVotes(), 'message' => $i18n->__('Vote added')));
        }
    }

    public function runToggleFriend(framework\Request $request)
    {
        try
        {
            $friend_user = tables\Users::getTable()->selectById($request['user_id']);
            $mode = $request['mode'];
            if ($mode == 'add')
            {
                if ($friend_user instanceof entities\User && $friend_user->isDeleted())
                {
                    $this->getResponse()->setHttpStatus(400);
                    return $this->renderJSON(array('error' => framework\Context::getI18n()->__('This user has been deleted')));
                }
                $this->getUser()->addFriend($friend_user);
            }
            else
            {
                $this->getUser()->removeFriend($friend_user);
            }
            return $this->renderJSON(array('mode' => $mode));
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Could not add or remove friend')));
        }
    }

    public function runSetState(framework\Request $request)
    {
        try
        {
            $state = tables\Userstates::getTable()->selectById($request['state_id']);
            $this->getUser()->setState($state);
            $this->getUser()->save();
            return $this->renderJSON(array('userstate' => $this->getI18n()->__($state->getName())));
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $this->getI18n()->__('An error occured while trying to update your status')));
        }
    }

    public function runToggleAffectedConfirmed(framework\Request $request)
    {
        try
        {
            $issue = tables\Issues::getTable()->selectById($request['issue_id']);
            $itemtype = $request['affected_type'];

            if (!(($itemtype == 'build' && $issue->canEditAffectedBuilds()) || ($itemtype == 'component' && $issue->canEditAffectedComponents()) || ($itemtype == 'edition' && $issue->canEditAffectedEditions())))
            {
                throw new \Exception($this->getI18n()->__('You are not allowed to do this'));
            }

            $affected_id = $request['affected_id'];
            $confirmed = true;

            switch ($itemtype)
            {
                case 'edition':
                    if (!$issue->getProject()->isEditionsEnabled())
                    {
                        throw new \Exception($this->getI18n()->__('Editions are disabled'));
                    }

                    $editions = $issue->getEditions();
                    if (!array_key_exists($affected_id, $editions))
                    {
                        throw new \Exception($this->getI18n()->__('This edition is not affected by this issue'));
                    }
                    $edition = $editions[$affected_id];

                    if ($edition['confirmed'] == true)
                    {
                        $issue->confirmAffectedEdition($edition['edition'], false);
                        $confirmed = false;
                    }
                    else
                    {
                        $issue->confirmAffectedEdition($edition['edition']);
                        $confirmed = true;
                    }

                    break;
                case 'component':
                    if (!$issue->getProject()->isComponentsEnabled())
                    {
                        throw new \Exception($this->getI18n()->__('Components are disabled'));
                    }

                    $components = $issue->getComponents();
                    if (!array_key_exists($affected_id, $components))
                    {
                        throw new \Exception($this->getI18n()->__('This component is not affected by this issue'));
                    }
                    $component = $components[$affected_id];

                    if ($component['confirmed'] == true)
                    {
                        $issue->confirmAffectedComponent($component['component'], false);
                        $confirmed = false;
                    }
                    else
                    {
                        $issue->confirmAffectedComponent($component['component']);
                        $confirmed = true;
                    }

                    break;
                case 'build':
                    if (!$issue->getProject()->isBuildsEnabled())
                    {
                        throw new \Exception($this->getI18n()->__('Releases are disabled'));
                    }

                    $builds = $issue->getBuilds();
                    if (!array_key_exists($affected_id, $builds))
                    {
                        throw new \Exception($this->getI18n()->__('This release is not affected by this issue'));
                    }
                    $build = $builds[$affected_id];

                    if ($build['confirmed'] == true)
                    {
                        $issue->confirmAffectedBuild($build['build'], false);
                        $confirmed = false;
                    }
                    else
                    {
                        $issue->confirmAffectedBuild($build['build']);
                        $confirmed = true;
                    }

                    break;
                default:
                    throw new \Exception('Internal error');
            }

            return $this->renderJSON(array('confirmed' => $confirmed, 'text' => ($confirmed) ? $this->getI18n()->__('Confirmed') : $this->getI18n()->__('Unconfirmed')));
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $e->getMessage()));
        }
    }

    public function runRemoveAffected(framework\Request $request)
    {
        framework\Context::loadLibrary('ui');
        try
        {
            $issue = tables\Issues::getTable()->selectById($request['issue_id']);

            if (!$issue->canEditIssue())
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You are not allowed to do this')));
            }

            switch ($request['affected_type'])
            {
                case 'edition':
                    if (!$issue->getProject()->isEditionsEnabled())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Editions are disabled')));
                    }

                    $editions = $issue->getEditions();
                    $edition = $editions[$request['affected_id']];

                    $issue->removeAffectedEdition($edition['edition']);

                    $message = framework\Context::getI18n()->__('Edition <b>%edition</b> is no longer affected by this issue', array('%edition' => $edition['edition']->getName()), true);

                    break;
                case 'component':
                    if (!$issue->getProject()->isComponentsEnabled())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Components are disabled')));
                    }

                    $components = $issue->getComponents();
                    $component = $components[$request['affected_id']];

                    $issue->removeAffectedComponent($component['component']);

                    $message = framework\Context::getI18n()->__('Component <b>%component</b> is no longer affected by this issue', array('%component' => $component['component']->getName()), true);

                    break;
                case 'build':
                    if (!$issue->getProject()->isBuildsEnabled())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Releases are disabled')));
                    }

                    $builds = $issue->getBuilds();
                    if (isset($builds[$request['affected_id']]))
                    {
                        $build = $builds[$request['affected_id']];

                        $issue->removeAffectedBuild($build['build']);
                        $message = framework\Context::getI18n()->__('Release <b>%build</b> is no longer affected by this issue', array('%build' => $build['build']->getName()), true);
                    }
                    else
                    {
                        $message = framework\Context::getI18n()->__('The release is no longer affected by this issue');
                    }

                    break;
                default:
                    throw new \Exception('Internal error');
            }

            $editions = array();
            $components = array();
            $builds = array();

            if ($issue->getProject()->isEditionsEnabled())
            {
                $editions = $issue->getEditions();
            }

            if ($issue->getProject()->isComponentsEnabled())
            {
                $components = $issue->getComponents();
            }

            if ($issue->getProject()->isBuildsEnabled())
            {
                $builds = $issue->getBuilds();
            }

            $count = count($editions) + count($components) + count($builds) - 1;

            return $this->renderJSON(array('message' => $message, 'itemcount' => $count));
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An internal error has occured')));
        }
    }

    public function runStatusAffected(framework\Request $request)
    {
        framework\Context::loadLibrary('ui');
        try
        {
            $issue = tables\Issues::getTable()->selectById($request['issue_id']);
            $status = tables\ListTypes::getTable()->selectById($request['status_id']);
            if (!$issue->canEditIssue())
            {
                $this->getResponse()->setHttpStatus(400);
                return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You are not allowed to do this')));
            }

            switch ($request['affected_type'])
            {
                case 'edition':
                    if (!$issue->getProject()->isEditionsEnabled())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Editions are disabled')));
                    }
                    $editions = $issue->getEditions();
                    $edition = $editions[$request['affected_id']];

                    $issue->setAffectedEditionStatus($edition['edition'], $status);
                    break;
                case 'component':
                    if (!$issue->getProject()->isComponentsEnabled())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Components are disabled')));
                    }
                    $components = $issue->getComponents();
                    $component = $components[$request['affected_id']];

                    $issue->setAffectedcomponentStatus($component['component'], $status);
                    break;
                case 'build':
                    if (!$issue->getProject()->isBuildsEnabled())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Releases are disabled')));
                    }
                    $builds = $issue->getBuilds();
                    $build = $builds[$request['affected_id']];

                    $issue->setAffectedbuildStatus($build['build'], $status);
                    break;
                default:
                    throw new \Exception('Internal error');
            }

            return $this->renderJSON(array('colour' => $status->getColor(), 'name' => $status->getName()));
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => framework\Context::getI18n()->__('An internal error has occured')));
        }
    }

    public function runAddAffected(framework\Request $request)
    {
        framework\Context::loadLibrary('ui');
        try
        {
            $issue = tables\Issues::getTable()->selectById($request['issue_id']);
            $statuses = entities\Status::getAll();

            switch ($request['item_type'])
            {
                case 'edition':
                    if (!$issue->getProject()->isEditionsEnabled())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Editions are disabled')));
                    }
                    elseif (!$issue->canEditAffectedEditions())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You are not allowed to do this')));
                    }

                    $edition = tables\Editions::getTable()->selectById($request['which_item_edition']);

                    if (tables\IssueAffectsEdition::getTable()->getByIssueIDandEditionID($issue->getID(), $edition->getID()))
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('%item is already affected by this issue', array('%item' => $edition->getName()))));
                    }

                    $result = $issue->addAffectedEdition($edition);

                    if ($result !== false)
                    {
                        $itemtype = 'edition';
                        $item = $result;
                        $itemtypename = framework\Context::getI18n()->__('Edition');
                        $content = get_component_html('main/affecteditem', array('item' => $item, 'itemtype' => $itemtype, 'itemtypename' => $itemtypename, 'issue' => $issue, 'statuses' => $statuses));
                    }

                    $message = framework\Context::getI18n()->__('Edition <b>%edition</b> is now affected by this issue', array('%edition' => $edition->getName()), true);

                    break;
                case 'component':
                    if (!$issue->getProject()->isComponentsEnabled())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Components are disabled')));
                    }
                    elseif (!$issue->canEditAffectedComponents())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You are not allowed to do this')));
                    }

                    $component = tables\Components::getTable()->selectById($request['which_item_component']);

                    if (tables\IssueAffectsComponent::getTable()->getByIssueIDandComponentID($issue->getID(), $component->getID()))
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('%item is already affected by this issue', array('%item' => $component->getName()))));
                    }

                    $result = $issue->addAffectedComponent($component);

                    if ($result !== false)
                    {
                        $itemtype = 'component';
                        $item = $result;
                        $itemtypename = framework\Context::getI18n()->__('Component');
                        $content = get_component_html('main/affecteditem', array('item' => $item, 'itemtype' => $itemtype, 'itemtypename' => $itemtypename, 'issue' => $issue, 'statuses' => $statuses));
                    }

                    $message = framework\Context::getI18n()->__('Component <b>%component</b> is now affected by this issue', array('%component' => $component->getName()), true);

                    break;
                case 'build':
                    if (!$issue->getProject()->isBuildsEnabled())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('Releases are disabled')));
                    }
                    elseif (!$issue->canEditAffectedBuilds())
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('You are not allowed to do this')));
                    }

                    $build = tables\Builds::getTable()->selectById($request['which_item_build']);

                    if (tables\IssueAffectsBuild::getTable()->getByIssueIDandBuildID($issue->getID(), $build->getID()))
                    {
                        $this->getResponse()->setHttpStatus(400);
                        return $this->renderJSON(array('error' => framework\Context::getI18n()->__('%item is already affected by this issue', array('%item' => $build->getName()))));
                    }

                    $result = $issue->addAffectedBuild($build);

                    if ($result !== false)
                    {
                        $itemtype = 'build';
                        $item = $result;
                        $itemtypename = framework\Context::getI18n()->__('Release');
                        $content = get_component_html('main/affecteditem', array('item' => $item, 'itemtype' => $itemtype, 'itemtypename' => $itemtypename, 'issue' => $issue, 'statuses' => $statuses));
                    }

                    $message = framework\Context::getI18n()->__('Release <b>%build</b> is now affected by this issue', array('%build' => $build->getName()), true);

                    break;
                default:
                    throw new \Exception('Internal error');
            }

            $editions = array();
            $components = array();
            $builds = array();

            if ($issue->getProject()->isEditionsEnabled())
            {
                $editions = $issue->getEditions();
            }

            if ($issue->getProject()->isComponentsEnabled())
            {
                $components = $issue->getComponents();
            }

            if ($issue->getProject()->isBuildsEnabled())
            {
                $builds = $issue->getBuilds();
            }

            $count = count($editions) + count($components) + count($builds);

            return $this->renderJSON(array('content' => $content, 'message' => $message, 'itemcount' => $count));
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $e->getMessage()));
        }
    }

    /**
     * Reset user password
     *
     * @Route(name="reset_password", url="/reset/password/:user/:reset_hash")
     * @AnonymousRoute
     *
     * @param \thebuggenie\core\framework\Request $request The request object
     */
    public function runResetPassword(framework\Request $request)
    {
        $i18n = framework\Context::getI18n();

        try
        {
            if ($request->hasParameter('user') && $request->hasParameter('reset_hash'))
            {
                $user = entities\User::getByUsername(str_replace('%2E', '.', $request['user']));
                if ($user instanceof entities\User)
                {
                    if ($request['reset_hash'] == $user->getActivationKey())
                    {
                        $this->error = false;
                        if ($request->isPost())
                        {
                            $p1 = trim($request['password_1']);
                            $p2 = trim($request['password_2']);

                            if ($p1 && $p2 && $p1 == $p2)
                            {
                                $user->setPassword($p1);
                                $user->regenerateActivationKey();
                                $user->save();
                                framework\Context::setMessage('login_message', $i18n->__('Your password has been reset. Please log in.'));
                                framework\Context::setMessage('login_referer', $this->getRouting()->generate('home'));
                                return $this->forward($this->getRouting()->generate('login_page'));
                            }
                            else
                            {
                                $this->error = true;
                            }
                        }
                        else
                        {
                            $user->regenerateActivationKey();
                        }
                        $this->user = $user;
                    }
                    else
                    {
                        throw new \Exception('Your password recovery token is either invalid or has expired');
                    }
                }
                else
                {
                    throw new \Exception('User is invalid or does not exist');
                }
            }
            else
            {
                throw new \Exception('An internal error has occured');
            }
        }
        catch (\Exception $e)
        {
            framework\Context::setMessage('login_message_err', $i18n->__($e->getMessage()));
            return $this->forward($this->getRouting()->generate('login_page'));
        }
    }

    /**
     * Generate captcha picture
     *
     * @Route(name="captcha", url="/captcha/*")
     * @AnonymousRoute
     *
     * @param \thebuggenie\core\framework\Request $request The request object
     * @global array $_SESSION['activation_number'] The session captcha activation number
     */
    public function runCaptcha(framework\Request $request)
    {
        framework\Context::loadLibrary('ui');

        if (!function_exists('imagecreatetruecolor'))
        {
            return $this->return404();
        }

        $this->getResponse()->setContentType('image/png');
        $this->getResponse()->setDecoration(\thebuggenie\core\framework\Response::DECORATE_NONE);
        $chain = str_split($_SESSION['activation_number'], 1);
        $size = getimagesize(THEBUGGENIE_PATH . DS . 'themes' . DS . framework\Settings::getThemeName() . DS . 'images' . DS . 'numbers' . DS . '0.png');
        $captcha = imagecreatetruecolor($size[0] * sizeof($chain), $size[1]);
        foreach ($chain as $n => $number)
        {
            $pic = imagecreatefrompng(THEBUGGENIE_PATH . DS . 'themes' . DS . framework\Settings::getThemeName() . DS . 'images' . DS . 'numbers' . DS . "{$number}.png");
            imagecopymerge($captcha, $pic, $size[0] * $n, 0, 0, 0, imagesx($pic), imagesy($pic), 100);
            imagedestroy($pic);
        }
        imagepng($captcha);
        imagedestroy($captcha);

        return true;
    }

    public function runIssueGetTempFieldValue(framework\Request $request)
    {
        switch ($request['field'])
        {
            case 'assigned_to':
                if ($request['identifiable_type'] == 'user')
                {
                    $identifiable = tables\Users::getTable()->selectById($request['value']);
                    $content = $this->getComponentHTML('main/userdropdown', array('user' => $identifiable));
                }
                elseif ($request['identifiable_type'] == 'team')
                {
                    $identifiable = tables\Teams::getTable()->selectById($request['value']);
                    $content = $this->getComponentHTML('main/teamdropdown', array('team' => $identifiable));
                }
                else
                {
                    $content = '';
                }

                return $this->renderJSON(array('content' => $content));
        }
    }

    public function runAccountCheckUsername(framework\Request $request)
    {
        if ($request['desired_username'] && entities\User::isUsernameAvailable($request['desired_username']))
        {
            return $this->renderJSON(array('available' => true, 'url' => $this->getRouting()->generate('get_partial_for_backdrop', array('key' => 'confirm_username', 'username' => $request['desired_username']))));
        }
        else
        {
            return $this->renderJSON(array('available' => false));
        }
    }

    public function runAccountPickUsername(framework\Request $request)
    {
        if (entities\User::isUsernameAvailable($request['selected_username']))
        {
            $authentication_backend = framework\Settings::getAuthenticationBackend();

            $user = $this->getUser();
            $user->setUsername($request['selected_username']);
            $user->setOpenIdLocked(false);
            $user->setPassword(entities\User::createPassword());
            $user->save();

            if ($authentication_backend->getAuthenticationMethod() == framework\AuthenticationBackend::AUTHENTICATION_TYPE_TOKEN)
            {
                $this->getResponse()->setCookie('username', $user->getUsername());
                $this->getResponse()->setCookie('session_token', $user->createUserSession()->getToken());
            }
            else
            {
                $this->getResponse()->setCookie('username', $user->getUsername());
                $this->getResponse()->setCookie('password', $user->getHashPassword());
            }

            framework\Context::setMessage('username_chosen', true);
            $this->forward($this->getRouting()->generate('account'));
        }

        framework\Context::setMessage('error', $this->getI18n()->__('Could not pick the username "%username"', array('%username' => $request['selected_username'])));
        $this->forward($this->getRouting()->generate('account'));
    }

    public function runDashboardView(framework\Request $request)
    {
        $view = tables\DashboardViews::getTable()->selectById($request['view_id']);
        if (!$view instanceof entities\DashboardView)
        {
            return $this->renderJSON(array('content' => 'invalid view'));
        }

        if ($view->getTargetType() == entities\DashboardView::TYPE_PROJECT)
        {
            framework\Context::setCurrentProject($view->getDashboard()->getProject());
        }

        return $this->renderJSON(array('content' => $this->returnComponentHTML($view->getTemplate(), array('view' => $view))));
    }

    public function runGetTempIdentifiable(framework\Request $request)
    {
        if ($request['i_type'] == 'user')
            return $this->renderComponent('main/userdropdown', array('user' => $request['i_id']));
        else
            return $this->renderComponent('main/teamdropdown', array('team' => $request['i_id']));
    }

    public function runGetACLFormEntry(framework\Request $request)
    {
        switch ($request['identifiable_type'])
        {
            case 'user':
                $target = tables\Users::getTable()->selectById((int) $request['identifiable_value']);
                break;
            case 'team':
                $target = tables\Teams::getTable()->selectById((int) $request['identifiable_value']);
                break;
        }

        if (!$target instanceof entities\common\Identifiable)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $this->getI18n()->__('Could not show permissions list')));
        }

        return $this->renderJSON(array('content' => $this->getComponentHTML('main/issueaclformentry', array('target' => $target))));
    }

    public function runRemoveScope(framework\Request $request)
    {
        $this->getUser()->removeScope((int) $request['scope_id']);
        return $this->renderJSON('ok');
    }

    public function runConfirmScope(framework\Request $request)
    {
        $this->getUser()->confirmScope((int) $request['scope_id']);
        return $this->renderJSON('ok');
    }

    public function runAddScope(framework\Request $request)
    {
        if ($request->isPost())
        {
            $scope = framework\Context::getScope();
            $this->getUser()->addScope($scope, false);
            $this->getUser()->confirmScope($scope->getID());
            $route = (framework\Settings::getLoginReturnRoute() != 'referer') ? framework\Settings::getLoginReturnRoute() : 'home';
            $this->forward($this->getRouting()->generate($route));
        }
    }

    public function runIssueLog(framework\Request $request)
    {
        try
        {
            $this->issue = tables\Issues::getTable()->getIssueById((int) $request['issue_id']);
            $this->log_items = $this->issue->getLogEntries();
            if ($this->issue->isDeleted() || !$this->issue->hasAccess())
                $this->issue = null;
        }
        catch (\Exception $e)
        {
            throw $e;
        }
    }

    public function runIssueMoreactions(framework\Request $request)
    {
        try
        {
            $issue = tables\Issues::getTable()->getIssueById((int) $request['issue_id']);
            if ($request['board_id']) $board = agile\entities\tables\AgileBoards::getTable()->selectById((int) $request['board_id']);

            $times = (!isset($board) || $board->getType() != agile\entities\AgileBoard::TYPE_KANBAN);
            $estimator_mode = isset($request['estimator_mode']) ? $request['estimator_mode'] : null;

            return $this->renderJSON(array('menu' => $this->getComponentHTML('main/issuemoreactions', compact('issue', 'times', 'board', 'estimator_mode'))));
        }
        catch (\Exception $e)
        {
            throw $e;
        }
    }

    protected function _saveMilestoneDetails(framework\Request $request, $milestone = null)
    {
        if (!$request['name'])
            throw new \Exception($this->getI18n()->__('You must provide a valid milestone name'));

        if ($milestone === null) $milestone = new entities\Milestone();
        $milestone->setName($request['name']);
        $milestone->setProject($this->selected_project);
        $milestone->setStarting((bool) $request['is_starting']);
        $milestone->setScheduled((bool) $request['is_scheduled']);
        $milestone->setDescription($request['description']);
        $milestone->setVisibleRoadmap($request['visibility_roadmap']);
        $milestone->setVisibleIssues($request['visibility_issues']);
        $milestone->setType($request->getParameter('milestone_type', entities\Milestone::TYPE_REGULAR));
        $milestone->setPercentageType($request->getParameter('percentage_type', entities\Milestone::PERCENTAGE_TYPE_REGULAR));
        if ($request->hasParameter('sch_month') && $request->hasParameter('sch_day') && $request->hasParameter('sch_year'))
        {
            $scheduled_date = mktime(23, 59, 59, framework\Context::getRequest()->getParameter('sch_month'), framework\Context::getRequest()->getParameter('sch_day'), framework\Context::getRequest()->getParameter('sch_year'));
            $milestone->setScheduledDate($scheduled_date);
        }
        else
            $milestone->setScheduledDate(0);

        if ($request->hasParameter('starting_month') && $request->hasParameter('starting_day') && $request->hasParameter('starting_year'))
        {
            $starting_date = mktime(0, 0, 1, framework\Context::getRequest()->getParameter('starting_month'), framework\Context::getRequest()->getParameter('starting_day'), framework\Context::getRequest()->getParameter('starting_year'));
            $milestone->setStartingDate($starting_date);
        }
        else
            $milestone->setStartingDate(0);

        $milestone->save();
    }

    /**
     * Milestone actions
     *
     * @Route(url="/:project_key/milestone/:milestone_id/actions/*", name='project_milestone')
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runMilestone(framework\Request $request)
    {
        $milestone_id = ($request['milestone_id']) ? $request['milestone_id'] : null;
        $milestone = new entities\Milestone($milestone_id);
        $action_option = str_replace($this->selected_project->getKey().'/milestone/'.$request['milestone_id'].'/', '', $request['url']);

        try
        {
            if (!($this->getUser()->canEditMilestones($this->selected_project) || ($this->getUser()->canManageProjectReleases($this->selected_project) && $this->getUser()->canManageProject($this->selected_project))))
                throw new \Exception($this->getI18n()->__("You don't have access to modify milestones"));

            switch (true)
            {
                case $request->isDelete():
                    $milestone->delete();

                    $no_milestone = new entities\Milestone(0);
                    $no_milestone->setProject($milestone->getProject());
                    return $this->renderJSON(array('issue_count' => $no_milestone->countIssues(), 'hours' => $no_milestone->getHoursEstimated(), 'points' => $no_milestone->getPointsEstimated()));
                case $request->isPost():
                    $this->_saveMilestoneDetails($request, $milestone);

                    if ($request->hasParameter('issues') && $request['include_selected_issues'])
                        \thebuggenie\core\entities\tables\Issues::getTable()->assignMilestoneIDbyIssueIDs($milestone->getID(), $request['issues']);

                    $event = framework\Event::createNew('project', 'runMilestone::post', $milestone);
                    $event->triggerUntilProcessed();

                    if ($event->isProcessed()) {
                        $component = $event->getReturnValue();
                    } else {
                        $component = $this->getComponentHTML('project/milestonebox', array('milestone' => $milestone, 'include_counts' => true));
                    }
                    $message = framework\Context::getI18n()->__('Milestone saved');
                    return $this->renderJSON(array('message' => $message, 'component' => $component, 'milestone_id' => $milestone->getID()));
                case $action_option == 'details':
                    \thebuggenie\core\framework\Context::performAction(
                        new \thebuggenie\core\modules\project\controllers\Main(),
                        'project',
                        'MilestoneDetails'
                    );
                    return true;
                default:
                    return $this->forward($this->getRouting()->generate('project_roadmap', array('project_key' => $this->selected_project->getKey())));
            }
        }
        catch (\Exception $e)
        {
            $this->getResponse()->setHttpStatus(400);
            return $this->renderJSON(array('error' => $e->getMessage()));
        }
    }

    /**
     * @param framework\Request $request
     * @param                   $issue
     */
    protected function _lockIssueAfter(framework\Request $request, $issue) {
        framework\Context::setPermission('canviewissue', $issue->getID(), 'core', 0, 0, 0, false);
        framework\Context::setPermission('canviewissue', $issue->getID(), 'core', $this->getUser()
          ->getID(), 0, 0, true);

        $al_users = $request->getParameter('access_list_users', array());
        $al_teams = $request->getParameter('access_list_teams', array());
        $i_al     = $issue->getAccessList();
        foreach ($i_al as $k => $item)
        {
            if ($item['target'] instanceof entities\Team)
            {
                $tid = $item['target']->getID();
                if (array_key_exists($tid, $al_teams))
                {
                    unset($i_al[$k]);
                }
                else
                {
                    framework\Context::removePermission('canviewissue', $issue->getID(), 'core', 0, 0, $tid);
                }
            }
            elseif ($item['target'] instanceof entities\User)
            {
                $uid = $item['target']->getID();
                if (array_key_exists($uid, $al_users))
                {
                    unset($i_al[$k]);
                }
                elseif ($uid != $this->getUser()
                    ->getID()
                )
                {
                    framework\Context::removePermission('canviewissue', $issue->getID(), 'core', $uid, 0, 0);
                }
            }
        }
        foreach ($al_users as $uid)
        {
            framework\Context::setPermission('canviewissue', $issue->getID(), 'core', $uid, 0, 0, true);
        }
        foreach ($al_teams as $tid)
        {
            framework\Context::setPermission('canviewissue', $issue->getID(), 'core', 0, 0, $tid, true);
        }
    }

    /**
     * @param framework\Request $request
     * @param                   $issue
     */
    protected function _unlockIssueAfter(framework\Request $request, $issue)
    {
        tables\Permissions::getTable()
          ->deleteByPermissionTargetIDAndModule('canviewissue', $issue->getID());

        $al_users = $request->getParameter('access_list_users', array());
        $al_teams = $request->getParameter('access_list_teams', array());
        $i_al     = $issue->getAccessList();
        foreach ($i_al as $k => $item)
        {
            if ($item['target'] instanceof entities\Team)
            {
                $tid = $item['target']->getID();
                if (array_key_exists($tid, $al_teams))
                {
                    unset($i_al[$k]);
                }
            }
            elseif ($item['target'] instanceof entities\User)
            {
                $uid = $item['target']->getID();
                if (array_key_exists($uid, $al_users))
                {
                    unset($i_al[$k]);
                }
            }
        }
        foreach ($al_users as $uid)
        {
            framework\Context::setPermission('canviewissue', $issue->getID(), 'core', $uid, 0, 0, true);
        }
        foreach ($al_teams as $tid)
        {
            framework\Context::setPermission('canviewissue', $issue->getID(), 'core', 0, 0, $tid, true);
        }
    }

    /**
     * @param framework\Request $request
     * @return mixed|string
     * @throws \Exception
     */
    protected function _getLoginForwardUrl(framework\Request $request)
    {
        $forward_url = $this->getRouting()->generate('home');

        if ($request->hasParameter('return_to'))
        {
            $forward_url = $request['return_to'];
        }
        else
        {
            if (framework\Settings::get('returnfromlogin') == 'referer')
            {
                $forward_url = $request->getParameter('referer', $this->getRouting()->generate('dashboard'));
            }
            else
            {
                $forward_url = $this->getRouting()->generate(framework\Settings::get('returnfromlogin'));
            }
        }

        $forward_url = htmlentities($forward_url, ENT_COMPAT, framework\Context::getI18n()->getCharset());

        return $forward_url;
    }

    /**
     * @param $authentication_backend
     * @param $user
     * @param $persist
     */
    protected function _persistLogin($authentication_backend, $user, $persist)
    {
        if ($authentication_backend->getAuthenticationMethod() == framework\AuthenticationBackend::AUTHENTICATION_TYPE_TOKEN) {
            $token = $user->createUserSession();
            $authentication_backend->persistTokenSession($user, $token, $persist);
        } else {
            $password = $user->getHashPassword();
            $authentication_backend->persistPasswordSession($user, $password, $persist);
        }
    }

    /**
     * Delete an issue todos item.
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runDeleteTodo(framework\Request $request)
    {
        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('This issue does not exist')));
            }
        }
        else
        {
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('This issue does not exist')));
        }

        if (! isset($request['comment_id']) || ! is_numeric($request['comment_id']))
        {
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('Invalid "comment_id" parameter')));
        }

        $this->forward403unless(($request['comment_id'] == 0 && $issue->canEditDescription()) || ($request['comment_id'] != 0 && $issue->getComments()[$request['comment_id']]->canUserEditComment()));

        if (! isset($request['todo']) || $request['todo'] == '')
        {
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('Invalid "todo" parameter')));
        }

        framework\Context::loadLibrary('common');
        $issue->deleteTodo($request['comment_id'], $request['todo']);

        return $this->renderJSON(array(
            'content' => $this->getComponentHTML('todos', compact('issue'))
        ));
    }

    /**
     * Toggle done for issue todos item.
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runToggleDoneTodo(framework\Request $request)
    {
        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('This issue does not exist')));
            }
        }
        else
        {
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('This issue does not exist')));
        }

        if (! isset($request['comment_id']) || ! is_numeric($request['comment_id']))
        {
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('Invalid "comment_id" parameter')));
        }

        if (! isset($request['todo']) || $request['todo'] == '')
        {
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('Invalid "todo" parameter')));
        }

        if (! isset($request['mark']) || ! in_array($request['mark'], array('done', 'not_done')))
        {
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('Invalid "mark" parameter')));
        }

        framework\Context::loadLibrary('common');
        $issue->markTodo($request['comment_id'], $request['todo'], $request['mark']);

        return $this->renderJSON(array(
            'content' => $this->getComponentHTML('todos', compact('issue'))
        ));
    }

    /**
     * Add an issue todos item.
     *
     * @param \thebuggenie\core\framework\Request $request
     */
    public function runAddTodo(framework\Request $request)
    {
        // If todos item is submitted via form and not ajax forward 403 error.
        $this->forward403unless($request->isAjaxCall());

        if ($issue_id = $request['issue_id'])
        {
            try
            {
                $issue = tables\Issues::getTable()->selectById($issue_id);
            }
            catch (\Exception $e)
            {
                return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('This issue does not exist')));
            }
        }
        else
        {
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('This issue does not exist')));
        }

        if (!$issue->canEditDescription())
        {
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('You do not have permission to perform this action')));
        }

        if (! isset($request['todo_body']) || !trim($request['todo_body']))
        {
            return $this->renderJSON(array('failed' => true, 'error' => framework\Context::getI18n()->__('Invalid "todo_body" parameter')));
        }

        framework\Context::loadLibrary('common');
        $issue->addTodo($request['todo_body']);

        return $this->renderJSON(array(
            'content' => $this->getComponentHTML('todos', compact('issue'))
        ));
    }
}
