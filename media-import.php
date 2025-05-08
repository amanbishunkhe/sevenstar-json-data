<?php

// Load media.json
$mediajsonFile = get_template_directory_uri() . '/media.json';
$media_json_data = file_get_contents($mediajsonFile);
$media_data = json_decode($media_json_data, true);
$site_url = 'https://sevenstarkhabar.com';
$post_image_data = [];
foreach ($media_data[2]['data'] as $value) {
    if (isset($value['model_type']) && $value['model_type'] === 'App\\Models\\BackendModels\\Post') {
        $media_id = $value['id'];
        $post_id = $value['model_id'];
        $post_ref_name = $value['name'];
        $image_source = "{$site_url}/storage/{$media_id}/conversions/{$post_ref_name}-thumb_1200_600.jpg";
        $post_image_data[] = [
            "post_id" => (string)$post_id,
            "image_source" => $image_source
        ];
    }
}

// Load posts.json
$postJsonFile = get_template_directory_uri() . '/posts.json';
$post_json_data = file_get_contents($postJsonFile);
if ($post_json_data === false) {
    die("Error: Failed to read posts.json");
}
$post_data = json_decode($post_json_data, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON in posts.json - " . json_last_error_msg());
}
$posts = $post_data[2]['data'] ?? [];
if (empty($posts)) {
    die("Error: No posts data found in posts.json");
}

// Load category_post.json
$categoryPostJsonFile = get_template_directory_uri() . '/category_post.json';
$category_post_json_data = file_get_contents($categoryPostJsonFile);
$category_post_data = json_decode($category_post_json_data, true);



$post_to_category = [];
foreach ($category_post_data[2]['data'] as $entry) {
    $post_id = (string)$entry['post_id'];
    $category_id = (string)$entry['category_id'];

    if (!isset($post_to_category[$post_id])) {
        $post_to_category[$post_id] = [];
    }
    $post_to_category[$post_id][] = $category_id;
}

// Load categories.json
$categoriesJsonFile = get_template_directory_uri() . '/categories.json';
$categories_json_data = file_get_contents($categoriesJsonFile);
$categories_data = json_decode($categories_json_data, true);

$category_slug_lookup = [];
$category_title_lookup = [];
foreach ($categories_data[2]['data'] as $category) {
    $id = (string)$category['id'];
    $slug = $category['key']; // Assuming 'key' is slug
    $category_title_lookup[$id] = $category['category_title']; 
    $category_slug_lookup[$id] = $slug;
}


//load tag_post.json
$tagPostJsonFile = get_template_directory_uri() . '/post_tag.json';
$tag_post_json_data = file_get_contents($tagPostJsonFile);
$tag_post_data = json_decode($tag_post_json_data, true);

$post_to_tag = [];
foreach ($tag_post_data[2]['data'] as $key => $tag_entry) {
    $post_id = (string)$tag_entry['post_id'];
    $tag_id = (string)$tag_entry['tag_id'];

    if( !isset( $post_to_tag[$post_id] ) ){
        $post_to_tag[$post_id ] = [];
    }
    $post_to_tag[$post_id][] = $tag_id;
}

//load tags.json
$tagJsonFIle = get_template_directory_uri().'/tags.json';
$tag_json_data = file_get_contents( $tagJsonFIle );
$tags_data = json_decode( $tag_json_data,true );

$tag_slug_lookup = [];
$tag_title_lookup = [];
foreach ($tags_data[2]['data'] as $key => $tag) {
    $id = ( string )$tag['id'];
    $slug = $tag['key'];
    $tag_title = $tag['tag_title'];
    $tag_slug_lookup[$id] = $slug;
    $tag_title_lookup[$id] = $tag_title;
}

// Merge everything into posts
$match_count = 0;
foreach ($posts as &$post) {
    $post_id = (string)$post['id'];

    if(!empty( $post['details'] )){      
        $details = stripslashes($post['details']);
        $details = preg_replace_callback(
            '#<img\s+[^>]*src=[\'"](?:\.\.\/){3}([^\'"]+)[\'"]#',
            function ($matches) use ($site_url) {
                return str_replace('../../../', $site_url . '/', $matches[0]);
            },
            $details
        );

        $post['details'] = $details;
    }

    // Add image if available
    foreach ($post_image_data as $image) {
        if ($image['post_id'] === $post_id) {
            $post['image_source'] = $image['image_source'];
            break;
        }
    }

    // Add categories and slugs
    if (isset($post_to_category[$post_id])) {
        $cat_ids = $post_to_category[$post_id];
        //$post['category_id'] = implode(',', $cat_ids);

        $cat_title = [];
        $slugs = [];
        foreach ($cat_ids as $cat_id) {
            if (isset($category_slug_lookup[$cat_id])) {
                $cat_title[] = $category_title_lookup[$cat_id];
                $slugs[] = $category_slug_lookup[$cat_id];
            }
        }
        $post['category_title'] = implode(',', $cat_title);
        $post['category_slug'] = implode(',', $slugs);
    }

    // Add slug and title

    if( isset( $post_to_tag[$post_id] ) ){
        $tag_ids = $post_to_tag[$post_id];
       // $post['tag_id'] = implode(',', $tag_ids);

        $tag_slugs = [];   
        $tag_title = [];    
        foreach ($tag_ids as $key => $tag_id) {
            if( isset( $tag_slug_lookup[ $tag_id ]  ) ){
                $tag_slugs[] = $tag_slug_lookup[$tag_id];   
                $tag_title[] = $tag_title_lookup[$tag_id];          
            }
        }
        $post['tag_slug'] = implode(',', $tag_slugs);   
        $post['tag_title'] = implode(',', $tag_title);      

    }

    $match_count++;
}
unset($post);

// Save JSON
file_put_contents(
    __DIR__ . '/output.json',
    json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

// Save CSV
$csvFile = fopen(__DIR__ . '/output.csv', 'w');
if ($csvFile === false) {
    die("Error: Failed to open output.csv for writing");
}
if (!empty($posts)) {
    fputcsv($csvFile, array_keys($posts[0]));
    foreach ($posts as $post) {
        fputcsv($csvFile, $post);
    }
}
fclose($csvFile);

echo "JSON and CSV files generated successfully.\n";
echo $match_count;
?>
