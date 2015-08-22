<?php

    $tbg_response->addBreadcrumb(__('Teams'), make_url('team_list'), tbg_get_breadcrumblinks('team_list'));

?>

<div class="team_list_header"><?php echo __('Teams'); ?></div>

<div class="team_list">
    <?php
        if (count($teams) > 0)
        {
            foreach($teams as $team)
            {
                if ($team->hasAccess())
                {
                    include_component('team', array('team' => $team));
                }
            }
        }
        else
        {
            echo '<div class="no_items">', __('There are no teams.'), '</div>';
        }
    ?>
</div>
