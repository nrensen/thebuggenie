<div id="client_<?php echo $client->getID(); ?>">
    <?php echo image_tag('client_large.png', array('style' => 'float: left; margin-right: 5px;')); ?>
    <p class="header">
        <?php echo link_tag(make_url('client_dashboard', array('client_id' => $client->getId())), $client->getName()); ?>
    </p>
    <p class="info">
        <?php echo __('%number_of member(s)', array('%number_of' => '<span id="client_'.$client->getID().'_membercount">'.$client->getNumberOfMembers().'</span>')); ?>,
        <?php echo __('%number_of projects(s)', array('%number_of' => '<span id="client_'.$client->getID().'_projectcount">'.count(\thebuggenie\core\entities\Project::getAllByClientID($client->getID())).'</span>')); ?>
     </p>
</div>
