<?php

    $tbg_response->addBreadcrumb(__('Clients'), make_url('client_list'), tbg_get_breadcrumblinks('client_list'));

?>

<div class="client_list_header"><?php echo __('Clients'); ?></div>

<div class="client_list">
    <?php
        if (count($clients) > 0)
        {
            foreach($clients as $client)
            {
                if ($client->hasAccess())
                {
                    include_component('client', array('client' => $client));
                }
            }
        }
        else
        {
            echo '<div class="no_items">', __('There are no clients.'), '</div>';
        }
    ?>
</div>
