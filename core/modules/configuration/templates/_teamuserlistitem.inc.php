<li id="team_<?php echo $team->getID(); ?>_<?php echo $user_id; ?>_item">
    <?php include_component('main/userdropdown', array('user' => $user)); ?>
    <button class="button-icon button-silver" onclick="TBG.Main.Helpers.Dialog.show('<?php echo __('Do you really want to remove the user from this team?'); ?>', '<?php echo __('Removing the user from this team will remove all automatically inherited permissions from the user. You can easily add the user back to the team in the future.'); ?>', {yes: {click: function() {TBG.Config.Team.removeMember('<?php echo make_url('configure_users_remove_team_member', array('user_id' => $user_id, 'team_id' => $team->getID())); ?>', <?php echo $team->getID(); ?>, <?php echo $user_id; ?>); }}, no: { click: TBG.Main.Helpers.Dialog.dismiss }});return false;"><?= fa_image_tag('times'); ?></button>
</li>
