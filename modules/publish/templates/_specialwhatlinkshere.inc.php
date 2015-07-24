<div class="article">
    <div class="header">Special:WhatLinksHere &rArr; <?php echo get_spaced_name($linked_article_name); ?></div>
    <p>
        <?php echo __('Below is a listing of pages that link to this page.'); ?>
    </p>
    <?php include_component('publish/articleslist', array('articles' => $articles, 'include_redirects' => false)); ?>
</div>
