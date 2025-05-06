<?php

// Load media.json
$mediajsonFile = get_template_directory_uri() . '/media.json';
$media_json_data = file_get_contents($mediajsonFile);
$media_data = json_decode($media_json_data, true);

$post_image_data = [];
foreach ($media_data[2]['data'] as $value) {
    if (isset($value['model_type']) && $value['model_type'] === 'App\\Models\\BackendModels\\Post') {
        $media_id = $value['id'];
        $post_id = $value['model_id'];
        $post_ref_name = $value['name'];
        $image_source = "https://sevenstarkhabar.com/storage/{$media_id}/conversions/{$post_ref_name}-thumb_1200_600.jpg";
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
foreach ($categories_data[2]['data'] as $category) {
    $id = (string)$category['id'];
    $slug = $category['key']; // Assuming 'key' is your slug
    $category_slug_lookup[$id] = $slug;
}

// Merge everything into posts
$match_count = 0;
foreach ($posts as &$post) {
    $post_id = (string)$post['id'];

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
        $post['category_id'] = implode(',', $cat_ids);

        $slugs = [];
        foreach ($cat_ids as $cat_id) {
            if (isset($category_slug_lookup[$cat_id])) {
                $slugs[] = $category_slug_lookup[$cat_id];
            }
        }
        $post['category_slug'] = implode(',', $slugs);
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
