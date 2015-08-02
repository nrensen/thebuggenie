<?php

    \thebuggenie\core\framework\Context::loadLibrary('publish/publish');
    include_component('publish/wikibreadcrumbs', array('article_name' => $article_name));
    $tbg_response->setTitle(__('%article_name permissions', array('%article_name' => $article_name)));

?>
<table style="margin-top: 0px; table-layout: fixed; width: 100%" cellpadding=0 cellspacing=0>
    <tr>
        <td class="side_bar">
            <?php include_component('leftmenu', array('article' => $article)); ?>
        </td>
        <td class="main_area article">
            <a name="top"></a>
            <div class="article" style="width: auto; padding: 5px; position: relative;">
                <?php include_component('publish/header', array('article' => $article, 'article_name' => $article_name, 'show_actions' => true, 'mode' => 'permissions')); ?>
                <?php if ($article instanceof \thebuggenie\modules\publish\entities\Article): ?>
                    <?php if (\thebuggenie\core\framework\Context::getModule('publish')->canUserEditArticle($article_name)): ?>
                        <ul class="simple_list">
                        <?php for ($i = 0; $i < count($namespaces); $i++):
                            $namespace = $namespaces[$i];
                            $cssclass = 'invisible borderless';
                            if (is_numeric($namespace) && $namespace == 0)
                            {
                                $title = __('Permissions for entire wiki');
                                $help = __('These are the default permissions for all wiki articles. They will apply unless overridden by article-specific or namespace permissions.');
                            }
                            elseif ($namespace == $article->getName())
                            {
                                $title = __('Permissions for the article %article_name', array('%article_name' => '<span class="namespace">'.get_spaced_name($namespace).'</span>'));
                                $help = __('These permissions override any other permissions for this article. They will also apply to child-articles unless overridden by child-namespace or child-article permissions.');
                                $cssclass = 'verylightyellow';
                            }
                            elseif ($namespace == "Category")
                            {
                                $title = __('Permissions to edit categories');
                                $help = __('These permissions determine who can create and edit categories.');
                            }
                            else
                            {
                                $title = __('Permissions for the %namespace namespace', array('%namespace' => '<span class="namespace">'.get_spaced_name($namespace).'</span>'));
                                $help = __('These permissions apply to the the %namespace namespace. They will also apply to all child-articles of this namespace unless overridden by article-specific or child-namespace permissions.', array('%namespace' => '<i>'.get_spaced_name($namespace).'</i>'));
                            }
                        ?>
                            <li class="rounded_box <?php echo $cssclass; ?>" style="padding: 10px;">
                                <div class="namespace_header">
                                    <?php echo $title; ?>
                                </div>
                                <?php echo $help; ?>
                                <div style="text-align: right; padding: 10px;">
                                    <button onclick="$('publish_<?php echo $i; ?>_readarticle_permissions').toggle();"><?php echo __('Edit read permissions'); ?></button>
                                    <button onclick="$('publish_<?php echo $i; ?>_editarticle_permissions').toggle();"><?php echo __('Edit write permissions'); ?></button>
                                    <button onclick="$('publish_<?php echo $i; ?>_deletearticle_permissions').toggle();"><?php echo __('Edit delete permissions'); ?></button>
                                </div>
                                <div id="publish_<?php echo $i; ?>_readarticle_permissions" style="padding: 10px; width: 700px; display: none;">
                                    <?php include_component('configuration/permissionsinfo', array('key' => \thebuggenie\modules\publish\Publish::PERMISSION_READ_ARTICLE, 'mode' => 'module_permissions', 'target_id' => $namespace, 'module' => 'publish', 'access_level' => \thebuggenie\core\framework\Settings::ACCESS_FULL)); ?>
                                </div>
                                <div id="publish_<?php echo $i; ?>_editarticle_permissions" style="padding: 10px; width: 700px; display: none;">
                                    <?php include_component('configuration/permissionsinfo', array('key' => \thebuggenie\modules\publish\Publish::PERMISSION_EDIT_ARTICLE, 'mode' => 'module_permissions', 'target_id' => $namespace, 'module' => 'publish', 'access_level' => \thebuggenie\core\framework\Settings::ACCESS_FULL)); ?>
                                </div>
                                <div id="publish_<?php echo $i; ?>_deletearticle_permissions" style="padding: 10px; width: 700px; display: none;">
                                    <?php include_component('configuration/permissionsinfo', array('key' => \thebuggenie\modules\publish\Publish::PERMISSION_DELETE_ARTICLE, 'mode' => 'module_permissions', 'target_id' => $namespace, 'module' => 'publish', 'access_level' => \thebuggenie\core\framework\Settings::ACCESS_FULL)); ?>
                                </div>
                            </li>
                        <?php endfor; ?>
                        </ul>
                    <?php else: ?>
                        <div class="redbox" style="margin: 0 5px 5px 5px; font-size: 14px;">
                            <?php echo __('You do not have access to edit permissions for this article'); ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php include_component('publish/placeholder', array('article_name' => $article_name, 'nocreate' => true)); ?>
                <?php endif; ?>
            </div>
        </td>
    </tr>
</table>
