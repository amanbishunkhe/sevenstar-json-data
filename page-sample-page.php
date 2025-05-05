<?php
$args = array(
    'posts_per_page' => 1,
    'post_type' => 'post'
);
$query = new WP_Query( $args );

echo '<pre>';
print_r( $query );
echo '</pre>';