<div id="team_<?php echo $team->getID(); ?>">
    <?php echo image_tag('team_large.png', array('style' => 'float: left; margin-right: 5px;')); ?>
    <p class="header">
        <?php echo link_tag(make_url('team_dashboard', array('team_id' => $team->getId())), $team->getName()); ?>
    </p>
    <p class="info">
        <?php echo __('%number_of member(s)', array('%number_of' => '<span id="team_'.$team->getID().'_membercount">'.$team->getNumberOfMembers().'</span>')); ?>,
        <?php echo __('%number_of project(s)', array('%number_of' => '<span id="team_'.$team->getID().'_projectcount">'.count($team->getAssociatedProjects()).'</span>')); ?>
    </p>
</div>
